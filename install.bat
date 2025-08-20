@echo off
REM PAJ GPS to Calendar - Windows Installation Script

echo PAJ GPS to Calendar - Installation
echo ==================================
echo.

REM PHP Version prüfen
echo Prüfe PHP Version...
php -v >nul 2>&1
if errorlevel 1 (
    echo Fehler: PHP ist nicht installiert oder nicht im PATH.
    echo Bitte installieren Sie PHP 8.1 oder höher.
    pause
    exit /b 1
)

php -r "exit(version_compare(PHP_VERSION, '8.1', '<') ? 1 : 0);"
if errorlevel 1 (
    echo Fehler: PHP 8.1 oder höher ist erforderlich.
    php -r "echo 'Aktuelle Version: '.PHP_VERSION;"
    pause
    exit /b 1
)

echo PHP Version OK
echo.

REM Composer prüfen
echo Prüfe Composer...
composer --version >nul 2>&1
if errorlevel 1 (
    echo Fehler: Composer ist nicht installiert.
    echo Bitte installieren Sie Composer: https://getcomposer.org/download/
    pause
    exit /b 1
)

echo Composer gefunden
echo.

REM Verzeichnisse erstellen
echo Erstelle Verzeichnisse...
if not exist "logs" mkdir logs
if not exist "config" mkdir config
echo Verzeichnisse erstellt
echo.

REM Composer Dependencies installieren
echo Installiere PHP Abhängigkeiten...
composer install --no-dev --optimize-autoloader
if errorlevel 1 (
    echo Fehler beim Installieren der Abhängigkeiten
    pause
    exit /b 1
)
echo Abhängigkeiten installiert
echo.

REM Konfigurationsdatei erstellen
if not exist "config\config.yaml" (
    echo Erstelle Konfigurationsdatei...
    copy "config\config.example.yaml" "config\config.yaml"
    echo Konfigurationsdatei erstellt: config\config.yaml
    echo.
)

REM Setup ausführen
echo Führe Setup aus...
php bin\paj-gps-calendar setup
echo.

echo Installation abgeschlossen!
echo.
echo Nächste Schritte:
echo 1. Bearbeiten Sie config\config.yaml mit Ihren Zugangsdaten
echo 2. Testen Sie das System: php bin\paj-gps-calendar check --dry-run
echo 3. Richten Sie eine geplante Aufgabe ein für automatische Ausführung
echo.
echo Hilfe: php bin\paj-gps-calendar --help
echo.
pause
