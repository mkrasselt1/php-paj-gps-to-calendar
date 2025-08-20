<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\CalendarService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class TestCalendarCommand extends Command
{
    protected static $defaultName = 'test:calendar';

    protected function configure(): void
    {
        $this
            ->setDescription('Testet die Kalender-Verbindung (CalDAV)')
            ->addOption(
                'create-test-event',
                't',
                \Symfony\Component\Console\Input\InputOption::VALUE_NONE,
                'Erstellt einen Test-Kalendereintrag'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln('<info>Kalender-Verbindung Test</info>');
            $output->writeln('========================');
            $output->writeln('');

            // Config und Logger
            $config = new ConfigService();
            $logger = new Logger('calendar-test');
            $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

            // Kalender-Konfiguration prüfen
            $calendarType = $config->get('calendar.type');
            $caldavUrl = $config->get('calendar.caldav_url');
            $username = $config->get('calendar.username');
            $password = $config->get('calendar.password');

            $output->writeln("Kalender-Typ: {$calendarType}");
            
            if ($calendarType === 'caldav') {
                $output->writeln("CalDAV-URL: {$caldavUrl}");
                $output->writeln("Benutzername: {$username}");
                
                if (!$caldavUrl || !$username || !$password) {
                    $output->writeln('<error>CalDAV-Zugangsdaten nicht vollständig konfiguriert!</error>');
                    $output->writeln('Bitte konfigurieren Sie calendar.caldav_url, calendar.username und calendar.password');
                    return Command::FAILURE;
                }
            } else {
                $output->writeln('<error>Nur CalDAV wird derzeit unterstützt!</error>');
                return Command::FAILURE;
            }
            
            $output->writeln('');

            // 1. Calendar Service initialisieren
            $output->writeln('<comment>1. Kalender-Service initialisieren...</comment>');
            $calendar = new CalendarService($config, $logger);
            $output->writeln('<info>✓ Service initialisiert</info>');
            $output->writeln('');

            // 2. Verbindung testen
            $output->writeln('<comment>2. CalDAV-Verbindung testen...</comment>');
            if ($calendar->testConnection()) {
                $output->writeln('<info>✓ CalDAV-Verbindung erfolgreich</info>');
            } else {
                $output->writeln('<error>✗ CalDAV-Verbindung fehlgeschlagen</error>');
                $output->writeln('');
                $output->writeln('<comment>Mögliche Ursachen:</comment>');
                $output->writeln('- Falsche CalDAV-URL');
                $output->writeln('- Ungültige Zugangsdaten');
                $output->writeln('- Kalender existiert nicht');
                $output->writeln('- Netzwerkproblem');
                return Command::FAILURE;
            }
            $output->writeln('');

            // 3. Test-Event erstellen (optional)
            if ($input->getOption('create-test-event')) {
                $output->writeln('<comment>3. Test-Kalendereintrag erstellen...</comment>');
                
                // Fake Fahrzeug- und Kundendaten für Test
                $testVehicle = [
                    'id' => 'TEST-VEHICLE-001',
                    'name' => 'Test Fahrzeug',
                    'latitude' => 52.5200,
                    'longitude' => 13.4050,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'speed' => 0
                ];
                
                $testCustomer = [
                    'customer_id' => 'TEST-CUSTOMER-001',
                    'customer_name' => 'Test Kunde GmbH',
                    'full_address' => 'Teststraße 1, 12345 Teststadt',
                    'latitude' => 52.5198,
                    'longitude' => 13.4045,
                    'phone' => '+49 30 12345678',
                    'email' => 'test@example.com',
                    'contact_person' => 'Max Tester',
                    'notes' => 'Dies ist ein automatischer Test-Eintrag'
                ];
                
                $distance = 50.5; // 50.5 Meter
                
                if ($calendar->createEntry($testVehicle, $testCustomer, $distance)) {
                    $output->writeln('<info>✓ Test-Kalendereintrag erfolgreich erstellt</info>');
                    $output->writeln('');
                    $output->writeln('<comment>Prüfen Sie Ihren Kalender:</comment>');
                    $output->writeln('Titel: "Fahrzeug Test Fahrzeug bei Test Kunde GmbH"');
                    $output->writeln('Zeitpunkt: ' . date('d.m.Y H:i'));
                    $output->writeln('');
                    $output->writeln('<warning>Hinweis: Dies war ein Test-Eintrag. Sie können ihn aus dem Kalender löschen.</warning>');
                } else {
                    $output->writeln('<error>✗ Test-Kalendereintrag konnte nicht erstellt werden</error>');
                    return Command::FAILURE;
                }
            }

            $output->writeln('<success>Kalender-Test abgeschlossen!</success>');
            
            if (!$input->getOption('create-test-event')) {
                $output->writeln('<comment>Verwenden Sie --create-test-event um einen Test-Eintrag zu erstellen</comment>');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Test fehlgeschlagen: %s</error>', $e->getMessage()));
            $output->writeln('');
            $output->writeln('<comment>Häufige CalDAV-Probleme:</comment>');
            $output->writeln('- URL muss auf den spezifischen Kalender zeigen, nicht nur auf den Server');
            $output->writeln('- Bei Google Calendar: App-spezifisches Passwort verwenden');
            $output->writeln('- Bei Nextcloud: Richtige Kalender-URL verwenden');
            $output->writeln('- Firewall/Proxy-Einstellungen prüfen');
            return Command::FAILURE;
        }
    }
}
