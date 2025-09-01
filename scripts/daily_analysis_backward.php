<?php
/**
 * Tägliche rückwärts GPS-Analyse vom 18.08.2025 bis 18.08.2024
 * Geht Tag für Tag zurück und analysiert historische Daten
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use PajGpsCalendar\Console\Commands\HistoryCommand;

// Konfiguration
$startDate = new DateTime('2025-08-22', new DateTimeZone('Europe/Berlin')); // Heute
$endDate = new DateTime('2025-08-22', new DateTimeZone('Europe/Berlin'));   // Nur heute
$dryRun = false; // Auf true setzen für Test-Modus
$delaySeconds = 1; // Pause zwischen den Tagen (um Server zu schonen)

// Console Application Setup
$application = new Application('PAJ GPS Calendar Bulk Analysis');
$historyCommand = new HistoryCommand();
$application->add($historyCommand);

$output = new ConsoleOutput();
$output->writeln('<info>🚀 Starte tägliche rückwärts GPS-Analyse</info>');
$output->writeln(sprintf('📅 Von: %s bis: %s', 
    $startDate->format('d.m.Y'), 
    $endDate->format('d.m.Y')
));
$output->writeln(sprintf('⚙️  Modus: %s', $dryRun ? 'DRY-RUN (Testmodus)' : 'LIVE (Kalendereinträge werden erstellt)'));
$output->writeln(sprintf('⏱️  Pause zwischen Tagen: %d Sekunden', $delaySeconds));
$output->writeln('');

// Statistiken
$totalDays = 0;
$successfulDays = 0;
$failedDays = 0;
$totalEntriesCreated = 0;
$currentDate = clone $startDate;

// Tag für Tag rückwärts durchgehen
while ($currentDate >= $endDate) {
    $totalDays++;
    $dateString = $currentDate->format('d.m.Y');
    
    $output->writeln(sprintf('📍 Analysiere Tag %d: %s', $totalDays, $dateString));
    
    try {
        // Kommando-Parameter vorbereiten
        $input = new ArrayInput([
            'command' => 'history',
            '--from' => $currentDate->format('d.m.Y'),
            '--to' => $currentDate->format('d.m.Y'),
        ]);
        
        // Dry-Run Parameter hinzufügen falls aktiviert
        if ($dryRun) {
            $input->setOption('dry-run', true);
        }
        
        // History-Kommando ausführen
        $exitCode = $historyCommand->run($input, $output);
        
        if ($exitCode === 0) {
            $successfulDays++;
            $output->writeln(sprintf('  ✅ Tag %s erfolgreich analysiert', $dateString));
        } else {
            $failedDays++;
            $output->writeln(sprintf('  ❌ Fehler bei Tag %s (Exit Code: %d)', $dateString, $exitCode));
        }
        
    } catch (Exception $e) {
        $failedDays++;
        $output->writeln(sprintf('  ❌ Exception bei Tag %s: %s', $dateString, $e->getMessage()));
    }
    
    // Einen Tag zurückgehen
    $currentDate->sub(new DateInterval('P1D'));
    
    // Kurze Pause um Server zu schonen (außer beim letzten Tag)
    if ($currentDate >= $endDate && $delaySeconds > 0) {
        $output->writeln(sprintf('  ⏳ Warte %d Sekunden...', $delaySeconds));
        sleep($delaySeconds);
    }
    
    $output->writeln(''); // Leerzeile für bessere Lesbarkeit
}

// Zusammenfassung
$output->writeln('<info>📊 ANALYSE ABGESCHLOSSEN</info>');
$output->writeln('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
$output->writeln(sprintf('📅 Zeitraum: %s bis %s', 
    $startDate->format('d.m.Y'), 
    $endDate->format('d.m.Y')
));
$output->writeln(sprintf('📈 Tage gesamt: %d', $totalDays));
$output->writeln(sprintf('✅ Erfolgreich: %d (%.1f%%)', 
    $successfulDays, 
    $totalDays > 0 ? ($successfulDays / $totalDays * 100) : 0
));
$output->writeln(sprintf('❌ Fehlgeschlagen: %d (%.1f%%)', 
    $failedDays, 
    $totalDays > 0 ? ($failedDays / $totalDays * 100) : 0
));

if ($dryRun) {
    $output->writeln('<comment>⚠️  DRY-RUN Modus war aktiviert - keine Kalendereinträge wurden erstellt!</comment>');
} else {
    $output->writeln('<info>📅 Kalendereinträge wurden in den NextCloud Kalender geschrieben</info>');
}

$output->writeln('');
$output->writeln('<info>🎉 Bulk-Analyse beendet!</info>');

// Speichere eine kleine Statistik-Datei
$statsFile = __DIR__ . '/../logs/bulk_analysis_' . date('Y-m-d_H-i-s') . '.log';
file_put_contents($statsFile, sprintf(
    "PAJ GPS Bulk Analysis Report\n" .
    "============================\n" .
    "Datum: %s\n" .
    "Zeitraum: %s bis %s\n" .
    "Tage gesamt: %d\n" .
    "Erfolgreich: %d\n" .
    "Fehlgeschlagen: %d\n" .
    "Modus: %s\n" .
    "Ausführungszeit: %s\n",
    date('d.m.Y H:i:s'),
    $startDate->format('d.m.Y'),
    $endDate->format('d.m.Y'),
    $totalDays,
    $successfulDays,
    $failedDays,
    $dryRun ? 'DRY-RUN' : 'LIVE',
    date('d.m.Y H:i:s')
));

$output->writeln(sprintf('📄 Statistiken gespeichert: %s', $statsFile));
