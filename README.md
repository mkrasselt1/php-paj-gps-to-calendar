# PAJ GPS to Calendar Integration

Ein intelligentes PHP-Kommandozeilen-Tool, das Fahrzeugpositionen aus der PAJ Finder API abruft und automatisch Kalendereinträge erstellt, wenn sich Fahrzeuge bei Kunden aufhalten. Mit fortschrittlicher Motor-Erkennung und präziser Aufenthaltsdauer-Analyse.

## 🚀 Hauptfeatures

- ✅ **PAJ Finder API Integration** - Echte GPS-Daten mit Motor-Status und Bewegungsanalyse
- ✅ **Intelligente Besuchserkennung** - Unterscheidet zwischen "Vorbeifahren" und echten Besuchen
- ✅ **Motor-Status Erkennung** - Batterie-Trends und Geschwindigkeitsanalyse
- ✅ **Flexible CRM-Anbindung** - Sicherer Nur-Lese-Zugriff auf Ihre Kundendaten
- ✅ **CRM→PAJ Synchronisation** - Automatischer Abgleich Ihrer Kundenadressen
- ✅ **CalDAV Kalender-Integration** - Google Calendar, Nextcloud, Outlook
- ✅ **Detaillierte Besuchsinfos** - Kundendaten, Aufenthaltsdauer, Motor-Status im Kalender
- ✅ **Live-Monitoring** - Kontinuierliche Überwachung aktiver Besuche
- ✅ **100% CRM-sicher** - Niemals Schreibzugriff auf Ihr CRM-System
- ✅ **Plattformunabhängig** - Windows, Linux, macOS

## 🧠 Intelligente Besuchserkennung

Das System erkennt automatisch echte Kundenbesuche durch:

### ✨ **PAJ-basierte Analyse**
- **Motor-Status:** Batterie-Trends und Spannungsanalyse
- **Bewegungserkennung:** Präzise GPS-Tracking der letzten 30 Minuten
- **Stopp-Dauer:** Echte Aufenthaltszeit basierend auf PAJ-Daten
- **Geschwindigkeitsanalyse:** Unterscheidung zwischen Fahren und Stehen

### 🎯 **Mehrschichtige Besuchskriterien**
1. **Motor AUS + mindestens 5 Min stehend** = Definitiver Besuch
2. **Keine Bewegung + fallende Batterie** = Wahrscheinlicher Besuch  
3. **Längerer Stopp (10+ Min) ohne Bewegung** = Konservativer Besuch
4. **Fallback-Tracking** bei unvollständigen PAJ-Daten

**Resultat:** Keine "Vorbeifahren"-Einträge mehr! 🎯

## 📋 CRM-System Unterstützung

Das System verwendet einen **sicheren Nur-Lese-Adapter** und unterstützt verschiedene CRM-Systeme:

### ✅ **Unterstützte CRM-Systeme:**
- **MySQL/MariaDB basierte CRM-Systeme** (Standard-Adapter)
- **AFS-Software** (getestet und optimiert)
- **Beliebige SQL-Datenbanken** (Generic-Adapter)
- **Salesforce** (vorbereitet)
- **HubSpot** (vorbereitet) 
- **Pipedrive** (vorbereitet)

### 🔒 **100% CRM-sicher:**
- **Nur Lesezugriff** - Ihr CRM wird niemals verändert!
- **Separate Tracking-DB** für Visit-Historie (optional)
- **Keine eigene Datenbank erforderlich**
- **SQL-Injection geschützt** durch prepared statements

### 🔄 **CRM→PAJ Synchronisation:**
- **Automatischer Abgleich** Ihrer Kundenadressen zu PAJ
- **Nur eine Richtung:** CRM → PAJ (niemals umgekehrt!)
- **Batch-Synchronisation** für große Kundenstämme
- **Intelligente Duplikatserkennung**

## 🛠 Installation

### Automatische Installation

**Linux/macOS:**
```bash
git clone <repository>
cd php-paj-gps-to-calendar
chmod +x install.sh
./install.sh
```

**Windows:**
```cmd
git clone <repository>
cd php-paj-gps-to-calendar
install.bat
```

### Manuelle Installation

1. **PHP 8.1+ und Composer installieren**

2. **Dependencies installieren:**
```bash
composer install --no-dev --optimize-autoloader
```

3. **Konfiguration erstellen:**
```bash
cp config/config.example.yaml config/config.yaml
```

4. **Konfiguration anpassen** (siehe unten)

5. **Setup ausführen:**
```bash
php bin/paj-gps-calendar setup
```

## ⚙️ Konfiguration

### 🧙‍♂️ **Automatische Konfiguration (Empfohlen)**

```bash
# Interaktiver Konfigurationsassistent
php bin/paj-gps-calendar config
# Führt Sie durch die komplette Setup inkl. Verbindungstests!
```

### 1. **PAJ Finder API**

```yaml
paj_api:
  email: "ihr-paj-account@email.de"    # PAJ Account E-Mail
  password: "ihr-paj-passwort"          # PAJ Account Passwort  
  base_url: "https://connect.paj-gps.de"
```

### 2. **CRM-System Anbindung (Nur-Lese-Zugriff)**

```yaml
crm:
  adapter_type: "mysql"
  system_name: "Ihr CRM System"
  
  database:
    host: "crm-server.local"
    database: "crm_database"
    username: "readonly_user"    # ⚠️ Nur Lesezugriff!
    password: "secure_password"
  
  # SQL-Queries an Ihr CRM anpassen
  queries:
    find_customers_in_radius: |
      SELECT 
          id as customer_id,
          company_name as customer_name,
          CONCAT(street, ', ', postal_code, ' ', city) as full_address,
          latitude, longitude, phone, email, contact_person
      FROM customers 
      WHERE latitude BETWEEN :min_lat AND :max_lat
      AND longitude BETWEEN :min_lng AND :max_lng
      AND active = 1
```

### 3. **Intelligente Besuchserkennung**

```yaml
settings:
  proximity_threshold_meters: 150        # Entfernung zum Kunden (Meter)
  minimum_visit_duration_minutes: 5     # Mindestaufenthalt für Besuch
  duplicate_check_hours: 24             # Duplikatsprüfung (Stunden)
  log_level: "info"                     # debug, info, warning, error
  timezone: "Europe/Berlin"
```

### 4. **Kalender-Integration (CalDAV)**

```yaml
calendar:
  type: "caldav"
  caldav_url: "https://apidata.googleusercontent.com/caldav/v2/CALENDAR_ID/events/"
  username: "calendar-username"
  password: "app-specific-password"     # ⚠️ App-spezifisches Passwort verwenden!
```

## 🎯 Verwendung

### Erstmalige Einrichtung

```bash
# Interaktiver Konfigurationsassistent (empfohlen)
php bin/paj-gps-calendar config

# Konfigurationsdatei manuell erstellen
cp config/config.example.yaml config/config.yaml
# Anschließend config/config.yaml bearbeiten
```

### Verfügbare Befehle

#### Hauptfunktionen
```bash
# Aktuelle Positionen prüfen und Kalendereinträge erstellen
php bin/paj-gps-calendar check

# Test-Modus (keine Kalendereinträge)
php bin/paj-gps-calendar check --dry-run

# Alle Fahrzeuge prüfen (Standard)
php bin/paj-gps-calendar check --all

# Spezifisches Fahrzeug prüfen
php bin/paj-gps-calendar check --vehicle-id=12345

# Historische Daten analysieren (letzte 7 Tage)
php bin/paj-gps-calendar history --days=7

# Spezifisches Fahrzeug (Historie)
php bin/paj-gps-calendar history --vehicle-id=12345

# System-Setup und Tests
php bin/paj-gps-calendar setup
```

#### Test- und Diagnose-Befehle
```bash
# PAJ Finder API testen
php bin/paj-gps-calendar test:paj
# Zeigt alle verfügbaren Fahrzeuge und testet die API-Verbindung

# CRM-Datenbankverbindung testen  
php bin/paj-gps-calendar test:crm
# Testet die Datenbankverbindung und prüft Datenqualität

# Kalender (CalDAV) Verbindung testen
php bin/paj-gps-calendar test:calendar
# Testet die CalDAV-Verbindung, optional mit Test-Ereignis
```

#### Konfiguration
```bash
# Interaktiver Konfigurationsassistent
php bin/paj-gps-calendar config
# Führt Sie durch die komplette Systemkonfiguration mit Tests
```

### Automatisierung mit Cronjob

#### Linux/macOS
```bash
# Crontab bearbeiten
crontab -e

# Alle 15 Minuten ausführen
*/15 * * * * /usr/bin/php /pfad/zum/projekt/bin/paj-gps-calendar check
```

#### Windows Aufgabenplanung
```cmd
schtasks /create /tn "PAJ GPS Check" /tr "php C:\pfad\zum\projekt\bin\paj-gps-calendar check" /sc minute /mo 15
```

## 📅 Kalendereinträge

### Was wird im Kalender gespeichert?

**Titel:** "Fahrzeug [Fahrzeugname] bei [Kundenname]"

**Beschreibung:**
```
Fahrzeugbesuch bei Kunde erfasst

FAHRZEUG:
- Name: Lieferwagen 1
- ID: PAJ123456
- Entfernung zum Kunden: 50 Meter
- Geschwindigkeit: 0 km/h
- Erkannt am: 15.08.2024 14:30:00

KUNDE:
- Name: Musterfirma GmbH
- Kundennummer: CRM001
- Adresse: Musterstraße 1, 12345 Berlin
- Ansprechpartner: Max Mustermann
- Telefon: +49 30 12345678
- E-Mail: info@musterfirma.de

GPS-POSITION:
- Fahrzeug: 52.5200, 13.4050
- Kunde: 52.5198, 13.4045
```

**Zusätzlich:**
- Aufenthaltsdauer (wenn Fahrzeug sich wieder entfernt)
- Ort: Kundenadresse
- Kategorien: "PAJ-GPS", "Fahrzeugverfolgung"

## 🔧 Erweiterte Features

### Aufenthaltsdauer-Tracking

Das System erkennt automatisch:
- **Ankunftszeit** bei Kunden
- **Abfahrtszeit** wenn Fahrzeug sich entfernt
- **Aufenthaltsdauer** wird im Kalender aktualisiert

### Visit-Tracking Datenbank (Optional)

Für detaillierte Berichte können Sie eine separate Tracking-Datenbank konfigurieren:

```yaml
tracking_database:
  host: "localhost"
  database: "paj_tracking"
  username: "tracking_user"
  password: "tracking_password"
```

**Berichte verfügbar:**
- Besuchshäufigkeit pro Kunde
- Durchschnittliche Aufenthaltsdauer
- Besuchshistorie
- Fahrzeug-Statistiken

### Duplikatsvermeidung

Das System verhindert:
- Mehrfache Einträge für denselben Besuch
- Spam bei längeren Aufenthalten
- Konfigurierbare Wartezeit zwischen Einträgen

## 🛡️ Sicherheit & Performance

### Datenbank-Sicherheit
- **Nur Lesezugriff** auf CRM-Datenbank erforderlich
- Separater DB-User empfohlen
- SSL-Verbindungen unterstützt
- Keine Änderungen an bestehenden CRM-Daten

### Performance-Optimierung
- **Bounding Box** Algorithmus für GPS-Suchen
- Datenbankindizes für optimale Performance
- Caching von Geocoding-Ergebnissen
- Effiziente Haversine-Distanzberechnung

### Monitoring & Logging
```bash
# Logs überwachen
tail -f logs/application.log

# Fehleranalyse
grep "ERROR" logs/application.log | tail -20

# Debug-Modus
php bin/paj-gps-calendar check --dry-run -vvv
```

## 🔌 API-Integration

### PAJ Finder API
- Unterstützt aktuelle und historische GPS-Daten
- Automatische Authentifizierung
- Rate-Limiting beachtet
- Fehlerbehandlung und Retry-Logic

### CalDAV Kalender
- Google Calendar
- Nextcloud/ownCloud
- Outlook/Exchange
- Standard iCal Format

## 🆘 Fehlerbehebung

### Häufige Probleme

**CRM-Verbindung fehlgeschlagen:**
```bash
php bin/paj-gps-calendar setup  # Verbindung testen
```

**Keine GPS-Koordinaten:**
```sql
-- Prüfen welche Kunden Koordinaten haben
SELECT COUNT(*) FROM customers WHERE latitude IS NOT NULL;

-- Fehlende Koordinaten geocodieren
php bin/paj-gps-calendar geocode-customers
```

**Kalender-Einträge werden nicht erstellt:**
```yaml
# Debug-Modus in config.yaml aktivieren
settings:
  log_level: "debug"
```

## 📊 Beispiel-Berichte

Mit der Tracking-Datenbank können Sie detaillierte Berichte erstellen:

```sql
-- Top 10 besuchte Kunden
SELECT customer_name, COUNT(*) as visits 
FROM visit_tracking 
WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY customer_name 
ORDER BY visits DESC LIMIT 10;

-- Durchschnittliche Aufenthaltsdauer
SELECT customer_name, AVG(duration_minutes) as avg_duration
FROM visit_tracking 
WHERE duration_minutes > 0
GROUP BY customer_name
ORDER BY avg_duration DESC;
```

## 📝 Lizenz

MIT License - Siehe LICENSE Datei für Details.

## 🤝 Support

Bei Fragen oder Problemen:

1. **Logs prüfen:** `logs/application.log`
2. **Debug-Modus:** `log_level: "debug"`
3. **Verbindung testen:** `php bin/paj-gps-calendar setup`
4. **Dry-Run Modus:** `--dry-run` Option verwenden

Das System ist darauf ausgelegt, flexibel an verschiedene CRM-Systeme angepasst zu werden. Die SQL-Queries in der Konfiguration können vollständig an Ihre Datenbankstruktur angepasst werden.
