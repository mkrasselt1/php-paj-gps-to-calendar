@echo off
REM PAJ GPS to Calendar - Vereinfachte Windows Installation

echo PAJ GPS to Calendar - Vereinfachte Installation
echo =================================================
echo.

REM PHP Version prüfen
echo 1. Prüfe PHP...
php -v
if errorlevel 1 (
    echo FEHLER: PHP ist nicht installiert oder nicht im PATH
    echo Bitte installieren Sie PHP 8.1 oder höher
    goto :error
)
echo   ✓ PHP gefunden
echo.

REM Composer prüfen
echo 2. Prüfe Composer...
composer --version
if errorlevel 1 (
    echo FEHLER: Composer ist nicht installiert
    echo Bitte laden Sie Composer herunter: https://getcomposer.org/download/
    goto :error
)
echo   ✓ Composer gefunden
echo.

REM Verzeichnisse erstellen
echo 3. Erstelle Verzeichnisse...
if not exist "logs" mkdir logs
if not exist "config" mkdir config
echo   ✓ Verzeichnisse erstellt
echo.

REM Dependencies installieren
echo 4. Installiere Abhängigkeiten...
echo    Dies kann einige Minuten dauern...
composer install --no-dev --optimize-autoloader --no-interaction
if errorlevel 1 (
    echo FEHLER: Composer install fehlgeschlagen
    echo.
    echo Versuchen Sie manuell:
    echo   composer install
    goto :error
)
echo   ✓ Abhängigkeiten installiert
echo.

REM Konfiguration kopieren
echo 5. Erstelle Konfigurationsdatei...
if not exist "config\config.yaml" (
    copy "config\config.example.yaml" "config\config.yaml" >nul
    echo   ✓ config\config.yaml erstellt
) else (
    echo   - config\config.yaml bereits vorhanden
)
echo.

REM Test
echo 6. Teste Installation...
php bin\paj-gps-calendar --version
if errorlevel 1 (
    echo FEHLER: Installation fehlgeschlagen
    goto :error
)
echo   ✓ Installation erfolgreich
echo.

echo =================================================
echo Installation abgeschlossen!
echo =================================================
echo.
echo Nächste Schritte:
echo 1. Bearbeiten Sie config\config.yaml mit Ihren Zugangsdaten
echo 2. Testen Sie das System:
echo    php bin\paj-gps-calendar test:paj
echo    php bin\paj-gps-calendar test:crm  
echo    php bin\paj-gps-calendar test:calendar
echo 3. Erste Ausführung:
echo    php bin\paj-gps-calendar check --dry-run
echo.
echo Alle Commands anzeigen:
echo    php bin\paj-gps-calendar list
echo.
pause
exit /b 0

:error
echo.
echo Installation fehlgeschlagen!
echo.
echo Hilfe:
echo 1. Stellen Sie sicher, dass PHP 8.1+ installiert ist
echo 2. Installieren Sie Composer: https://getcomposer.org/download/
echo 3. Führen Sie das Skript als Administrator aus
echo.
pause
exit /b 1
