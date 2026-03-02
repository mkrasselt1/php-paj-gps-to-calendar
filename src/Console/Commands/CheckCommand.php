<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\PajApiService;
use PajGpsCalendar\Services\CalendarService;
use PajGpsCalendar\Services\CrmService;
use PajGpsCalendar\Services\GeoService;
use PajGpsCalendar\Services\BlindSpotService;
use PajGpsCalendar\Helpers\PathHelper;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class CheckCommand extends Command
{
    protected static $defaultName = 'check';

    private ConfigService $config;
    private Logger $logger;

    protected function configure(): void
    {
        $this
            ->setDescription('Überprüft Fahrzeugbewegungen und erstellt Kalendereinträge beim Losfahren')
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Führt eine Testausführung durch ohne Kalendereinträge zu erstellen'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeServices();

            $output->writeln('<info>PAJ GPS to Calendar - Überprüfung gestartet</info>');

            $dryRun = $input->getOption('dry-run');
            if ($dryRun) {
                $output->writeln('<comment>Dry-Run Modus aktiviert - keine Kalendereinträge werden erstellt</comment>');
            }

            // Services initialisieren
            $pajApi    = new PajApiService($this->config, $this->logger);
            $crm       = new CrmService($this->config, $this->logger, new GeoService($this->config, $this->logger));
            $calendar  = new CalendarService($this->config, $this->logger);
            $blindSpot = new BlindSpotService($this->config);

            $proximityThreshold   = $this->config->get('settings.proximity_threshold_meters', 500);
            $minimumVisitDuration = $this->config->get('settings.minimum_visit_duration_minutes', 2);

            // CRM-System testen
            $output->writeln(sprintf('Verbinde mit CRM-System: %s', $crm->getCrmSystemName()));
            if (!$crm->testCrmConnection()) {
                $output->writeln('<error>CRM-Verbindung fehlgeschlagen</error>');
                return Command::FAILURE;
            }

            // State-Datei laden
            $stateFile = PathHelper::resolveProjectPath('data/vehicle_states.json');
            $stateDir  = dirname($stateFile);
            if (!is_dir($stateDir)) {
                mkdir($stateDir, 0755, true);
            }
            $vehicleStates = file_exists($stateFile)
                ? (json_decode(file_get_contents($stateFile), true) ?: [])
                : [];

            // Fahrzeuge abrufen
            $output->writeln('Lade Fahrzeugdaten...');
            $vehicles = $pajApi->getVehicles();
            $output->writeln(sprintf('Gefunden: %d Fahrzeuge', count($vehicles)));

            $entriesCreated = 0;

            foreach ($vehicles as $vehicle) {
                $vid      = (string)$vehicle['id'];
                $speed    = (float)($vehicle['speed'] ?? 0);
                $isMoving = $speed >= 1.0;

                $output->writeln(sprintf(
                    'Prüfe Fahrzeug: %s  (%.1f km/h, %s)',
                    $vehicle['name'],
                    $speed,
                    $isMoving ? 'fährt' : 'steht'
                ));

                // Blind-Spot-Check für aktuellen Standort
                $blindSpotInfo = $blindSpot->isInBlindSpot($vehicle['latitude'], $vehicle['longitude']);
                if ($blindSpotInfo) {
                    $output->writeln(sprintf(
                        '  🚫 Fahrzeug in blindem Fleck "%s" (%.0fm) - überspringe',
                        $blindSpotInfo['name'],
                        $blindSpotInfo['distance_meters']
                    ));
                    // Auch im Blind-Spot den Stop-Zeitstempel festhalten (falls noch nicht bekannt)
                    if (empty($vehicleStates[$vid]['stopped_since'])) {
                        $vehicleStates[$vid] = [
                            'status'        => 'stopped',
                            'stopped_since' => $vehicle['timestamp'] ?? time(),
                        ];
                    }
                    continue;
                }

                $prevState   = $vehicleStates[$vid] ?? ['status' => 'unknown', 'stopped_since' => null];
                $prevStopped = ($prevState['status'] === 'stopped');

                if (!$isMoving) {
                    // ── Fahrzeug steht ────────────────────────────────────────
                    if (!$prevStopped) {
                        // Frisch gestoppt
                        $stoppedSince = $vehicle['timestamp'] ?? time();
                        $output->writeln(sprintf(
                            '  🛑 Gerade gestoppt – merke Zeitstempel %s',
                            date('H:i:s', $stoppedSince)
                        ));
                        $vehicleStates[$vid] = ['status' => 'stopped', 'stopped_since' => $stoppedSince];
                    } else {
                        $stoppedSince = $prevState['stopped_since'] ?? ($vehicle['timestamp'] ?? time());
                        $waitMinutes  = round((time() - $stoppedSince) / 60);
                        $output->writeln(sprintf('  ⏸  Steht seit %d Minuten – warte auf Losfahren', $waitMinutes));
                    }
                } else {
                    // ── Fahrzeug fährt ────────────────────────────────────────
                    if ($prevStopped && !empty($prevState['stopped_since'])) {
                        // ── Transition: stopped → moving ──────────────────────
                        $stoppedSince = (int)$prevState['stopped_since'];
                        $output->writeln(sprintf(
                            '  🚗 Losgefahren! Stand seit %s – starte History-Analyse',
                            date('H:i:s', $stoppedSince)
                        ));

                        // History-Fenster: stopped_since − 30 min bis jetzt, max. 12 h
                        $historyFrom = $stoppedSince - 1800;
                        $historyTo   = time();
                        if (($historyTo - $historyFrom) > 43200) {
                            $historyFrom = $historyTo - 43200;
                        }

                        try {
                            $visits = $pajApi->analyzeHistoricalVisits(
                                $vid,
                                $historyFrom,
                                $historyTo,
                                $minimumVisitDuration
                            );
                        } catch (\Exception $e) {
                            $output->writeln(sprintf('  ⚠️  History-Abfrage fehlgeschlagen: %s', $e->getMessage()));
                            $visits = [];
                        }

                        if (empty($visits)) {
                            $output->writeln('  -> Keine Besuche in History gefunden');
                        } else {
                            $output->writeln(sprintf('  📋 %d Besuch(e) in History gefunden', count($visits)));
                        }

                        foreach ($visits as $visit) {
                            // Blind-Spot-Check auf Besuchszentroid
                            $visitBlindSpot = $blindSpot->isInBlindSpot($visit['latitude'], $visit['longitude']);
                            if ($visitBlindSpot) {
                                $output->writeln(sprintf(
                                    '    🚫 Besuch in blindem Fleck "%s" - überspringe',
                                    $visitBlindSpot['name']
                                ));
                                continue;
                            }

                            // Kunden in der Nähe des Besuchszentroids suchen
                            $nearbyCustomers = $crm->findCustomersNearLocation(
                                $visit['latitude'],
                                $visit['longitude'],
                                $proximityThreshold
                            );

                            if (empty($nearbyCustomers)) {
                                $output->writeln(sprintf(
                                    '    -> Besuch %s–%s: keine Kunden in der Nähe',
                                    $visit['start_time_formatted'],
                                    $visit['end_time_formatted']
                                ));
                                continue;
                            }

                            // Nächsten Kunden bestimmen
                            $closestCustomer = null;
                            $minDist         = PHP_FLOAT_MAX;
                            foreach ($nearbyCustomers as $candidate) {
                                if ($candidate['distance_meters'] < $minDist) {
                                    $minDist         = $candidate['distance_meters'];
                                    $closestCustomer = $candidate;
                                }
                            }

                            $output->writeln(sprintf(
                                '    🎯 Besuch %s–%s (%d Min) bei %s (%.0fm)',
                                $visit['start_time_formatted'],
                                $visit['end_time_formatted'],
                                round($visit['duration_minutes']),
                                $closestCustomer['customer_name'],
                                $closestCustomer['distance_meters']
                            ));

                            // Fahrzeugdaten im erwarteten Format (mit Besuchszentroid)
                            $vehicleData = [
                                'id'        => $vehicle['id'],
                                'name'      => $vehicle['name'],
                                'latitude'  => $visit['latitude'],
                                'longitude' => $visit['longitude'],
                                'speed'     => 0,
                            ];

                            if (!$dryRun) {
                                // Duplikat-Schutz
                                if ($calendar->hasExistingHistoricalEntry($vehicleData, $closestCustomer, $visit['start_time'])) {
                                    $output->writeln('    ⏭️  Kalendereintrag bereits vorhanden');
                                    continue;
                                }

                                if ($calendar->createHistoricalEntry(
                                    $vehicleData,
                                    $closestCustomer,
                                    $closestCustomer['distance_meters'],
                                    $visit['start_time'],
                                    $visit['end_time'],
                                    false
                                )) {
                                    $entriesCreated++;
                                    $output->writeln('    ✅ Kalendereintrag erstellt');
                                } else {
                                    $output->writeln('    ❌ Fehler beim Erstellen des Kalendereintrags');
                                }
                            } else {
                                $output->writeln('    📅 Kalendereintrag würde erstellt werden (Dry-Run)');
                            }
                        }
                    } else {
                        $output->writeln('  -> Fährt bereits – nichts zu tun');
                    }

                    // Status auf moving setzen
                    $vehicleStates[$vid] = ['status' => 'moving', 'stopped_since' => null];
                }
            }

            // State-Datei speichern
            file_put_contents($stateFile, json_encode($vehicleStates, JSON_PRETTY_PRINT) . "\n");

            $output->writeln(sprintf('<info>Fertig! %d neue Kalendereinträge erstellt</info>', $entriesCreated));
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Fehler: %s</error>', $e->getMessage()));
            $this->logger->error('Command execution failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }

    private function initializeServices(): void
    {
        $this->config = new ConfigService();

        $this->logger = new Logger('paj-gps-calendar');
        $logFile      = $this->config->get('settings.log_file', 'logs/application.log');
        $logFile      = PathHelper::resolveProjectPath($logFile);
        $logLevel     = $this->config->get('settings.log_level', 'info');

        $this->logger->pushHandler(new StreamHandler($logFile, $logLevel));
    }
}
