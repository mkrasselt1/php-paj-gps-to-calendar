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
where composer >nul 2>&1
if errorlevel 1 (
    echo Fehler: Composer ist nicht installiert oder nicht im PATH.
    echo Bitte installieren Sie Composer: https://getcomposer.org/download/
    echo.
    echo Alternative: Prüfen Sie ob 'composer.bat' oder 'composer.phar' vorhanden ist
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
echo Dies kann einige Minuten dauern...
echo.

REM Composer install ohne strenge Fehlerprüfung
composer install --no-dev --optimize-autoloader --no-interaction --verbose

REM Prüfe ob vendor-Verzeichnis existiert (bessere Prüfung als errorlevel)
if not exist "vendor" (
    echo.
    echo Composer install fehlgeschlagen. Versuche alternative Methode...
    
    REM Versuche mit php composer.phar
    if exist "composer.phar" (
        echo Verwende lokale composer.phar...
        php composer.phar install --no-dev --optimize-autoloader --no-interaction
    ) else (
        echo.
        echo WARNUNG: Composer install nicht vollständig erfolgreich.
        echo Das Setup wird trotzdem fortgesetzt...
        echo Sie können später manuell 'composer install' ausführen.
        echo.
        timeout /t 3 /nobreak >nul
    )
)

echo Dependencies-Installation abgeschlossen
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

REM Task Scheduler automatisch einrichten
echo Richte Automatisierung ein...
echo.

REM PHP Pfad ermitteln
set TASK_PHP_PATH=
where php >nul 2>&1
if errorlevel 1 (
    echo WARNUNG: PHP nicht im PATH - Task Scheduler wird übersprungen
    echo Sie können später setup-task-scheduler.bat ausführen
    goto :skip_task
) else (
    for /f "tokens=*" %%i in ('where php') do set TASK_PHP_PATH=%%i
)

REM Task erstellen
echo Erstelle Windows Task Scheduler Aufgabe...
schtasks /create /tn "PAJ GPS Calendar Check" /tr "\"%TASK_PHP_PATH%\" \"%cd%\bin\paj-gps-calendar\" check" /sc minute /mo 3 /st 00:00 /sd %date% /ru "%USERNAME%" /f >nul 2>&1

if errorlevel 1 (
    echo WARNUNG: Task Scheduler Aufgabe konnte nicht automatisch erstellt werden
    echo Führen Sie später setup-task-scheduler.bat als Administrator aus
) else (
    echo ✓ Task Scheduler Aufgabe erfolgreich erstellt
    echo   Task läuft automatisch alle 3 Minuten
)

:skip_task
echo.

echo Installation abgeschlossen!
echo.
echo Nächste Schritte:
echo 1. Bearbeiten Sie config\config.yaml mit Ihren Zugangsdaten
echo 2. Testen Sie das System: php bin\paj-gps-calendar check --dry-run
echo 3. Das System läuft automatisch alle 3 Minuten (Task Scheduler)
echo.
echo Task Status prüfen: schtasks /query /tn "PAJ GPS Calendar Check"
echo Logs anzeigen: type logs\application.log
echo Hilfe: php bin\paj-gps-calendar --help
echo.
pause
