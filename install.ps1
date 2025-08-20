# PAJ GPS to Calendar - PowerShell Installation Script
# Für Windows PowerShell

Write-Host "PAJ GPS to Calendar - PowerShell Installation" -ForegroundColor Green
Write-Host "=================================================" -ForegroundColor Green
Write-Host ""

try {
    # PHP prüfen
    Write-Host "1. Prüfe PHP..." -ForegroundColor Yellow
    $phpVersion = & php -r "echo PHP_VERSION;" 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "PHP ist nicht installiert oder nicht im PATH"
    }
    Write-Host "   ✓ PHP Version: $phpVersion" -ForegroundColor Green
    
    # Version prüfen
    $versionCheck = & php -r "exit(version_compare(PHP_VERSION, '8.1', '<') ? 1 : 0);" 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "PHP 8.1 oder höher ist erforderlich (gefunden: $phpVersion)"
    }
    Write-Host ""

    # Composer prüfen
    Write-Host "2. Prüfe Composer..." -ForegroundColor Yellow
    $composerVersion = & composer --version 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "Composer ist nicht installiert. Bitte installieren Sie Composer von https://getcomposer.org/download/"
    }
    Write-Host "   ✓ $composerVersion" -ForegroundColor Green
    Write-Host ""

    # Verzeichnisse erstellen
    Write-Host "3. Erstelle Verzeichnisse..." -ForegroundColor Yellow
    if (!(Test-Path "logs")) { New-Item -ItemType Directory -Name "logs" | Out-Null }
    if (!(Test-Path "config")) { New-Item -ItemType Directory -Name "config" | Out-Null }
    Write-Host "   ✓ Verzeichnisse erstellt" -ForegroundColor Green
    Write-Host ""

    # Dependencies installieren
    Write-Host "4. Installiere PHP Abhängigkeiten..." -ForegroundColor Yellow
    Write-Host "   Dies kann einige Minuten dauern..." -ForegroundColor Cyan
    Write-Host ""
    
    & composer install --no-dev --optimize-autoloader --no-interaction
    if ($LASTEXITCODE -ne 0) {
        throw "Composer install fehlgeschlagen"
    }
    Write-Host "   ✓ Abhängigkeiten installiert" -ForegroundColor Green
    Write-Host ""

    # Konfiguration kopieren
    Write-Host "5. Erstelle Konfigurationsdatei..." -ForegroundColor Yellow
    if (!(Test-Path "config\config.yaml")) {
        Copy-Item "config\config.example.yaml" "config\config.yaml"
        Write-Host "   ✓ config\config.yaml erstellt" -ForegroundColor Green
    } else {
        Write-Host "   - config\config.yaml bereits vorhanden" -ForegroundColor Gray
    }
    Write-Host ""

    # Test
    Write-Host "6. Teste Installation..." -ForegroundColor Yellow
    $testOutput = & php bin\paj-gps-calendar --version 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "Installation Test fehlgeschlagen"
    }
    Write-Host "   ✓ Installation erfolgreich" -ForegroundColor Green
    Write-Host ""

    # Erfolgsmeldung
    Write-Host "=================================================" -ForegroundColor Green
    Write-Host "Installation erfolgreich abgeschlossen!" -ForegroundColor Green
    Write-Host "=================================================" -ForegroundColor Green
    Write-Host ""
    
    Write-Host "Nächste Schritte:" -ForegroundColor Yellow
    Write-Host "1. Bearbeiten Sie config\config.yaml mit Ihren Zugangsdaten"
    Write-Host "2. Testen Sie das System:"
    Write-Host "   php bin\paj-gps-calendar test:paj" -ForegroundColor Cyan
    Write-Host "   php bin\paj-gps-calendar test:crm" -ForegroundColor Cyan
    Write-Host "   php bin\paj-gps-calendar test:calendar" -ForegroundColor Cyan
    Write-Host "3. Erste Ausführung:"
    Write-Host "   php bin\paj-gps-calendar check --dry-run" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Alle Commands anzeigen:"
    Write-Host "   php bin\paj-gps-calendar list" -ForegroundColor Cyan
    Write-Host ""

} catch {
    Write-Host ""
    Write-Host "FEHLER: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host ""
    Write-Host "Mögliche Lösungen:" -ForegroundColor Yellow
    Write-Host "1. Stellen Sie sicher, dass PHP 8.1+ installiert ist"
    Write-Host "2. Installieren Sie Composer: https://getcomposer.org/download/"
    Write-Host "3. Führen Sie PowerShell als Administrator aus"
    Write-Host "4. Prüfen Sie Ihre Internetverbindung"
    Write-Host ""
    Write-Host "Für manuelle Installation:"
    Write-Host "   composer install"
    Write-Host "   copy config\config.example.yaml config\config.yaml"
    Write-Host ""
    exit 1
}

Write-Host "Drücken Sie eine beliebige Taste zum Beenden..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
