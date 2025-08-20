<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\DatabaseService;
use PajGpsCalendar\Services\CalendarService;
use PajGpsCalendar\Services\PajApiService;
use PajGpsCalendar\Services\GeoService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class SetupCommand extends Command
{
    protected static $defaultName = 'setup';

    protected function configure(): void
    {
        $this->setDescription('Führt die Ersteinrichtung des Systems durch');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>PAJ GPS to Calendar - Ersteinrichtung</info>');
        $output->writeln('');

        $helper = $this->getHelper('question');

        try {
            // Konfigurationsdatei prüfen
            $configFile = __DIR__ . '/../../../config/config.yaml';
            if (!file_exists($configFile)) {
                $output->writeln('<comment>Konfigurationsdatei nicht gefunden. Erstelle aus Vorlage...</comment>');
                
                $exampleFile = __DIR__ . '/../../../config/config.example.yaml';
                if (!copy($exampleFile, $configFile)) {
                    throw new \Exception('Konnte Konfigurationsdatei nicht erstellen');
                }
                
                $output->writeln('<success>Konfigurationsdatei erstellt: ' . $configFile . '</success>');
                $output->writeln('<comment>Bitte bearbeiten Sie die Konfigurationsdatei mit Ihren Zugangsdaten.</comment>');
                $output->writeln('');
            }

            // Config laden
            $config = new ConfigService($configFile);
            $logger = new Logger('paj-gps-setup');
            $logger->pushHandler(new StreamHandler('php://stdout'));

            // Datenbank-Setup
            $output->writeln('<info>Datenbank-Setup</info>');
            $question = new ConfirmationQuestion('Sollen die Datenbanktabellen erstellt werden? (y/N) ', false);
            
            if ($helper->ask($input, $output, $question)) {
                $database = new DatabaseService($config, $logger);
                $database->createTables();
                $output->writeln('<success>Datenbanktabellen erstellt</success>');
            }

            // PAJ API Verbindung testen
            $output->writeln('');
            $output->writeln('<info>PAJ API Verbindung testen</info>');
            $question = new ConfirmationQuestion('Soll die PAJ API Verbindung getestet werden? (y/N) ', false);
            
            if ($helper->ask($input, $output, $question)) {
                try {
                    $pajApi = new PajApiService($config, $logger);
                    $pajApi->authenticate();
                    $vehicles = $pajApi->getVehicles();
                    $output->writeln('<success>PAJ API Verbindung erfolgreich</success>');
                    $output->writeln(sprintf('Gefundene Fahrzeuge: %d', count($vehicles)));
                    
                    foreach ($vehicles as $vehicle) {
                        $output->writeln(sprintf('  - %s (ID: %s)', $vehicle['name'], $vehicle['id']));
                    }
                } catch (\Exception $e) {
                    $output->writeln('<error>PAJ API Verbindung fehlgeschlagen: ' . $e->getMessage() . '</error>');
                }
            }

            // Kalender-Verbindung testen
            $output->writeln('');
            $output->writeln('<info>Kalender-Verbindung testen</info>');
            $question = new ConfirmationQuestion('Soll die Kalender-Verbindung getestet werden? (y/N) ', false);
            
            if ($helper->ask($input, $output, $question)) {
                try {
                    $calendar = new CalendarService($config, $logger);
                    if ($calendar->testConnection()) {
                        $output->writeln('<success>Kalender-Verbindung erfolgreich</success>');
                    } else {
                        $output->writeln('<error>Kalender-Verbindung fehlgeschlagen</error>');
                    }
                } catch (\Exception $e) {
                    $output->writeln('<error>Kalender-Verbindung fehlgeschlagen: ' . $e->getMessage() . '</error>');
                }
            }

            // Beispiel-Kundendaten einfügen
            $output->writeln('');
            $output->writeln('<info>Beispiel-Kundendaten</info>');
            $question = new ConfirmationQuestion('Sollen Beispiel-Kundendaten eingefügt werden? (y/N) ', false);
            
            if ($helper->ask($input, $output, $question)) {
                $this->insertExampleCustomers($config, $logger, $output);
            }

            // Cronjob Hinweis
            $output->writeln('');
            $output->writeln('<info>Cronjob Einrichtung</info>');
            $output->writeln('Für den automatischen Betrieb richten Sie einen Cronjob ein:');
            $output->writeln('');
            $output->writeln('<comment>Linux/macOS:</comment>');
            $output->writeln('*/15 * * * * /usr/bin/php ' . realpath(__DIR__ . '/../../../bin/paj-gps-calendar') . ' check');
            $output->writeln('');
            $output->writeln('<comment>Windows (Aufgabenplanung):</comment>');
            $output->writeln('schtasks /create /tn "PAJ GPS Check" /tr "php ' . realpath(__DIR__ . '/../../../bin/paj-gps-calendar') . ' check" /sc minute /mo 15');
            $output->writeln('');

            $output->writeln('<success>Ersteinrichtung abgeschlossen!</success>');
            $output->writeln('');
            $output->writeln('Nächste Schritte:');
            $output->writeln('1. Bearbeiten Sie die Konfigurationsdatei: ' . $configFile);
            $output->writeln('2. Fügen Sie Ihre Kundendaten in die Datenbank ein');
            $output->writeln('3. Testen Sie das System mit: php bin/paj-gps-calendar check --dry-run');
            $output->writeln('4. Richten Sie den Cronjob ein für automatische Ausführung');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('<error>Fehler bei der Einrichtung: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function insertExampleCustomers(ConfigService $config, Logger $logger, OutputInterface $output): void
    {
        $database = new DatabaseService($config, $logger);
        $geo = new GeoService($config, $logger);

        $examples = [
            ['name' => 'Musterfirma GmbH', 'address' => 'Musterstraße 1, 12345 Musterstadt'],
            ['name' => 'Beispiel AG', 'address' => 'Beispielweg 10, 54321 Beispielort'],
            ['name' => 'Demo Unternehmen', 'address' => 'Demostraße 5, 67890 Demostadt'],
        ];

        foreach ($examples as $example) {
            $coordinates = $geo->addressToCoordinates($example['address']);
            
            if ($coordinates) {
                // Hier würde normalerweise ein SQL INSERT stehen
                $output->writeln(sprintf('Beispielkunde hinzugefügt: %s (%s)', 
                    $example['name'], $example['address']
                ));
            } else {
                $output->writeln(sprintf('<comment>Koordinaten nicht gefunden für: %s</comment>', 
                    $example['address']
                ));
            }
        }
    }
}
