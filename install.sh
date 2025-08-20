#!/bin/bash

# PAJ GPS to Calendar - Installation Script
# Dieses Skript installiert alle Abhängigkeiten und richtet das System ein

set -e  # Beenden bei Fehlern

echo "PAJ GPS to Calendar - Installation"
echo "=================================="
echo ""

# PHP Version prüfen
echo "Prüfe PHP Version..."
php_version=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
required_version="8.1"

if php -r "exit(version_compare(PHP_VERSION, '$required_version', '<') ? 1 : 0);"; then
    echo "Fehler: PHP $required_version oder höher ist erforderlich. Aktuelle Version: $php_version"
    exit 1
fi

echo "PHP Version OK: $php_version"
echo ""

# Composer prüfen
echo "Prüfe Composer..."
if ! command -v composer &> /dev/null; then
    echo "Fehler: Composer ist nicht installiert."
    echo "Bitte installieren Sie Composer: https://getcomposer.org/download/"
    exit 1
fi

echo "Composer gefunden"
echo ""

# Verzeichnisse erstellen
echo "Erstelle Verzeichnisse..."
mkdir -p logs
mkdir -p config
chmod 755 logs
echo "Verzeichnisse erstellt"
echo ""

# Composer Dependencies installieren
echo "Installiere PHP Abhängigkeiten..."
composer install --no-dev --optimize-autoloader
echo "Abhängigkeiten installiert"
echo ""

# Konfigurationsdatei erstellen
if [ ! -f "config/config.yaml" ]; then
    echo "Erstelle Konfigurationsdatei..."
    cp config/config.example.yaml config/config.yaml
    echo "Konfigurationsdatei erstellt: config/config.yaml"
    echo ""
fi

# Ausführungsrechte setzen
echo "Setze Ausführungsrechte..."
chmod +x bin/paj-gps-calendar
echo "Ausführungsrechte gesetzt"
echo ""

# Setup ausführen
echo "Führe Setup aus..."
php bin/paj-gps-calendar setup
echo ""

echo "Installation abgeschlossen!"
echo ""
echo "Nächste Schritte:"
echo "1. Bearbeiten Sie config/config.yaml mit Ihren Zugangsdaten"
echo "2. Testen Sie das System: php bin/paj-gps-calendar check --dry-run"
echo "3. Richten Sie einen Cronjob ein für automatische Ausführung"
echo ""
echo "Hilfe: php bin/paj-gps-calendar --help"
