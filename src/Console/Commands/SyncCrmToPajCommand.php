<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\PajApiService;
use PajGpsCalendar\Services\CrmService;
use PajGpsCalendar\Services\GeoService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class SyncCrmToPajCommand extends Command
{
    protected static $defaultName = 'sync:crm-to-paj';
    
    private ConfigService $config;
    private Logger $logger;

    protected function configure(): void
    {
        $this
            ->setDescription('Synchronisiert Kundenadressen vom CRM zu PAJ (nur CRM → PAJ, nie umgekehrt!)')
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Zeigt was synchronisiert würde, ohne Änderungen vorzunehmen'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximale Anzahl Kunden zum Synchronisieren',
                100
            )
            ->addOption(
                'force-update',
                'f',
                InputOption::VALUE_NONE,
                'Aktualisiert auch bereits synchronisierte Kunden'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeServices();
            
            $output->writeln('<info>🔄 CRM → PAJ Synchronisation</info>');
            $output->writeln('===============================');
            $output->writeln('<comment>⚠️  WICHTIG: Synchronisation läuft nur CRM → PAJ (CRM wird niemals verändert!)</comment>');
            $output->writeln('');
            
            $dryRun = $input->getOption('dry-run');
            $limit = (int) $input->getOption('limit');
            $forceUpdate = $input->getOption('force-update');
            
            if ($dryRun) {
                $output->writeln('<comment>🧪 Dry-Run Modus - keine Änderungen werden vorgenommen</comment>');
                $output->writeln('');
            }

            // Services initialisieren
            $pajApi = new PajApiService($this->config, $this->logger);
            $crm = new CrmService($this->config, $this->logger, new GeoService($this->config, $this->logger));

            // CRM-Verbindung testen
            if (!$crm->testCrmConnection()) {
                $output->writeln('<error>❌ CRM-Verbindung fehlgeschlagen</error>');
                return Command::FAILURE;
            }
            
            $output->writeln('<info>✅ CRM-Verbindung erfolgreich</info>');

            // PAJ-Verbindung testen
            try {
                $pajApi->authenticate();
                $output->writeln('<info>✅ PAJ API-Verbindung erfolgreich</info>');
            } catch (\Exception $e) {
                $output->writeln('<error>❌ PAJ API-Verbindung fehlgeschlagen: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }

            $output->writeln('');
            $output->writeln('📊 Lade Daten...');

            // Hole alle CRM-Kunden mit GPS-Koordinaten
            $output->writeln('  -> CRM-Kunden mit GPS-Koordinaten laden...');
            $crmCustomers = $this->getAllCrmCustomers($crm, $limit);
            $output->writeln(sprintf('  -> %d CRM-Kunden gefunden', count($crmCustomers)));

            // Hole bestehende PAJ-Kunden
            $output->writeln('  -> PAJ-Kunden laden...');
            $pajCustomers = $pajApi->getPajCustomers();
            $output->writeln(sprintf('  -> %d PAJ-Kunden gefunden', count($pajCustomers)));

            $output->writeln('');
            $output->writeln('🔍 Synchronisation analysieren...');

            $toCreate = [];
            $toUpdate = [];
            $alreadySynced = [];

            foreach ($crmCustomers as $crmCustomer) {
                $existingPajCustomer = $this->findPajCustomerByCrmId($pajCustomers, $crmCustomer['customer_id']);
                
                if ($existingPajCustomer) {
                    if ($forceUpdate || $this->needsUpdate($existingPajCustomer, $crmCustomer)) {
                        $toUpdate[] = $crmCustomer;
                    } else {
                        $alreadySynced[] = $crmCustomer;
                    }
                } else {
                    $toCreate[] = $crmCustomer;
                }
            }

            // Statistiken anzeigen
            $output->writeln(sprintf('  📊 Zu erstellen: <info>%d</info>', count($toCreate)));
            $output->writeln(sprintf('  📊 Zu aktualisieren: <comment>%d</comment>', count($toUpdate)));
            $output->writeln(sprintf('  📊 Bereits synchron: <info>%d</info>', count($alreadySynced)));
            $output->writeln('');

            if (empty($toCreate) && empty($toUpdate)) {
                $output->writeln('<success>✅ Alle Kunden sind bereits synchron!</success>');
                return Command::SUCCESS;
            }

            // Synchronisation durchführen
            $created = 0;
            $updated = 0;
            $errors = 0;

            if (!empty($toCreate)) {
                $output->writeln('<info>➕ Neue Kunden zu PAJ hinzufügen:</info>');
                foreach ($toCreate as $customer) {
                    $result = $this->syncCustomer($pajApi, $customer, 'create', $dryRun, $output);
                    if ($result) $created++;
                    else $errors++;
                }
                $output->writeln('');
            }

            if (!empty($toUpdate)) {
                $output->writeln('<comment>📝 Bestehende PAJ-Kunden aktualisieren:</comment>');
                foreach ($toUpdate as $customer) {
                    $result = $this->syncCustomer($pajApi, $customer, 'update', $dryRun, $output);
                    if ($result) $updated++;
                    else $errors++;
                }
                $output->writeln('');
            }

            // Zusammenfassung
            $output->writeln('<info>📈 Synchronisation abgeschlossen:</info>');
            $output->writeln(sprintf('  ✅ Erstellt: %d', $created));
            $output->writeln(sprintf('  📝 Aktualisiert: %d', $updated));
            $output->writeln(sprintf('  ❌ Fehler: %d', $errors));
            
            if ($dryRun) {
                $output->writeln('');
                $output->writeln('<comment>💡 Führen Sie den Befehl ohne --dry-run aus, um die Änderungen anzuwenden</comment>');
            }

            return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>❌ Fehler: %s</error>', $e->getMessage()));
            $this->logger->error('Sync command failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }

    private function getAllCrmCustomers(CrmService $crm, int $limit): array
    {
        // Hole alle Kunden mit GPS-Koordinaten (verwendet bestehende findCustomersNearLocation mit großem Radius)
        // Mittelpunkt Deutschland: 51.1657, 10.4515
        $customers = $crm->findCustomersNearLocation(51.1657, 10.4515, 1000000); // 1000km Radius = ganz Deutschland
        
        // Begrenze auf gewünschte Anzahl
        return array_slice($customers, 0, $limit);
    }

    private function findPajCustomerByCrmId(array $pajCustomers, string $crmId): ?array
    {
        foreach ($pajCustomers as $pajCustomer) {
            if (isset($pajCustomer['external_id']) && $pajCustomer['external_id'] === $crmId) {
                return $pajCustomer;
            }
        }
        return null;
    }

    private function needsUpdate(array $pajCustomer, array $crmCustomer): bool
    {
        // Prüfe ob sich wichtige Daten geändert haben
        $fieldsToCheck = [
            'name' => 'customer_name',
            'address' => 'full_address',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'phone' => 'phone',
            'email' => 'email'
        ];

        foreach ($fieldsToCheck as $pajField => $crmField) {
            $pajValue = $pajCustomer[$pajField] ?? '';
            $crmValue = $crmCustomer[$crmField] ?? '';
            
            if ($pajValue !== $crmValue) {
                return true;
            }
        }

        return false;
    }

    private function syncCustomer(PajApiService $pajApi, array $customer, string $action, bool $dryRun, OutputInterface $output): bool
    {
        $actionIcon = $action === 'create' ? '➕' : '📝';
        $actionText = $action === 'create' ? 'Erstelle' : 'Aktualisiere';
        
        $output->writeln(sprintf(
            '  %s %s: %s (%s)', 
            $actionIcon,
            $actionText,
            $customer['customer_name'], 
            $customer['customer_id']
        ));

        if ($dryRun) {
            $output->writeln('    🧪 Dry-Run - würde synchronisiert werden');
            return true;
        }

        try {
            $success = $pajApi->syncCustomerToPaj($customer);
            
            if ($success) {
                $output->writeln('    ✅ Erfolgreich synchronisiert');
                return true;
            } else {
                $output->writeln('    ❌ Synchronisation fehlgeschlagen');
                return false;
            }
        } catch (\Exception $e) {
            $output->writeln(sprintf('    ❌ Fehler: %s', $e->getMessage()));
            return false;
        }
    }
    
    private function initializeServices(): void
    {
        $this->config = new ConfigService();
        
        $this->logger = new Logger('crm-paj-sync');
        $logFile = $this->config->get('settings.log_file', 'logs/application.log');
        $this->logger->pushHandler(new StreamHandler($logFile));
    }
}
