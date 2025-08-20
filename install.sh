#!/bin/bash
# PAJ GPS to Calendar - Universal Installation Script
# Funktioniert unter Linux und macOS mit automatischer Cron-Einrichtung

echo "PAJ GPS to Calendar - Installation (Unix/Linux)"
echo "==============================================="
echo ""

# Farben für bessere Lesbarkeit
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Fehler-Handler
set -e
trap 'echo -e "${RED}Installation fehlgeschlagen!${NC}"; exit 1' ERR

# PHP Version prüfen
echo -e "${YELLOW}1. Prüfe PHP Version...${NC}"
if ! command -v php &> /dev/null; then
    echo -e "${RED}FEHLER: PHP ist nicht installiert${NC}"
    echo "Bitte installieren Sie PHP 8.1 oder höher"
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_VERSION;")
if php -r "exit(version_compare(PHP_VERSION, '8.1', '<') ? 1 : 0);"; then
    echo -e "${RED}FEHLER: PHP 8.1 oder höher erforderlich (gefunden: $PHP_VERSION)${NC}"
    exit 1
fi

echo -e "${GREEN}   ✓ PHP Version: $PHP_VERSION${NC}"
echo ""

# Composer prüfen
echo -e "${YELLOW}2. Prüfe Composer...${NC}"
if ! command -v composer &> /dev/null; then
    echo -e "${RED}FEHLER: Composer ist nicht installiert${NC}"
    echo "Installation: https://getcomposer.org/download/"
    exit 1
fi

COMPOSER_VERSION=$(composer --version --no-ansi 2>/dev/null | head -n1)
echo -e "${GREEN}   ✓ $COMPOSER_VERSION${NC}"
echo ""

# Verzeichnisse erstellen
echo -e "${YELLOW}3. Erstelle Verzeichnisse...${NC}"
mkdir -p logs config
chmod 755 logs
echo -e "${GREEN}   ✓ Verzeichnisse erstellt${NC}"
echo ""

# Dependencies installieren
echo -e "${YELLOW}4. Installiere PHP Abhängigkeiten...${NC}"
echo "   Dies kann einige Minuten dauern..."
composer install --no-dev --optimize-autoloader --no-interaction
echo -e "${GREEN}   ✓ Abhängigkeiten installiert${NC}"
echo ""

# Konfigurationsdatei erstellen
echo -e "${YELLOW}5. Erstelle Konfigurationsdatei...${NC}"
if [ ! -f "config/config.yaml" ]; then
    cp config/config.example.yaml config/config.yaml
    echo -e "${GREEN}   ✓ config/config.yaml erstellt${NC}"
else
    echo "   - config/config.yaml bereits vorhanden"
fi
echo ""

# Ausführungsrechte setzen
echo -e "${YELLOW}6. Setze Ausführungsrechte...${NC}"
chmod +x bin/paj-gps-calendar
echo -e "${GREEN}   ✓ Ausführungsrechte gesetzt${NC}"
echo ""

# Setup ausführen
echo -e "${YELLOW}7. Führe Setup aus...${NC}"
php bin/paj-gps-calendar setup
echo ""

# Cron Job einrichten
echo -e "${YELLOW}8. Richte Automatisierung ein...${NC}"

# Aktuellen Pfad ermitteln
CURRENT_PATH="$(pwd)"
PHP_PATH="$(which php)"

# Cron Entry generieren
CRON_ENTRY="*/3 * * * * cd \"$CURRENT_PATH\" && \"$PHP_PATH\" bin/paj-gps-calendar check >/dev/null 2>&1"

# Prüfen ob Cron Entry bereits existiert
if crontab -l 2>/dev/null | grep -F "paj-gps-calendar check" >/dev/null; then
    echo "   - Cron Job bereits vorhanden"
else
    # Cron Job hinzufügen
    (crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}   ✓ Cron Job erstellt (läuft alle 3 Minuten)${NC}"
    else
        echo -e "${YELLOW}   WARNUNG: Cron Job konnte nicht automatisch erstellt werden${NC}"
        echo "   Fügen Sie manuell hinzu:"
        echo "   $CRON_ENTRY"
    fi
fi
echo ""

echo -e "${GREEN}===============================================${NC}"
echo -e "${GREEN}Installation erfolgreich abgeschlossen!${NC}"
echo -e "${GREEN}===============================================${NC}"
echo ""
echo -e "${YELLOW}Nächste Schritte:${NC}"
echo "1. Bearbeiten Sie config/config.yaml mit Ihren Zugangsdaten"
echo "2. Testen Sie das System:"
echo -e "   ${GREEN}php bin/paj-gps-calendar test:paj${NC}"
echo -e "   ${GREEN}php bin/paj-gps-calendar test:crm${NC}"
echo -e "   ${GREEN}php bin/paj-gps-calendar test:calendar${NC}"
echo "3. Erste Ausführung:"
echo -e "   ${GREEN}php bin/paj-gps-calendar check --dry-run${NC}"
echo ""
echo "Cron Job Status anzeigen:"
echo -e "   ${GREEN}crontab -l | grep paj-gps-calendar${NC}"
echo ""
echo "Alle Commands anzeigen:"
echo -e "   ${GREEN}php bin/paj-gps-calendar list${NC}"
echo ""
