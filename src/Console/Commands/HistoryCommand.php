<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\PajApiService;
use PajGpsCalendar\Services\DatabaseService;
use PajGpsCalendar\Services\CalendarService;
use PajGpsCalendar\Services\GeoService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class HistoryCommand extends Command
{
    protected static $defaultName = 'history';
    
    private ConfigService $config;
    private Logger $logger;

    protected function configure(): void
    {
        $this
            ->setDescription('Analysiert historische Fahrzeugdaten und erstellt Kalendereinträge')
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'Anzahl der Tage in der Vergangenheit die analysiert werden sollen',
                7
            )
            ->addOption(
                'vehicle-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Spezifische Fahrzeug-ID die analysiert werden soll'
            )
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
            
            $days = (int) $input->getOption('days');
            $vehicleId = $input->getOption('vehicle-id');
            $dryRun = $input->getOption('dry-run');
            
            $output->writeln('<info>PAJ GPS to Calendar - Historische Datenanalyse</info>');
            $output->writeln(sprintf('Zeitraum: %d Tage zurück', $days));
            
            if ($dryRun) {
                $output->writeln('<comment>Dry-Run Modus aktiviert</comment>');
            }

            // Services initialisieren
            $pajApi = new PajApiService($this->config, $this->logger);
            $database = new DatabaseService($this->config, $this->logger);
            $calendar = new CalendarService($this->config, $this->logger);
            $geo = new GeoService($this->config, $this->logger);

            // Zeitraum definieren
            $timezone = new \DateTimeZone($this->config->get('settings.timezone', 'Europe/Berlin'));
            $endDate = new \DateTime('now', $timezone);
            $startDate = (clone $endDate)->sub(new \DateInterval("P{$days}D"));
            
            $output->writeln(sprintf('Von: %s bis: %s', 
                $startDate->format('d.m.Y H:i'), 
                $endDate->format('d.m.Y H:i')
            ));

            // Fahrzeuge abrufen
            $vehicles = $pajApi->getVehicles();
            
            if ($vehicleId) {
                $vehicles = array_filter($vehicles, function($v) use ($vehicleId) {
                    return $v['id'] === $vehicleId;
                });
                
                if (empty($vehicles)) {
                    $output->writeln('<error>Fahrzeug mit ID ' . $vehicleId . ' nicht gefunden</error>');
                    return Command::FAILURE;
                }
            }

            // Kundenstandorte laden
            $customerLocations = $database->getCustomerLocations();
            $output->writeln(sprintf('Analysiere %d Fahrzeuge gegen %d Kundenstandorte', 
                count($vehicles), count($customerLocations)
            ));

            $totalEntriesCreated = 0;
            $proximityThreshold = $this->config->get('settings.proximity_threshold_meters', 500);

            foreach ($vehicles as $vehicle) {
                $output->writeln(sprintf('Analysiere Fahrzeug: %s', $vehicle['name']));
                
                // Historische Daten abrufen
                $history = $pajApi->getVehicleHistory($vehicle['id'], $startDate, $endDate);
                $output->writeln(sprintf('  %d historische Positionen geladen', count($history)));
                
                $entriesForVehicle = 0;
                $lastLocationId = null;
                $lastEntryTime = null;
                
                foreach ($history as $position) {
                    // Für jede Position prüfen ob sie in der Nähe eines Kundenstandorts ist
                    $nearbyLocations = $geo->findNearbyLocations(
                        $position['latitude'],
                        $position['longitude'],
                        $customerLocations,
                        $proximityThreshold
                    );
                    
                    foreach ($nearbyLocations as $location) {
                        $positionTime = new \DateTime($position['timestamp'], $timezone);
                        
                        // Duplikate vermeiden: Nur einen Eintrag pro Standort und Stunde
                        if ($lastLocationId === $location['id'] && 
                            $lastEntryTime && 
                            $positionTime->getTimestamp() - $lastEntryTime->getTimestamp() < 3600) {
                            continue;
                        }
                        
                        $vehicleWithPosition = array_merge($vehicle, [
                            'latitude' => $position['latitude'],
                            'longitude' => $position['longitude'],
                            'timestamp' => $position['timestamp']
                        ]);
                        
                        if (!$dryRun) {
                            if ($calendar->createEntry($vehicleWithPosition, $location, $location['distance'])) {
                                $entriesForVehicle++;
                                $totalEntriesCreated++;
                                $lastLocationId = $location['id'];
                                $lastEntryTime = $positionTime;
                                
                                $output->writeln(sprintf('    Eintrag erstellt: %s (%.0fm, %s)', 
                                    $location['customer_name'], 
                                    $location['distance'],
                                    $positionTime->format('d.m.Y H:i')
                                ));
                            }
                        } else {
                            $output->writeln(sprintf('    Eintrag würde erstellt: %s (%.0fm, %s)', 
                                $location['customer_name'], 
                                $location['distance'],
                                $positionTime->format('d.m.Y H:i')
                            ));
                        }
                        
                        // Nur der nächste Standort wird berücksichtigt
                        break;
                    }
                }
                
                $output->writeln(sprintf('  %d Einträge für Fahrzeug %s erstellt', 
                    $entriesForVehicle, $vehicle['name']
                ));
            }

            $output->writeln(sprintf('<success>Historische Analyse abgeschlossen! %d neue Kalendereinträge erstellt</success>', 
                $totalEntriesCreated
            ));
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Fehler: %s</error>', $e->getMessage()));
            $this->logger->error('History command execution failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }
    
    private function initializeServices(): void
    {
        $this->config = new ConfigService();
        
        // Logger einrichten
        $this->logger = new Logger('paj-gps-calendar');
        $logFile = $this->config->get('settings.log_file', 'logs/application.log');
        $logLevel = $this->config->get('settings.log_level', 'info');
        
        $this->logger->pushHandler(new StreamHandler($logFile, $logLevel));
    }
}
