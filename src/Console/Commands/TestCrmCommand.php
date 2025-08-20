<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\CrmService;
use PajGpsCalendar\Services\GeoService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class TestCrmCommand extends Command
{
    protected static $defaultName = 'test:crm';

    protected function configure(): void
    {
        $this
            ->setDescription('Testet die CRM-Datenbankverbindung und Abfragen')
            ->addOption(
                'show-customers',
                's',
                InputOption::VALUE_NONE,
                'Zeigt Beispiel-Kunden an'
            )
            ->addOption(
                'test-location',
                'l',
                InputOption::VALUE_REQUIRED,
                'Testet Radius-Suche um GPS-Position (Format: lat,lng)'
            )
            ->addOption(
                'radius',
                'r',
                InputOption::VALUE_REQUIRED,
                'Suchradius in Metern (Standard: 500)',
                500
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln('<info>CRM-System Test</info>');
            $output->writeln('===============');
            $output->writeln('');

            // Config und Logger
            $config = new ConfigService();
            $logger = new Logger('crm-test');
            $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
            $geo = new GeoService($config, $logger);

            // CRM Service initialisieren
            $crm = new CrmService($config, $logger, $geo);

            $output->writeln(sprintf('CRM-System: %s', $crm->getCrmSystemName()));
            $output->writeln('');

            // 1. Datenbankverbindung testen
            $output->writeln('<comment>1. Datenbankverbindung testen...</comment>');
            if ($crm->testCrmConnection()) {
                $output->writeln('<info>✓ CRM-Datenbankverbindung erfolgreich</info>');
            } else {
                $output->writeln('<error>✗ CRM-Datenbankverbindung fehlgeschlagen</error>');
                return Command::FAILURE;
            }
            $output->writeln('');

            // 2. Beispiel-Kunden anzeigen
            if ($input->getOption('show-customers')) {
                $output->writeln('<comment>2. Beispiel-Kunden laden...</comment>');
                
                // Test-Position: Berlin Mitte
                $testLat = 52.5200;
                $testLng = 13.4050;
                $testRadius = 50000; // 50km für mehr Ergebnisse
                
                $customers = $crm->findCustomersNearLocation($testLat, $testLng, $testRadius);
                
                if (empty($customers)) {
                    $output->writeln('<warning>Keine Kunden in der Test-Region gefunden</warning>');
                    $output->writeln('Möglicherweise fehlen GPS-Koordinaten in Ihrer CRM-Datenbank');
                } else {
                    $output->writeln(sprintf('<info>✓ %d Kunden gefunden</info>', count($customers)));
                    $output->writeln('');
                    
                    // Erste 5 Kunden anzeigen
                    $displayCount = min(5, count($customers));
                    for ($i = 0; $i < $displayCount; $i++) {
                        $customer = $customers[$i];
                        $output->writeln(sprintf('Kunde %d: %s', $i + 1, $customer['customer_name']));
                        if (!empty($customer['customer_id'])) {
                            $output->writeln(sprintf('  ID: %s', $customer['customer_id']));
                        }
                        $output->writeln(sprintf('  Adresse: %s', $customer['full_address'] ?? 'Nicht verfügbar'));
                        $output->writeln(sprintf('  GPS: %.6f, %.6f', $customer['latitude'], $customer['longitude']));
                        if (!empty($customer['phone'])) {
                            $output->writeln(sprintf('  Telefon: %s', $customer['phone']));
                        }
                        if (!empty($customer['contact_person'])) {
                            $output->writeln(sprintf('  Kontakt: %s', $customer['contact_person']));
                        }
                        if (isset($customer['distance_meters'])) {
                            $output->writeln(sprintf('  Entfernung: %.0f Meter', $customer['distance_meters']));
                        }
                        $output->writeln('');
                    }
                    
                    if (count($customers) > 5) {
                        $output->writeln(sprintf('<comment>... und %d weitere Kunden</comment>', count($customers) - 5));
                        $output->writeln('');
                    }
                }
            }

            // 3. Spezifische GPS-Position testen
            $testLocation = $input->getOption('test-location');
            if ($testLocation) {
                $output->writeln('<comment>3. Teste Radius-Suche um spezifische Position...</comment>');
                
                $coords = explode(',', $testLocation);
                if (count($coords) !== 2) {
                    $output->writeln('<error>Ungültiges Format! Verwenden Sie: lat,lng (z.B. 52.5200,13.4050)</error>');
                    return Command::FAILURE;
                }
                
                $lat = (float) trim($coords[0]);
                $lng = (float) trim($coords[1]);
                $radius = (int) $input->getOption('radius');
                
                $output->writeln(sprintf('Position: %.6f, %.6f', $lat, $lng));
                $output->writeln(sprintf('Radius: %d Meter', $radius));
                $output->writeln('');
                
                $customers = $crm->findCustomersNearLocation($lat, $lng, $radius);
                
                if (empty($customers)) {
                    $output->writeln('<warning>Keine Kunden in diesem Radius gefunden</warning>');
                } else {
                    $output->writeln(sprintf('<info>✓ %d Kunden im Radius gefunden</info>', count($customers)));
                    $output->writeln('');
                    
                    foreach ($customers as $customer) {
                        $output->writeln(sprintf('%s (%.0fm)', 
                            $customer['customer_name'], 
                            $customer['distance_meters']
                        ));
                    }
                }
                $output->writeln('');
            }

            // 4. Datenqualität prüfen
            $output->writeln('<comment>4. Datenqualität prüfen...</comment>');
            
            // Teste mit einem großen Radius um zu sehen was in der DB ist
            $allCustomers = $crm->findCustomersNearLocation(51.1657, 10.4515, 1000000); // Deutschland Zentrum, 1000km
            
            if (empty($allCustomers)) {
                $output->writeln('<warning>Keine Kunden mit GPS-Koordinaten gefunden!</warning>');
                $output->writeln('');
                $output->writeln('<comment>Mögliche Ursachen:</comment>');
                $output->writeln('- GPS-Koordinaten fehlen in der CRM-Datenbank');
                $output->writeln('- SQL-Query in der Konfiguration ist falsch');
                $output->writeln('- Feldmapping stimmt nicht mit der Datenbankstruktur überein');
                $output->writeln('');
                $output->writeln('<comment>Prüfen Sie:</comment>');
                $output->writeln('1. config/config.yaml - CRM-Konfiguration');
                $output->writeln('2. Datenbankfelder für latitude/longitude');
                $output->writeln('3. Führen Sie "php bin/paj-gps-calendar config" aus');
            } else {
                $output->writeln(sprintf('<info>✓ %d Kunden mit GPS-Koordinaten in der Datenbank</info>', count($allCustomers)));
                
                // Statistiken
                $withPhone = count(array_filter($allCustomers, fn($c) => !empty($c['phone'])));
                $withEmail = count(array_filter($allCustomers, fn($c) => !empty($c['email'])));
                $withContact = count(array_filter($allCustomers, fn($c) => !empty($c['contact_person'])));
                
                $output->writeln(sprintf('- Mit Telefon: %d (%.1f%%)', $withPhone, ($withPhone / count($allCustomers)) * 100));
                $output->writeln(sprintf('- Mit E-Mail: %d (%.1f%%)', $withEmail, ($withEmail / count($allCustomers)) * 100));
                $output->writeln(sprintf('- Mit Kontaktperson: %d (%.1f%%)', $withContact, ($withContact / count($allCustomers)) * 100));
            }

            $output->writeln('');
            $output->writeln('<success>CRM-Test abgeschlossen!</success>');
            
            if (!$input->getOption('show-customers')) {
                $output->writeln('<comment>Verwenden Sie --show-customers um Beispiel-Kunden anzuzeigen</comment>');
            }
            if (!$input->getOption('test-location')) {
                $output->writeln('<comment>Verwenden Sie --test-location=lat,lng um eine spezifische Position zu testen</comment>');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Test fehlgeschlagen: %s</error>', $e->getMessage()));
            $output->writeln('');
            $output->writeln('<comment>Mögliche Lösungen:</comment>');
            $output->writeln('- Prüfen Sie die CRM-Datenbankverbindung in config/config.yaml');
            $output->writeln('- Führen Sie "php bin/paj-gps-calendar config" aus');
            $output->writeln('- Aktivieren Sie Debug-Logging: log_level: "debug"');
            return Command::FAILURE;
        }
    }
}
