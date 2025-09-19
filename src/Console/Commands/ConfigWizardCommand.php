<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Yaml\Yaml;
use PajGpsCalendar\Services\GeoService;
use PajGpsCalendar\Services\ConfigService;
use Doctrine\DBAL\DriverManager;
use Sabre\DAV\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class ConfigWizardCommand extends Command
{
    protected static $defaultName = 'config';

    private array $config = [];
    private QuestionHelper $helper;
    private OutputInterface $output;
    private InputInterface $input;

    protected function configure(): void
    {
        $this->setDescription('Interaktiver Konfigurationsassistent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');
        \assert($this->helper instanceof QuestionHelper);

        $output->writeln('<info>PAJ GPS to Calendar - Konfigurationsassistent</info>');
        $output->writeln('==============================================');
        $output->writeln('');
        $output->writeln('Dieser Assistent hilft Ihnen bei der Konfiguration des Systems.');
        $output->writeln('Alle Einstellungen werden getestet und in config/config.yaml gespeichert.');
        $output->writeln('');

        try {
            // Bestehende Konfiguration laden (falls vorhanden)
            $this->loadExistingConfig();

            // 1. PAJ API Konfiguration
            $this->configurePajApi();

            // 2. CRM-System Konfiguration
            $this->configureCrm();

            // 3. Kalender-Konfiguration
            $this->configureCalendar();

            // 4. System-Einstellungen
            $this->configureSystemSettings();

            // 5. Tracking-Datenbank (optional)
            $this->configureTrackingDatabase();

            // 6. Konfiguration speichern
            $this->saveConfiguration();

            // 7. Finale Tests
            $this->runFinalTests();

            $output->writeln('');
            $output->writeln('<success>Konfiguration erfolgreich abgeschlossen!</success>');
            $output->writeln('');
            $output->writeln('<comment>Nächste Schritte:</comment>');
            $output->writeln('1. Testen Sie das System: php bin/paj-gps-calendar check --dry-run');
            $output->writeln('2. Richten Sie einen Cronjob ein für automatische Ausführung');
            $output->writeln('3. Überwachen Sie die Logs: tail -f logs/application.log');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Konfiguration fehlgeschlagen: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    private function loadExistingConfig(): void
    {
        $configFile = __DIR__ . '/../../../config/config.yaml';
        
        if (file_exists($configFile)) {
            $this->output->writeln('<comment>Bestehende Konfiguration gefunden - Werte werden als Standard verwendet</comment>');
            $this->config = Yaml::parseFile($configFile);
        } else {
            $this->output->writeln('<comment>Neue Konfiguration wird erstellt</comment>');
            $this->config = [];
        }
        $this->output->writeln('');
    }

    private function configurePajApi(): void
    {
        $this->output->writeln('<info>1. PAJ Finder API Konfiguration</info>');
        $this->output->writeln('==============================');
        $this->output->writeln('');

        // Email (PAJ API verwendet E-Mail statt Benutzername)
        $currentEmail = $this->config['paj_api']['email'] ?? '';
        $question = new Question("PAJ Finder E-Mail-Adresse [{$currentEmail}]: ", $currentEmail);
        $email = $this->helper->ask($this->input, $this->output, $question);

        // Password
        $currentPassword = $this->config['paj_api']['password'] ?? '';
        $question = new Question("PAJ Finder Passwort [" . str_repeat('*', strlen($currentPassword)) . "]: ", $currentPassword);
        $question->setHidden(true);
        $password = $this->helper->ask($this->input, $this->output, $question);

        // Base URL
        $currentBaseUrl = $this->config['paj_api']['base_url'] ?? 'https://connect.paj-gps.de';
        $question = new Question("PAJ API Base URL [{$currentBaseUrl}]: ", $currentBaseUrl);
        $baseUrl = $this->helper->ask($this->input, $this->output, $question);

        $this->config['paj_api'] = [
            'email' => $email,
            'password' => $password,
            'base_url' => $baseUrl
        ];

        // Test PAJ API
        $this->output->writeln('');
        $this->output->writeln('<comment>Teste PAJ API Verbindung...</comment>');
        
        if ($this->testPajApi($email, $password, $baseUrl)) {
            $this->output->writeln('<info>✓ PAJ API Verbindung erfolgreich</info>');
        } else {
            $this->output->writeln('<error>✗ PAJ API Verbindung fehlgeschlagen</error>');
            
            $question = new ConfirmationQuestion('Möchten Sie die Eingaben wiederholen? (y/N) ', false);
            if ($this->helper->ask($this->input, $this->output, $question)) {
                $this->configurePajApi();
                return;
            }
        }
        $this->output->writeln('');
    }

    private function configureCrm(): void
    {
        $this->output->writeln('<info>2. CRM-System Konfiguration</info>');
        $this->output->writeln('============================');
        $this->output->writeln('');

        // CRM-Typ auswählen
        $crmTypes = ['mysql' => 'MySQL/MariaDB Standard', 'generic' => 'Generischer SQL-Adapter'];
        $currentType = $this->config['crm']['adapter_type'] ?? 'mysql';
        
        $question = new ChoiceQuestion(
            "CRM-Adapter auswählen [{$currentType}]: ",
            $crmTypes,
            $currentType
        );
        $adapterType = $this->helper->ask($this->input, $this->output, $question);

        // System Name
        $currentSystemName = $this->config['crm']['system_name'] ?? 'Mein CRM';
        $question = new Question("CRM-System Name [{$currentSystemName}]: ", $currentSystemName);
        $systemName = $this->helper->ask($this->input, $this->output, $question);

        // Datenbank-Verbindung
        $this->output->writeln('');
        $this->output->writeln('<comment>Datenbank-Verbindung (nur Lesezugriff erforderlich):</comment>');

        $currentHost = $this->config['crm']['database']['host'] ?? 'localhost';
        $question = new Question("Host [{$currentHost}]: ", $currentHost);
        $host = $this->helper->ask($this->input, $this->output, $question);

        $currentPort = $this->config['crm']['database']['port'] ?? 3306;
        $question = new Question("Port [{$currentPort}]: ", $currentPort);
        $port = (int) $this->helper->ask($this->input, $this->output, $question);

        $currentDatabase = $this->config['crm']['database']['database'] ?? '';
        $question = new Question("Datenbank Name [{$currentDatabase}]: ", $currentDatabase);
        $database = $this->helper->ask($this->input, $this->output, $question);

        $currentUser = $this->config['crm']['database']['username'] ?? '';
        $question = new Question("Benutzername [{$currentUser}]: ", $currentUser);
        $dbUsername = $this->helper->ask($this->input, $this->output, $question);

        $currentPassword = $this->config['crm']['database']['password'] ?? '';
        $question = new Question("Passwort [" . str_repeat('*', strlen($currentPassword)) . "]: ", $currentPassword);
        $question->setHidden(true);
        $dbPassword = $this->helper->ask($this->input, $this->output, $question);

        // CRM-Konfiguration speichern
        $this->config['crm'] = [
            'adapter_type' => $adapterType,
            'system_name' => $systemName,
            'database' => [
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $dbUsername,
                'password' => $dbPassword
            ]
        ];

        // Standard-Queries setzen falls nicht vorhanden
        if (!isset($this->config['crm']['queries'])) {
            $this->config['crm']['queries'] = $this->getDefaultCrmQueries();
        }

        // Test CRM-Verbindung
        $this->output->writeln('');
        $this->output->writeln('<comment>Teste CRM-Datenbankverbindung...</comment>');
        
        if ($this->testCrmConnection($host, $port, $database, $dbUsername, $dbPassword)) {
            $this->output->writeln('<info>✓ CRM-Datenbankverbindung erfolgreich</info>');
            
            // Prüfe Datenqualität
            $this->checkCrmDataQuality();
        } else {
            $this->output->writeln('<error>✗ CRM-Datenbankverbindung fehlgeschlagen</error>');
            
            $question = new ConfirmationQuestion('Möchten Sie die Eingaben wiederholen? (y/N) ', false);
            if ($this->helper->ask($this->input, $this->output, $question)) {
                $this->configureCrm();
                return;
            }
        }
        $this->output->writeln('');
    }

    private function configureCalendar(): void
    {
        $this->output->writeln('<info>3. Kalender-Konfiguration</info>');
        $this->output->writeln('==========================');
        $this->output->writeln('');

        // Kalender-Typ (derzeit nur CalDAV)
        $calendarType = 'caldav';
        $this->output->writeln("Kalender-Typ: {$calendarType}");

        // CalDAV URL
        $currentUrl = $this->config['calendar']['caldav_url'] ?? '';
        $this->output->writeln('');
        $this->output->writeln('<comment>Beispiel CalDAV URLs:</comment>');
        $this->output->writeln('- Google Calendar: https://apidata.googleusercontent.com/caldav/v2/CALENDAR_ID/events/');
        $this->output->writeln('- Nextcloud: https://cloud.example.com/remote.php/dav/calendars/USERNAME/CALENDAR/');
        $this->output->writeln('- Outlook: https://outlook.office365.com/calendar/');
        $this->output->writeln('');
        
        $question = new Question("CalDAV URL [{$currentUrl}]: ", $currentUrl);
        $caldavUrl = $this->helper->ask($this->input, $this->output, $question);

        // Username
        $currentUsername = $this->config['calendar']['username'] ?? '';
        $question = new Question("Kalender Benutzername [{$currentUsername}]: ", $currentUsername);
        $calendarUsername = $this->helper->ask($this->input, $this->output, $question);

        // Password
        $currentPassword = $this->config['calendar']['password'] ?? '';
        $this->output->writeln('');
        $this->output->writeln('<comment>Hinweis: Verwenden Sie App-spezifische Passwörter!</comment>');
        $question = new Question("Kalender Passwort [" . str_repeat('*', strlen($currentPassword)) . "]: ", $currentPassword);
        $question->setHidden(true);
        $calendarPassword = $this->helper->ask($this->input, $this->output, $question);

        $this->config['calendar'] = [
            'type' => $calendarType,
            'caldav_url' => $caldavUrl,
            'username' => $calendarUsername,
            'password' => $calendarPassword
        ];

        // Test Kalender-Verbindung
        $this->output->writeln('');
        $this->output->writeln('<comment>Teste Kalender-Verbindung...</comment>');
        
        if ($this->testCalendarConnection($caldavUrl, $calendarUsername, $calendarPassword)) {
            $this->output->writeln('<info>✓ Kalender-Verbindung erfolgreich</info>');
        } else {
            $this->output->writeln('<error>✗ Kalender-Verbindung fehlgeschlagen</error>');
            
            $question = new ConfirmationQuestion('Möchten Sie die Eingaben wiederholen? (y/N) ', false);
            if ($this->helper->ask($this->input, $this->output, $question)) {
                $this->configureCalendar();
                return;
            }
        }
        $this->output->writeln('');
    }

    private function configureSystemSettings(): void
    {
        $this->output->writeln('<info>4. System-Einstellungen</info>');
        $this->output->writeln('=======================');
        $this->output->writeln('');

        // Proximity Threshold
        $currentThreshold = $this->config['settings']['proximity_threshold_meters'] ?? 500;
        $question = new Question("Entfernungsschwelle in Metern [{$currentThreshold}]: ", $currentThreshold);
        $proximityThreshold = (int) $this->helper->ask($this->input, $this->output, $question);

        // Duplicate Check Hours
        $currentHours = $this->config['settings']['duplicate_check_hours'] ?? 24;
        $question = new Question("Duplikatsprüfung in Stunden [{$currentHours}]: ", $currentHours);
        $duplicateHours = (int) $this->helper->ask($this->input, $this->output, $question);

        // Log Level
        $logLevels = ['debug', 'info', 'warning', 'error'];
        $currentLogLevel = $this->config['settings']['log_level'] ?? 'info';
        $question = new ChoiceQuestion("Log-Level [{$currentLogLevel}]: ", $logLevels, $currentLogLevel);
        $logLevel = $this->helper->ask($this->input, $this->output, $question);

        // Timezone
        $currentTimezone = $this->config['settings']['timezone'] ?? 'Europe/Berlin';
        $question = new Question("Zeitzone [{$currentTimezone}]: ", $currentTimezone);
        $timezone = $this->helper->ask($this->input, $this->output, $question);

        $this->config['settings'] = [
            'proximity_threshold_meters' => $proximityThreshold,
            'duplicate_check_hours' => $duplicateHours,
            'log_level' => $logLevel,
            'log_file' => 'logs/application.log',
            'timezone' => $timezone
        ];

        $this->output->writeln('');
    }

    private function configureTrackingDatabase(): void
    {
        $this->output->writeln('<info>5. Tracking-Datenbank (Optional)</info>');
        $this->output->writeln('===================================');
        $this->output->writeln('');
        $this->output->writeln('Eine separate Tracking-Datenbank ermöglicht detaillierte Berichte');
        $this->output->writeln('und Besuchshistorie. Dies ist optional.');
        $this->output->writeln('');

        $hasTracking = isset($this->config['tracking_database']);
        $question = new ConfirmationQuestion('Tracking-Datenbank konfigurieren? (y/N) ', $hasTracking);
        
        if ($this->helper->ask($this->input, $this->output, $question)) {
            // Tracking-DB Konfiguration
            $currentHost = $this->config['tracking_database']['host'] ?? 'localhost';
            $question = new Question("Tracking-DB Host [{$currentHost}]: ", $currentHost);
            $trackingHost = $this->helper->ask($this->input, $this->output, $question);

            $currentPort = $this->config['tracking_database']['port'] ?? 3306;
            $question = new Question("Tracking-DB Port [{$currentPort}]: ", $currentPort);
            $trackingPort = (int) $this->helper->ask($this->input, $this->output, $question);

            $currentDb = $this->config['tracking_database']['database'] ?? 'paj_tracking';
            $question = new Question("Tracking-DB Name [{$currentDb}]: ", $currentDb);
            $trackingDatabase = $this->helper->ask($this->input, $this->output, $question);

            $currentUser = $this->config['tracking_database']['username'] ?? '';
            $question = new Question("Tracking-DB Benutzername [{$currentUser}]: ", $currentUser);
            $trackingUsername = $this->helper->ask($this->input, $this->output, $question);

            $currentPassword = $this->config['tracking_database']['password'] ?? '';
            $question = new Question("Tracking-DB Passwort [" . str_repeat('*', strlen($currentPassword)) . "]: ", $currentPassword);
            $question->setHidden(true);
            $trackingPassword = $this->helper->ask($this->input, $this->output, $question);

            $this->config['tracking_database'] = [
                'host' => $trackingHost,
                'port' => $trackingPort,
                'database' => $trackingDatabase,
                'username' => $trackingUsername,
                'password' => $trackingPassword
            ];

            // Test Tracking-DB
            $this->output->writeln('');
            $this->output->writeln('<comment>Teste Tracking-Datenbankverbindung...</comment>');
            
            if ($this->testTrackingDatabase($trackingHost, $trackingPort, $trackingDatabase, $trackingUsername, $trackingPassword)) {
                $this->output->writeln('<info>✓ Tracking-Datenbankverbindung erfolgreich</info>');
            } else {
                $this->output->writeln('<error>✗ Tracking-Datenbankverbindung fehlgeschlagen</error>');
            }
        } else {
            // Tracking-DB entfernen falls vorhanden
            unset($this->config['tracking_database']);
            $this->output->writeln('<comment>Tracking-Datenbank wird nicht verwendet</comment>');
        }

        $this->output->writeln('');
    }

    private function saveConfiguration(): void
    {
        $this->output->writeln('<info>6. Konfiguration speichern</info>');
        $this->output->writeln('===========================');
        $this->output->writeln('');

        $configFile = __DIR__ . '/../../../config/config.yaml';
        $configDir = dirname($configFile);

        // Verzeichnis erstellen falls nicht vorhanden
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        // YAML speichern
        $yamlContent = Yaml::dump($this->config, 4, 2);
        file_put_contents($configFile, $yamlContent);

        $this->output->writeln('<info>✓ Konfiguration gespeichert: ' . $configFile . '</info>');
        $this->output->writeln('');
    }

    private function runFinalTests(): void
    {
        $this->output->writeln('<info>7. Finale Systemtests</info>');
        $this->output->writeln('======================');
        $this->output->writeln('');

        try {
            // Config Service testen
            $config = new ConfigService();
            $this->output->writeln('<info>✓ Konfiguration erfolgreich geladen</info>');

            // Logger testen
            $logger = new Logger('config-test');
            $projectRoot = realpath(__DIR__ . '/../../../');
            if (!$projectRoot) {
                $projectRoot = dirname(dirname(dirname(__DIR__)));
            }
            $logger->pushHandler(new StreamHandler($projectRoot . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'config-test.log'));
            $this->output->writeln('<info>✓ Logging erfolgreich initialisiert</info>');

            // Geo Service testen
            $geo = new GeoService($config, $logger);
            $distance = $geo->calculateDistance(52.5200, 13.4050, 52.5198, 13.4045);
            $this->output->writeln(sprintf('<info>✓ Geo-Service funktioniert (Test-Distanz: %.1fm)</info>', $distance));

        } catch (\Exception $e) {
            $this->output->writeln(sprintf('<error>✗ Systemtest fehlgeschlagen: %s</error>', $e->getMessage()));
        }
    }

    // Helper-Methoden für Tests

    private function testPajApi(string $email, string $password, string $baseUrl): bool
    {
        try {
            // Einfacher HTTP-Test mit korrekten PAJ API Parametern
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => http_build_query([
                        'email' => $email,
                        'password' => $password
                    ]),
                    'timeout' => 10
                ]
            ]);

            $response = @file_get_contents($baseUrl . '/api/v1/login', false, $context);
            
            if ($response !== false) {
                $data = json_decode($response, true);
                // Prüfe auf PAJ API Success-Format
                return isset($data['success']['token']);
            }
            
            return false;

        } catch (\Exception $e) {
            return false;
        }
    }

    private function testCrmConnection(string $host, int $port, string $database, string $username, string $password): bool
    {
        try {
            $connectionParams = [
                'dbname' => $database,
                'user' => $username,
                'password' => $password,
                'host' => $host,
                'port' => $port,
                'driver' => 'pdo_mysql',
            ];

            $connection = DriverManager::getConnection($connectionParams);
            $connection->connect();
            
            $result = $connection->executeQuery('SELECT 1');
            return $result->fetchOne() === 1;

        } catch (\Exception $e) {
            return false;
        }
    }

    private function testCalendarConnection(string $url, string $username, string $password): bool
    {
        try {
            $settings = [
                'baseUri' => $url,
                'userName' => $username,
                'password' => $password,
            ];

            $client = new Client($settings);
            $response = $client->request('OPTIONS', '');
            
            return $response['statusCode'] < 400;

        } catch (\Exception $e) {
            return false;
        }
    }

    private function testTrackingDatabase(string $host, int $port, string $database, string $username, string $password): bool
    {
        return $this->testCrmConnection($host, $port, $database, $username, $password);
    }

    private function checkCrmDataQuality(): void
    {
        try {
            // Einfache Datenqualitätsprüfung
            $connectionParams = [
                'dbname' => $this->config['crm']['database']['database'],
                'user' => $this->config['crm']['database']['username'],
                'password' => $this->config['crm']['database']['password'],
                'host' => $this->config['crm']['database']['host'],
                'port' => $this->config['crm']['database']['port'],
                'driver' => 'pdo_mysql',
            ];

            $connection = DriverManager::getConnection($connectionParams);
            
            // Test-Query um zu sehen ob Daten vorhanden sind
            $testQuery = "SELECT COUNT(*) as count FROM customer_locations WHERE latitude IS NOT NULL AND longitude IS NOT NULL LIMIT 1";
            
            try {
                $result = $connection->executeQuery($testQuery);
                $count = $result->fetchOne();
                
                if ($count > 0) {
                    $this->output->writeln(sprintf('<info>✓ %d Kunden mit GPS-Koordinaten gefunden</info>', $count));
                } else {
                    $this->output->writeln('<warning>⚠ Keine Kunden mit GPS-Koordinaten gefunden</warning>');
                    $this->output->writeln('<comment>Sie müssen möglicherweise GPS-Koordinaten zu Ihren Kundendaten hinzufügen</comment>');
                }
            } catch (\Exception $e) {
                $this->output->writeln('<warning>⚠ Standard-Tabelle nicht gefunden - bitte SQL-Queries anpassen</warning>');
            }

        } catch (\Exception $e) {
            // Ignore - bereits getestet
        }
    }

    private function getDefaultCrmQueries(): array
    {
        return [
            'find_customers_in_radius' => "
                SELECT 
                    id as customer_id,
                    customer_name,
                    address as full_address,
                    street,
                    city,
                    postal_code,
                    country,
                    latitude,
                    longitude,
                    phone,
                    email,
                    contact_person,
                    notes
                FROM customer_locations 
                WHERE latitude BETWEEN :min_lat AND :max_lat
                AND longitude BETWEEN :min_lng AND :max_lng
                AND latitude IS NOT NULL 
                AND longitude IS NOT NULL
                AND (is_active IS NULL OR is_active = 1)
            ",
            'get_customer_by_id' => "
                SELECT 
                    id as customer_id,
                    customer_name,
                    address as full_address,
                    street,
                    city,
                    postal_code,
                    country,
                    latitude,
                    longitude,
                    phone,
                    email,
                    contact_person,
                    notes
                FROM customer_locations 
                WHERE id = :customer_id
            ",
            'find_customers_by_name' => "
                SELECT 
                    id as customer_id,
                    customer_name,
                    address as full_address,
                    street,
                    city,
                    postal_code,
                    country,
                    latitude,
                    longitude,
                    phone,
                    email,
                    contact_person,
                    notes
                FROM customer_locations 
                WHERE customer_name LIKE :customer_name
                AND latitude IS NOT NULL 
                AND longitude IS NOT NULL
                AND (is_active IS NULL OR is_active = 1)
                ORDER BY customer_name
            "
        ];
    }
}
