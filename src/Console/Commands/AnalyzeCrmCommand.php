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

class AnalyzeCrmCommand extends Command
{
    protected static $defaultName = 'analyze:crm';

    protected function configure(): void
    {
        $this
            ->setDescription('Analysiert die CRM-Datenbankstruktur und schlägt Konfiguration vor')
            ->addOption(
                'show-tables',
                't',
                InputOption::VALUE_NONE,
                'Zeigt alle verfügbaren Tabellen an'
            )
            ->addOption(
                'analyze-table',
                null,
                InputOption::VALUE_REQUIRED,
                'Analysiert eine spezifische Tabelle'
            )
            ->addOption(
                'suggest-config',
                's',
                InputOption::VALUE_NONE,
                'Schlägt eine passende Konfiguration vor'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln('<info>CRM-Datenbank Analyse</info>');
            $output->writeln('======================');
            $output->writeln('');

            // Services initialisieren
            $config = new ConfigService();
            $logger = new Logger('crm-analyze');
            $logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
            
            $geoService = new GeoService($config, $logger);
            $crm = new CrmService($config, $logger, $geoService);

            // Datenbankverbindung über CRM-Service testen
            $output->writeln('1. Datenbankverbindung testen...');
            if (!$crm->testCrmConnection()) {
                $output->writeln('<error>✗ CRM-Verbindung fehlgeschlagen</error>');
                return Command::FAILURE;
            }
            $output->writeln('<info>✓ Verbindung erfolgreich</info>');
            $output->writeln(sprintf('   CRM-System: %s', $crm->getCrmSystemName()));
            $output->writeln(sprintf('   Datenbank: %s', $config->get('crm.database.database')));
            $output->writeln(sprintf('   Host: %s:%s', 
                $config->get('crm.database.host'), 
                $config->get('crm.database.port')
            ));
            $output->writeln('');

            // Hole die Datenbankverbindung vom CRM-Adapter
            $connection = $this->getCrmConnection($crm);

            // Alle Tabellen anzeigen
            if ($input->getOption('show-tables') || !$input->getOption('analyze-table')) {
                $this->showAllTables($connection, $output);
            }

            // Spezifische Tabelle analysieren
            if ($input->getOption('analyze-table')) {
                $this->analyzeTable($connection, $input->getOption('analyze-table'), $output);
            }

            // Konfiguration vorschlagen
            if ($input->getOption('suggest-config')) {
                $this->suggestConfiguration($connection, $output);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('<error>Fehler: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function showAllTables($connection, OutputInterface $output): void
    {
        $output->writeln('2. Verfügbare Tabellen:');
        $output->writeln('------------------------');

        try {
            $stmt = $connection->prepare("SHOW TABLES");
            $result = $stmt->executeQuery();
            $tables = $result->fetchAllAssociative();

            if (empty($tables)) {
                $output->writeln('<comment>Keine Tabellen gefunden</comment>');
                return;
            }

            $customerTables = [];
            $otherTables = [];

            foreach ($tables as $table) {
                $tableName = array_values($table)[0];
                
                // Suche nach Kunden-relevanten Tabellen
                if (stripos($tableName, 'customer') !== false || 
                    stripos($tableName, 'kunde') !== false ||
                    stripos($tableName, 'client') !== false ||
                    stripos($tableName, 'contact') !== false ||
                    stripos($tableName, 'address') !== false) {
                    $customerTables[] = $tableName;
                } else {
                    $otherTables[] = $tableName;
                }
            }

            if (!empty($customerTables)) {
                $output->writeln('<info>Kunden-relevante Tabellen:</info>');
                foreach ($customerTables as $table) {
                    $rowCount = $this->getTableRowCount($connection, $table);
                    $output->writeln(sprintf('  %-30s (%d Zeilen)', $table, $rowCount));
                }
                $output->writeln('');
            }

            $output->writeln('Andere Tabellen:');
            foreach (array_slice($otherTables, 0, 10) as $table) {
                $rowCount = $this->getTableRowCount($connection, $table);
                $output->writeln(sprintf('  %-30s (%d Zeilen)', $table, $rowCount));
            }
            
            if (count($otherTables) > 10) {
                $output->writeln(sprintf('  ... und %d weitere Tabellen', count($otherTables) - 10));
            }
            $output->writeln('');

        } catch (\Exception $e) {
            $output->writeln('<error>Fehler beim Abrufen der Tabellen: ' . $e->getMessage() . '</error>');
        }
    }

    private function analyzeTable($connection, string $tableName, OutputInterface $output): void
    {
        $output->writeln(sprintf('3. Tabellen-Analyse: %s', $tableName));
        $output->writeln('----------------------------------------');

        try {
            // Tabellenstruktur abrufen
            $stmt = $connection->prepare("DESCRIBE `$tableName`");
            $result = $stmt->executeQuery();
            $columns = $result->fetchAllAssociative();

            if (empty($columns)) {
                $output->writeln('<comment>Tabelle nicht gefunden oder leer</comment>');
                return;
            }

            $output->writeln('Spalten:');
            $nameColumns = [];
            $addressColumns = [];
            $coordinateColumns = [];
            $contactColumns = [];

            foreach ($columns as $column) {
                $field = $column['Field'];
                $type = $column['Type'];
                $null = $column['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
                $key = $column['Key'] ? '(' . $column['Key'] . ')' : '';
                
                $output->writeln(sprintf('  %-25s %-20s %-10s %s', $field, $type, $null, $key));

                // Analysiere Spalten-Typen
                $fieldLower = strtolower($field);
                
                if (stripos($fieldLower, 'name') !== false || stripos($fieldLower, 'firma') !== false) {
                    $nameColumns[] = $field;
                }
                if (stripos($fieldLower, 'address') !== false || stripos($fieldLower, 'adresse') !== false ||
                    stripos($fieldLower, 'street') !== false || stripos($fieldLower, 'strasse') !== false ||
                    stripos($fieldLower, 'city') !== false || stripos($fieldLower, 'stadt') !== false ||
                    stripos($fieldLower, 'plz') !== false || stripos($fieldLower, 'postal') !== false) {
                    $addressColumns[] = $field;
                }
                if (stripos($fieldLower, 'lat') !== false || stripos($fieldLower, 'lng') !== false ||
                    stripos($fieldLower, 'long') !== false || stripos($fieldLower, 'coord') !== false) {
                    $coordinateColumns[] = $field;
                }
                if (stripos($fieldLower, 'phone') !== false || stripos($fieldLower, 'tel') !== false ||
                    stripos($fieldLower, 'email') !== false || stripos($fieldLower, 'mail') !== false) {
                    $contactColumns[] = $field;
                }
            }

            $output->writeln('');
            $output->writeln('Analyse:');
            
            if (!empty($nameColumns)) {
                $output->writeln('  Name-Spalten: ' . implode(', ', $nameColumns));
            }
            if (!empty($addressColumns)) {
                $output->writeln('  Adress-Spalten: ' . implode(', ', $addressColumns));
            }
            if (!empty($coordinateColumns)) {
                $output->writeln('  Koordinaten-Spalten: ' . implode(', ', $coordinateColumns));
            } else {
                $output->writeln('  <comment>Keine GPS-Koordinaten-Spalten gefunden!</comment>');
            }
            if (!empty($contactColumns)) {
                $output->writeln('  Kontakt-Spalten: ' . implode(', ', $contactColumns));
            }

            // Beispiel-Daten anzeigen
            $output->writeln('');
            $output->writeln('Beispiel-Daten (erste 3 Zeilen):');
            $stmt = $connection->prepare("SELECT * FROM `$tableName` LIMIT 3");
            $result = $stmt->executeQuery();
            $rows = $result->fetchAllAssociative();

            foreach ($rows as $i => $row) {
                $output->writeln(sprintf('  Zeile %d:', $i + 1));
                foreach ($row as $key => $value) {
                    $displayValue = $value !== null ? (strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value) : 'NULL';
                    $output->writeln(sprintf('    %-20s: %s', $key, $displayValue));
                }
                $output->writeln('');
            }

        } catch (\Exception $e) {
            $output->writeln('<error>Fehler bei der Tabellen-Analyse: ' . $e->getMessage() . '</error>');
        }
    }

    private function suggestConfiguration($connection, OutputInterface $output): void
    {
        $output->writeln('4. Konfiguration-Vorschlag:');
        $output->writeln('----------------------------');

        try {
            // Suche nach der besten Kunden-Tabelle
            $stmt = $connection->prepare("SHOW TABLES");
            $result = $stmt->executeQuery();
            $tables = $result->fetchAllAssociative();

            $bestTable = null;
            $bestScore = 0;

            foreach ($tables as $table) {
                $tableName = array_values($table)[0];
                $score = 0;

                // Bewerte Tabellennamen
                if (stripos($tableName, 'customer') !== false) $score += 10;
                if (stripos($tableName, 'kunde') !== false) $score += 10;
                if (stripos($tableName, 'client') !== false) $score += 8;
                if (stripos($tableName, 'contact') !== false) $score += 6;
                if (stripos($tableName, 'address') !== false) $score += 5;

                // Bewerte Spalten
                try {
                    $stmt2 = $connection->prepare("DESCRIBE `$tableName`");
                    $result2 = $stmt2->executeQuery();
                    $columns = $result2->fetchAllAssociative();

                    $hasId = false;
                    $hasName = false;
                    $hasAddress = false;
                    $hasCoords = false;

                    foreach ($columns as $column) {
                        $field = strtolower($column['Field']);
                        
                        if ($field === 'id' || $field === 'customer_id' || $field === 'kunde_id') {
                            $hasId = true;
                            $score += 5;
                        }
                        if (stripos($field, 'name') !== false || stripos($field, 'firma') !== false) {
                            $hasName = true;
                            $score += 3;
                        }
                        if (stripos($field, 'address') !== false || stripos($field, 'street') !== false) {
                            $hasAddress = true;
                            $score += 2;
                        }
                        if (stripos($field, 'lat') !== false || stripos($field, 'lng') !== false) {
                            $hasCoords = true;
                            $score += 8;
                        }
                    }

                    if ($hasId && $hasName && $score > $bestScore) {
                        $bestTable = $tableName;
                        $bestScore = $score;
                    }

                } catch (\Exception $e) {
                    // Tabelle überspringen bei Fehlern
                }
            }

            if ($bestTable) {
                $output->writeln('<info>Empfohlene Tabelle: ' . $bestTable . '</info>');
                $output->writeln('');

                // Generiere SQL-Query-Vorschlag
                $stmt = $connection->prepare("DESCRIBE `$bestTable`");
                $result = $stmt->executeQuery();
                $columns = $result->fetchAllAssociative();

                $mapping = [
                    'customer_id' => null,
                    'customer_name' => null,
                    'full_address' => null,
                    'street' => null,
                    'city' => null,
                    'postal_code' => null,
                    'country' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'phone' => null,
                    'email' => null,
                    'contact_person' => null,
                    'notes' => null
                ];

                foreach ($columns as $column) {
                    $field = $column['Field'];
                    $fieldLower = strtolower($field);

                    if (!$mapping['customer_id'] && ($fieldLower === 'id' || $fieldLower === 'customer_id' || $fieldLower === 'kunde_id')) {
                        $mapping['customer_id'] = $field;
                    }
                    if (!$mapping['customer_name'] && (stripos($fieldLower, 'name') !== false || stripos($fieldLower, 'firma') !== false)) {
                        $mapping['customer_name'] = $field;
                    }
                    if (!$mapping['latitude'] && stripos($fieldLower, 'lat') !== false) {
                        $mapping['latitude'] = $field;
                    }
                    if (!$mapping['longitude'] && (stripos($fieldLower, 'lng') !== false || stripos($fieldLower, 'long') !== false)) {
                        $mapping['longitude'] = $field;
                    }
                    if (!$mapping['street'] && (stripos($fieldLower, 'street') !== false || stripos($fieldLower, 'strasse') !== false)) {
                        $mapping['street'] = $field;
                    }
                    if (!$mapping['city'] && (stripos($fieldLower, 'city') !== false || stripos($fieldLower, 'stadt') !== false || stripos($fieldLower, 'ort') !== false)) {
                        $mapping['city'] = $field;
                    }
                    if (!$mapping['postal_code'] && (stripos($fieldLower, 'plz') !== false || stripos($fieldLower, 'postal') !== false || stripos($fieldLower, 'zip') !== false)) {
                        $mapping['postal_code'] = $field;
                    }
                    if (!$mapping['phone'] && (stripos($fieldLower, 'phone') !== false || stripos($fieldLower, 'tel') !== false)) {
                        $mapping['phone'] = $field;
                    }
                    if (!$mapping['email'] && (stripos($fieldLower, 'email') !== false || stripos($fieldLower, 'mail') !== false)) {
                        $mapping['email'] = $field;
                    }
                }

                $this->generateConfigSuggestion($bestTable, $mapping, $output);

            } else {
                $output->writeln('<comment>Keine geeignete Kunden-Tabelle gefunden</comment>');
                $output->writeln('Bitte überprüfen Sie Ihre Datenbankstruktur manuell.');
            }

        } catch (\Exception $e) {
            $output->writeln('<error>Fehler bei der Konfiguration-Analyse: ' . $e->getMessage() . '</error>');
        }
    }

    private function generateConfigSuggestion(string $tableName, array $mapping, OutputInterface $output): void
    {
        $output->writeln('Vorgeschlagene config.yaml Konfiguration:');
        $output->writeln('```yaml');
        $output->writeln('crm:');
        $output->writeln('  adapter_type: mysql');
        $output->writeln('  system_name: AFS-Software');
        $output->writeln('  database:');
        $output->writeln('    # ... Ihre DB-Konfiguration ...');
        $output->writeln('  queries:');
        
        // Radius-Suche Query
        $latField = $mapping['latitude'] ?: 'latitude';
        $lngField = $mapping['longitude'] ?: 'longitude';
        $output->writeln('    find_customers_in_radius: |');
        $output->writeln('      SELECT');
        
        $selectFields = [];
        foreach ($mapping as $alias => $field) {
            if ($field) {
                $selectFields[] = "        $field as $alias";
            }
        }
        $output->writeln(implode(",\n", $selectFields));
        
        $output->writeln("      FROM $tableName");
        $output->writeln("      WHERE $latField BETWEEN :min_lat AND :max_lat");
        $output->writeln("      AND $lngField BETWEEN :min_lng AND :max_lng");
        $output->writeln("      AND $latField IS NOT NULL");
        $output->writeln("      AND $lngField IS NOT NULL");
        if ($mapping['customer_name']) {
            $output->writeln("      AND {$mapping['customer_name']} IS NOT NULL");
        }
        
        // Get Customer by ID Query
        $output->writeln('    get_customer_by_id: |');
        $output->writeln('      SELECT');
        $output->writeln(implode(",\n", $selectFields));
        $output->writeln("      FROM $tableName");
        $output->writeln("      WHERE {$mapping['customer_id']} = :customer_id");
        
        // Find by Name Query
        $output->writeln('    find_customers_by_name: |');
        $output->writeln('      SELECT');
        $output->writeln(implode(",\n", $selectFields));
        $output->writeln("      FROM $tableName");
        if ($mapping['customer_name']) {
            $output->writeln("      WHERE {$mapping['customer_name']} LIKE :customer_name");
        }
        $orderByField = $mapping['customer_name'] ?: $mapping['customer_id'];
        $output->writeln("      ORDER BY $orderByField");
        
        $output->writeln('```');
        $output->writeln('');
        
        // Warnung bei fehlenden GPS-Koordinaten
        if (!$mapping['latitude'] || !$mapping['longitude']) {
            $output->writeln('<comment>WARNUNG: Keine GPS-Koordinaten-Spalten gefunden!</comment>');
            $output->writeln('Das System benötigt Latitude/Longitude-Felder für die Proximity-Suche.');
            $output->writeln('Sie müssen GPS-Koordinaten zu Ihrer Kunden-Datenbank hinzufügen.');
        }
    }

    private function getTableRowCount($connection, string $tableName): int
    {
        try {
            $stmt = $connection->prepare("SELECT COUNT(*) as count FROM `$tableName`");
            $result = $stmt->executeQuery();
            $row = $result->fetchAssociative();
            return (int)$row['count'];
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Hilfsmethode um die Datenbankverbindung vom CRM-Adapter zu bekommen
     */
    private function getCrmConnection(CrmService $crm)
    {
        // Verwende Reflection um auf die protected connection zuzugreifen
        $reflection = new \ReflectionClass($crm);
        $adapterProperty = $reflection->getProperty('adapter');
        $adapterProperty->setAccessible(true);
        $adapter = $adapterProperty->getValue($crm);
        
        $connectionReflection = new \ReflectionClass($adapter);
        $connectionProperty = $connectionReflection->getProperty('connection');
        $connectionProperty->setAccessible(true);
        
        return $connectionProperty->getValue($adapter);
    }
}
