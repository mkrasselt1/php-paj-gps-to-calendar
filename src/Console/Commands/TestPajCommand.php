<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\PajApiService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class TestPajCommand extends Command
{
    protected static $defaultName = 'test:paj';

    protected function configure(): void
    {
        $this
            ->setDescription('Testet die PAJ Finder API Verbindung')
            ->addOption(
                'show-vehicles',
                's',
                InputOption::VALUE_NONE,
                'Zeigt alle verfügbaren Fahrzeuge an'
            )
            ->addOption(
                'vehicle-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Testet spezifisches Fahrzeug'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln('<info>PAJ Finder API Test</info>');
            $output->writeln('===================');
            $output->writeln('');

            // Config und Logger
            $config = new ConfigService();
            $logger = new Logger('paj-test');
            $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

            // API-Konfiguration prüfen
            $email = $config->get('paj_api.email');
            $password = $config->get('paj_api.password');
            $baseUrl = $config->get('paj_api.base_url');

            if (!$email || !$password) {
                $output->writeln('<error>PAJ API Zugangsdaten nicht konfiguriert!</error>');
                $output->writeln('Bitte konfigurieren Sie paj_api.email und paj_api.password in config/config.yaml');
                return Command::FAILURE;
            }

            $output->writeln("API-URL: {$baseUrl}");
            $output->writeln("E-Mail: {$email}");
            $output->writeln('');

            // PAJ API Service initialisieren
            $pajApi = new PajApiService($config, $logger);

            // 1. Authentifizierung testen
            $output->writeln('<comment>1. Authentifizierung testen...</comment>');
            if ($pajApi->authenticate()) {
                $output->writeln('<info>✓ Authentifizierung erfolgreich</info>');
            } else {
                $output->writeln('<error>✗ Authentifizierung fehlgeschlagen</error>');
                return Command::FAILURE;
            }
            $output->writeln('');

            // 2. Fahrzeuge abrufen
            $output->writeln('<comment>2. Fahrzeuge laden...</comment>');
            $vehicles = $pajApi->getVehicles();
            
            if (empty($vehicles)) {
                $output->writeln('<warning>Keine Fahrzeuge gefunden</warning>');
                return Command::SUCCESS;
            }

            $output->writeln(sprintf('<info>✓ %d Fahrzeuge gefunden</info>', count($vehicles)));
            $output->writeln('');

            // 3. Fahrzeug-Details anzeigen
            if ($input->getOption('show-vehicles')) {
                $output->writeln('<comment>3. Fahrzeug-Details:</comment>');
                foreach ($vehicles as $vehicle) {
                    $output->writeln(sprintf('Fahrzeug: %s (ID: %s)', $vehicle['name'], $vehicle['id']));
                    if (isset($vehicle['imei'])) {
                        $output->writeln(sprintf('  IMEI: %s', $vehicle['imei']));
                    }
                    $output->writeln(sprintf('  Position: %.6f, %.6f', $vehicle['latitude'] ?? 0, $vehicle['longitude'] ?? 0));
                    if (isset($vehicle['timestamp'])) {
                        $output->writeln(sprintf('  Zeitstempel: %s', date('Y-m-d H:i:s', $vehicle['timestamp'])));
                    }
                    if (isset($vehicle['speed'])) {
                        $output->writeln(sprintf('  Geschwindigkeit: %d km/h', $vehicle['speed']));
                    }
                    if (isset($vehicle['battery'])) {
                        $output->writeln(sprintf('  Batterie: %d%%', $vehicle['battery']));
                    }
                    if (isset($vehicle['direction'])) {
                        $output->writeln(sprintf('  Richtung: %d°', $vehicle['direction']));
                    }
                    $output->writeln('');
                }
            }

            // 4. Spezifisches Fahrzeug testen
            $vehicleId = $input->getOption('vehicle-id');
            if ($vehicleId) {
                $output->writeln('<comment>4. Teste spezifisches Fahrzeug...</comment>');
                try {
                    $position = $pajApi->getVehiclePosition($vehicleId);
                    $output->writeln('<info>✓ Fahrzeugposition abgerufen</info>');
                    $output->writeln(sprintf('Position: %.6f, %.6f', $position['lat'] ?? 0, $position['lng'] ?? 0));
                    if (isset($position['dateunix'])) {
                        $output->writeln(sprintf('Zeitstempel: %s', date('Y-m-d H:i:s', $position['dateunix'])));
                    }
                    
                    // Historische Daten testen
                    $output->writeln('<comment>5. Teste historische Daten (letzte 24h)...</comment>');
                    $from = new \DateTime('-24 hours');
                    $to = new \DateTime();
                    $history = $pajApi->getVehicleHistory($vehicleId, $from, $to);
                    $output->writeln(sprintf('<info>✓ %d historische Positionen gefunden</info>', count($history)));
                    
                } catch (\Exception $e) {
                    $output->writeln(sprintf('<error>✗ Fahrzeug-Test fehlgeschlagen: %s</error>', $e->getMessage()));
                }
            }

            $output->writeln('');
            $output->writeln('<success>PAJ API Test abgeschlossen!</success>');
            
            if (!$input->getOption('show-vehicles')) {
                $output->writeln('<comment>Verwenden Sie --show-vehicles um alle Fahrzeuge anzuzeigen</comment>');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Test fehlgeschlagen: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
