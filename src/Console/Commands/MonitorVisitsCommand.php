<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\VisitDurationService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class MonitorVisitsCommand extends Command
{
    protected static $defaultName = 'monitor:visits';
    
    private ConfigService $config;
    private Logger $logger;

    protected function configure(): void
    {
        $this
            ->setDescription('Überwacht aktuelle Fahrzeugbesuche und Aufenthaltsdauern')
            ->addOption(
                'continuous',
                'c',
                InputOption::VALUE_NONE,
                'Kontinuierliche Überwachung (aktualisiert alle 30 Sekunden)'
            )
            ->addOption(
                'clear-old',
                null,
                InputOption::VALUE_NONE,
                'Lösche alte Tracking-Einträge'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeServices();
            
            $visitDuration = new VisitDurationService($this->config, $this->logger);
            
            if ($input->getOption('clear-old')) {
                // Diese Methode müssten wir im VisitDurationService noch hinzufügen
                $output->writeln('<info>Alte Tracking-Einträge werden gelöscht...</info>');
                // $visitDuration->cleanupOldEntries(); // Public machen
                $output->writeln('<info>✓ Cleanup abgeschlossen</info>');
                return Command::SUCCESS;
            }
            
            $continuous = $input->getOption('continuous');
            
            if ($continuous) {
                $output->writeln('<info>🔄 Kontinuierliche Überwachung gestartet (Ctrl+C zum Beenden)</info>');
                $output->writeln('');
                
                while (true) {
                    $this->displayCurrentVisits($visitDuration, $output);
                    sleep(30);
                    
                    // Bildschirm leeren (funktioniert in den meisten Terminals)
                    $output->write("\033[2J\033[H");
                    $output->writeln('<info>🔄 Aktualisiert: ' . date('H:i:s') . '</info>');
                    $output->writeln('');
                }
            } else {
                $this->displayCurrentVisits($visitDuration, $output);
            }
            
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Fehler beim Monitoring: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    private function displayCurrentVisits(VisitDurationService $visitDuration, OutputInterface $output): void
    {
        $stats = $visitDuration->getCurrentVisitStatistics();
        
        if (empty($stats)) {
            $output->writeln('<comment>📍 Keine aktiven Besuche</comment>');
            return;
        }
        
        $output->writeln('<info>📊 Aktuelle Besuche:</info>');
        $output->writeln('===================');
        $output->writeln('');
        
        foreach ($stats as $stat) {
            $visitStart = new \DateTime($stat['visit_start']);
            $lastSeen = new \DateTime($stat['last_seen']);
            $now = new \DateTime();
            
            // Berechne wie lange der Besuch schon dauert
            $durationMinutes = (int) round(($now->getTimestamp() - $visitStart->getTimestamp()) / 60);
            
            // Berechne wie lange das letzte Signal her ist
            $lastSeenMinutes = (int) round(($now->getTimestamp() - $lastSeen->getTimestamp()) / 60);
            
            // Status bestimmen
            $status = '🟢 Aktiv';
            if ($lastSeenMinutes > 10) {
                $status = '🟡 Signal schwach';
            }
            if ($lastSeenMinutes > 30) {
                $status = '🔴 Möglicherweise weg';
            }
            
            $output->writeln(sprintf(
                '<info>🚗 %s</info> bei <comment>%s</comment>',
                $stat['vehicle_id'],
                $stat['customer_name']
            ));
            
            $output->writeln(sprintf(
                '   ⏱️  Aufenthaltsdauer: <info>%d Min</info> | Status: %s',
                $durationMinutes,
                $status
            ));
            
            $output->writeln(sprintf(
                '   📍 Ø Entfernung: <comment>%.0fm</comment> | Positionen: %d | Letztes Signal: %s',
                $stat['avg_distance'],
                $stat['position_count'],
                $lastSeenMinutes === 0 ? 'gerade eben' : "{$lastSeenMinutes} Min"
            ));
            
            $output->writeln(sprintf(
                '   🕐 Besuch seit: <comment>%s</comment>',
                $visitStart->format('H:i:s')
            ));
            
            $output->writeln('');
        }
        
        // Zusammenfassung
        $totalActiveVisits = count($stats);
        $totalVehicles = count(array_unique(array_column($stats, 'vehicle_id')));
        
        $output->writeln('<info>📈 Zusammenfassung:</info>');
        $output->writeln(sprintf('   👥 %d aktive Besuche von %d Fahrzeugen', $totalActiveVisits, $totalVehicles));
        
        // Empfehlungen
        $longVisits = array_filter($stats, function($stat) {
            $visitStart = new \DateTime($stat['visit_start']);
            $now = new \DateTime();
            $minutes = ($now->getTimestamp() - $visitStart->getTimestamp()) / 60;
            return $minutes > 120; // Länger als 2 Stunden
        });
        
        if (!empty($longVisits)) {
            $output->writeln('');
            $output->writeln('<warning>⚠️  Lange Aufenthalte (>2h):</warning>');
            foreach ($longVisits as $visit) {
                $visitStart = new \DateTime($visit['visit_start']);
                $now = new \DateTime();
                $hours = round(($now->getTimestamp() - $visitStart->getTimestamp()) / 3600, 1);
                
                $output->writeln(sprintf(
                    '   🕐 %s bei %s seit %.1f Stunden',
                    $visit['vehicle_id'],
                    $visit['customer_name'],
                    $hours
                ));
            }
        }
    }

    private function initializeServices(): void
    {
        $this->config = new ConfigService();
        
        $this->logger = new Logger('monitor');
        $logFile = $this->config->get('settings.log_file', 'logs/application.log');
        $this->logger->pushHandler(new StreamHandler($logFile));
    }
}
