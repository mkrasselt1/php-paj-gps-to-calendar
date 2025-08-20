# PAJ GPS to Calendar - Dokumentation

## Überblick

Das PAJ GPS to Calendar System ist ein PHP-Kommandozeilen-Tool, das automatisch Fahrzeugpositionen aus der PAJ Finder API abruft und Kalendereinträge erstellt, wenn sich Fahrzeuge in der Nähe bekannter Kundenstandorte befinden.

## Systemarchitektur

```
┌─────────────────┐    ┌──────────────────┐    ┌────────────────┐
│   PAJ Finder    │    │   CRM Database   │    │   Calendar     │
│      API        │    │  (Customer Locs) │    │   (CalDAV)     │
└─────────┬───────┘    └─────────┬────────┘    └───────┬────────┘
          │                      │                     │
          │                      │                     │
          └──────────────────────┼─────────────────────┘
                                 │
                    ┌────────────▼─────────────┐
                    │   PAJ GPS Calendar      │
                    │   PHP CLI Application   │
                    └─────────────────────────┘
```

## Hauptkomponenten

### 1. Services

#### ConfigService
- Lädt und verwaltet die YAML-Konfiguration
- Bietet get() Methode für verschachtelte Konfigurationswerte

#### PajApiService
- Authentifizierung mit PAJ Finder API
- Abrufen von Fahrzeugdaten und GPS-Positionen
- Historische Datenabfrage

#### DatabaseService
- Verbindung zur CRM-Datenbank via Doctrine DBAL
- Laden von Kundenstandorten
- Speichern von Kalendereinträgen für Duplikatsvermeidung

#### GeoService
- Distanzberechnung mit Haversine-Formel
- Reverse Geocoding (Koordinaten → Adresse)
- Geocoding (Adresse → Koordinaten)

#### CalendarService
- CalDAV Integration für Kalendererstellung
- iCal Event Generation
- Duplikatsprüfung

### 2. Commands

#### CheckCommand (`check`)
- Hauptfunktion für regelmäßige Ausführung
- Prüft aktuelle Fahrzeugpositionen
- Erstellt Kalendereinträge bei Nähe-Erkennung
- `--dry-run` Option für Tests

#### HistoryCommand (`history`)
- Analysiert historische GPS-Daten
- `--days` Option für Zeitraum
- `--vehicle-id` für spezifische Fahrzeuge

#### SetupCommand (`setup`)
- Interaktive Ersteinrichtung
- Datenbanktabellen erstellen
- Verbindungstests
- Beispieldaten einfügen

## Installation

### Voraussetzungen
- PHP 8.1 oder höher
- Composer
- MySQL/PostgreSQL für CRM-Datenbank
- CalDAV-fähiger Kalender (z.B. Google Calendar, Nextcloud)

### Automatische Installation

**Linux/macOS:**
```bash
chmod +x install.sh
./install.sh
```

**Windows:**
```cmd
install.bat
```

### Manuelle Installation

1. Dependencies installieren:
```bash
composer install --no-dev --optimize-autoloader
```

2. Konfiguration erstellen:
```bash
cp config/config.example.yaml config/config.yaml
```

3. Setup ausführen:
```bash
php bin/paj-gps-calendar setup
```

## Konfiguration

### PAJ API Konfiguration

```yaml
paj_api:
  username: "ihr_paj_username"
  password: "ihr_paj_passwort"
  base_url: "https://api.paj-gps.de"
```

**Wichtig:** Die PAJ API Endpoints können je nach Version variieren. Überprüfen Sie die aktuelle API-Dokumentation.

### Datenbank-Konfiguration

```yaml
database:
  driver: "mysql"        # mysql, postgresql, sqlite
  host: "localhost"
  port: 3306
  database: "crm_database"
  username: "db_user"
  password: "db_password"
```

### Kalender-Konfiguration

#### CalDAV (empfohlen)
```yaml
calendar:
  type: "caldav"
  caldav_url: "https://caldav.example.com/calendars/user/calendar/"
  username: "calendar_user"
  password: "calendar_password"
```

#### Google Calendar
```yaml
calendar:
  type: "google"
  google_client_id: "your_client_id"
  google_client_secret: "your_client_secret"
  google_refresh_token: "your_refresh_token"
```

### System-Einstellungen

```yaml
settings:
  proximity_threshold_meters: 500    # Entfernung für Nähe-Erkennung
  duplicate_check_hours: 24          # Duplikatsprüfung Zeitraum
  log_level: "info"                  # debug, info, warning, error
  log_file: "logs/application.log"
  timezone: "Europe/Berlin"
```

## Verwendung

### Grundlegende Befehle

```bash
# Aktuelle Positionen prüfen
php bin/paj-gps-calendar check

# Test-Modus (keine Kalendereinträge)
php bin/paj-gps-calendar check --dry-run

# Historische Daten (letzte 7 Tage)
php bin/paj-gps-calendar history --days=7

# Spezifisches Fahrzeug
php bin/paj-gps-calendar history --vehicle-id=12345

# Ersteinrichtung
php bin/paj-gps-calendar setup

# Hilfe anzeigen
php bin/paj-gps-calendar --help
```

### Cronjob Einrichtung

#### Linux/macOS
```bash
# Bearbeiten der Crontab
crontab -e

# Alle 15 Minuten ausführen
*/15 * * * * /usr/bin/php /pfad/zum/projekt/bin/paj-gps-calendar check >> /var/log/paj-gps-calendar.log 2>&1
```

#### Windows Aufgabenplanung
```cmd
# Aufgabe erstellen (alle 15 Minuten)
schtasks /create /tn "PAJ GPS Check" /tr "php C:\pfad\zum\projekt\bin\paj-gps-calendar check" /sc minute /mo 15 /ru SYSTEM

# Aufgabe anzeigen
schtasks /query /tn "PAJ GPS Check"

# Aufgabe löschen
schtasks /delete /tn "PAJ GPS Check"
```

## CRM-Integration

### Datenbankschema

Die Software erwartet eine Tabelle `customer_locations` in Ihrer CRM-Datenbank:

```sql
CREATE TABLE customer_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_name VARCHAR(255) NOT NULL,
    address VARCHAR(500) NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    -- weitere Felder optional
);
```

### Daten aus bestehendem CRM importieren

Beispiel für Import aus einer bestehenden Kundentabelle:

```sql
INSERT INTO customer_locations (customer_name, address, latitude, longitude)
SELECT 
    company_name,
    CONCAT(street, ', ', postal_code, ' ', city),
    0, 0  -- Koordinaten müssen separat geocodiert werden
FROM existing_customers 
WHERE street IS NOT NULL AND city IS NOT NULL;
```

### Geocoding bestehender Adressen

Die Software bietet automatisches Geocoding über OpenStreetMap Nominatim:

```php
$geo = new GeoService($config, $logger);
$coordinates = $geo->addressToCoordinates("Musterstraße 1, 12345 Musterstadt");
```

## Kalender-Integration

### CalDAV Setup

#### Nextcloud
```yaml
calendar:
  type: "caldav"
  caldav_url: "https://nextcloud.example.com/remote.php/dav/calendars/username/calendar-name/"
  username: "nextcloud_user"
  password: "app_password"  # App-spezifisches Passwort verwenden!
```

#### Google Calendar (über CalDAV)
```yaml
calendar:
  type: "caldav"
  caldav_url: "https://apidata.googleusercontent.com/caldav/v2/your-calendar-id/events/"
  username: "google_email@gmail.com"
  password: "app_password"  # App-Passwort erforderlich
```

#### Outlook/Exchange
```yaml
calendar:
  type: "caldav"
  caldav_url: "https://outlook.office365.com/calendar/"
  username: "office_email@company.com"
  password: "app_password"
```

### Kalendereintrag-Format

Die erstellten Kalendereinträge enthalten:

- **Titel:** "Fahrzeug [Name] bei [Kunde]"
- **Zeitpunkt:** Erkennungszeit
- **Dauer:** 1 Stunde (konfigurierbar)
- **Ort:** Kundenadresse
- **Beschreibung:** Detailinfo mit Entfernung, Geschwindigkeit etc.
- **Kategorien:** "PAJ-GPS", "Fahrzeugverfolgung"

## Logging und Monitoring

### Log-Konfiguration

```yaml
settings:
  log_level: "info"              # debug, info, warning, error
  log_file: "logs/application.log"
```

### Log-Level Bedeutung

- **debug:** Sehr detaillierte Informationen (GPS-Koordinaten, API-Calls)
- **info:** Normale Betriebsmeldungen (Fahrzeuge gefunden, Einträge erstellt)
- **warning:** Warnungen (Geocoding fehlgeschlagen, Duplikate)
- **error:** Fehler (API-Verbindung, Datenbank-Probleme)

### Monitoring-Befehle

```bash
# Aktuelle Logs anzeigen
tail -f logs/application.log

# Fehler der letzten Stunde
grep "ERROR\|CRITICAL" logs/application.log | grep "$(date '+%Y-%m-%d %H')"

# Statistiken
grep "Kalendereinträge erstellt" logs/application.log | wc -l
```

## Fehlerbehebung

### Häufige Probleme

#### 1. PAJ API Authentifizierung fehlgeschlagen
```
Fehler: PAJ API Authentifizierung fehlgeschlagen
```

**Lösung:**
- Benutzername/Passwort in `config/config.yaml` prüfen
- API-URL überprüfen (kann sich ändern)
- PAJ Finder Account-Status prüfen

#### 2. Datenbankverbindung fehlgeschlagen
```
SQLSTATE[HY000] [2002] Connection refused
```

**Lösung:**
- Database-Konfiguration prüfen
- Datenbank-Server erreichbar?
- Firewall-Einstellungen prüfen

#### 3. Kalender-Verbindung fehlgeschlagen
```
CalDAV Event konnte nicht erstellt werden: 401
```

**Lösung:**
- CalDAV-URL prüfen (korrekte Kalender-URL?)
- Zugangsdaten korrekt?
- App-spezifisches Passwort verwenden
- Kalender-Berechtigungen prüfen

#### 4. Geocoding Fehler
```
Reverse Geocoding fehlgeschlagen
```

**Lösung:**
- Internetverbindung prüfen
- Nominatim-Service verfügbar?
- Rate-Limiting beachten (max. 1 Request/Sekunde)

### Debug-Modus

Für detailliertes Debugging:

```yaml
settings:
  log_level: "debug"
```

```bash
# Debug-Modus mit Console-Output
php bin/paj-gps-calendar check --dry-run -vvv
```

### Performance-Optimierung

#### 1. Datenbank-Indizes
```sql
-- Wichtige Indizes für Performance
CREATE INDEX idx_coordinates ON customer_locations (latitude, longitude);
CREATE INDEX idx_vehicle_customer ON calendar_entries (vehicle_id, customer_location_id);
CREATE INDEX idx_created ON calendar_entries (created_at);
```

#### 2. Entfernungs-Schwellenwert anpassen
```yaml
settings:
  proximity_threshold_meters: 300  # Kleinerer Wert = weniger Berechnungen
```

#### 3. Cache für Geocoding
Die Software cached Geocoding-Ergebnisse automatisch in der Datenbank.

## Erweiterungen

### Zusätzliche Kalendertypen

Um weitere Kalendertypen zu unterstützen, implementieren Sie das `CalendarInterface`:

```php
interface CalendarInterface {
    public function createEntry(array $vehicle, array $location, float $distance): bool;
    public function hasRecentEntry(array $vehicle, array $location): bool;
    public function testConnection(): bool;
}
```

### Custom Notifications

Für E-Mail oder Slack-Benachrichtigungen erweitern Sie die `CalendarService`:

```php
public function createEntry(array $vehicle, array $location, float $distance): bool {
    // Kalendereintrag erstellen
    $success = parent::createEntry($vehicle, $location, $distance);
    
    if ($success) {
        // E-Mail senden
        $this->sendNotification($vehicle, $location);
    }
    
    return $success;
}
```

### Erweiterte Reporting

Für detaillierte Reports können Sie eigene Commands erstellen:

```php
class ReportCommand extends Command {
    protected function execute(InputInterface $input, OutputInterface $output): int {
        // Custom Reporting Logic
    }
}
```

## Sicherheit

### Konfigurationsdatei-Schutz
```bash
# Berechtigungen einschränken
chmod 600 config/config.yaml
```

### Datenbank-Sicherheit
- Separaten DB-User mit minimalen Rechten verwenden
- SSL-Verbindungen aktivieren
- Regelmäßige Backups

### API-Schlüssel-Rotation
- PAJ API Passwort regelmäßig ändern
- App-spezifische Passwörter für Kalender verwenden
- Zugriffslogs überwachen

## Support

### Logs sammeln
Bei Problemen sammeln Sie folgende Informationen:

```bash
# System-Info
php -v
composer show

# Logs
tail -100 logs/application.log

# Konfiguration (ohne Passwörter!)
grep -v password config/config.yaml
```

### Häufige Konfigurationsfehler
1. Falsche API-Endpoints
2. Fehlende Datenbank-Berechtigungen
3. Ungültige CalDAV-URLs
4. Zeitzone-Konflikte

Diese Dokumentation wird regelmäßig aktualisiert. Bei Fragen konsultieren Sie die Log-Dateien oder aktivieren Sie den Debug-Modus für detailliertere Informationen.
