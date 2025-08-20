# PAJ GPS to Calendar - CRM-Anpassung

Diese Datei zeigt, wie Sie das System an verschiedene CRM-Systeme anpassen können.

## Schritt 1: Identifizieren Sie Ihre CRM-Tabellenstruktur

Zunächst müssen Sie herausfinden, wie Ihre Kundendaten in der Datenbank gespeichert sind:

```sql
-- Zeige alle Tabellen
SHOW TABLES LIKE '%kunde%' OR SHOW TABLES LIKE '%customer%' OR SHOW TABLES LIKE '%client%';

-- Beschreibe die Struktur der Kundentabelle
DESCRIBE customers;  -- Ersetzen Sie 'customers' durch Ihren Tabellennamen
```

## Schritt 2: Prüfen Sie vorhandene Adressdaten

```sql
-- Prüfen Sie, welche Adressfelder vorhanden sind
SELECT COLUMN_NAME 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'customers'  -- Ihr Tabellenname
AND (COLUMN_NAME LIKE '%address%' 
     OR COLUMN_NAME LIKE '%street%' 
     OR COLUMN_NAME LIKE '%city%'
     OR COLUMN_NAME LIKE '%lat%'
     OR COLUMN_NAME LIKE '%lng%'
     OR COLUMN_NAME LIKE '%longitude%');

-- Beispiel-Datensatz anzeigen
SELECT * FROM customers LIMIT 5;
```

## Schritt 3: GPS-Koordinaten hinzufügen (falls nicht vorhanden)

```sql
-- GPS-Felder zur bestehenden Tabelle hinzufügen
ALTER TABLE customers 
ADD COLUMN latitude DECIMAL(10, 8) NULL,
ADD COLUMN longitude DECIMAL(11, 8) NULL;

-- Index für bessere Performance
CREATE INDEX idx_gps_coordinates ON customers (latitude, longitude);
```

## Schritt 4: Konfiguration anpassen

Bearbeiten Sie `config/config.yaml` entsprechend Ihrer Tabellenstruktur:

### Beispiel 1: Standard CRM-Tabelle
```yaml
crm:
  adapter_type: "mysql"
  database:
    host: "localhost"
    database: "mein_crm"
    username: "readonly_user"
    password: "secure_password"
  
  queries:
    find_customers_in_radius: |
      SELECT 
          id as customer_id,
          company_name as customer_name,
          CONCAT(street, ', ', postal_code, ' ', city) as full_address,
          street,
          city,
          postal_code,
          'Deutschland' as country,
          latitude,
          longitude,
          phone,
          email,
          contact_person,
          notes
      FROM customers 
      WHERE latitude BETWEEN :min_lat AND :max_lat
      AND longitude BETWEEN :min_lng AND :max_lng
      AND latitude IS NOT NULL 
      AND longitude IS NOT NULL
      AND active = 1
```

### Beispiel 2: CRM mit getrennten Adresstabellen
```yaml
crm:
  queries:
    find_customers_in_radius: |
      SELECT 
          c.id as customer_id,
          c.name as customer_name,
          CONCAT(a.street, ', ', a.postal_code, ' ', a.city) as full_address,
          a.street,
          a.city,
          a.postal_code,
          a.country,
          a.latitude,
          a.longitude,
          c.phone,
          c.email,
          c.contact_person,
          c.notes
      FROM customers c
      JOIN addresses a ON c.address_id = a.id
      WHERE a.latitude BETWEEN :min_lat AND :max_lat
      AND a.longitude BETWEEN :min_lng AND :max_lng
      AND a.latitude IS NOT NULL 
      AND a.longitude IS NOT NULL
      AND c.status = 'active'
```

### Beispiel 3: Salesforce-ähnliche Struktur
```yaml
crm:
  queries:
    find_customers_in_radius: |
      SELECT 
          Id as customer_id,
          Name as customer_name,
          CONCAT(BillingStreet, ', ', BillingPostalCode, ' ', BillingCity) as full_address,
          BillingStreet as street,
          BillingCity as city,
          BillingPostalCode as postal_code,
          BillingCountry as country,
          Custom_Latitude__c as latitude,
          Custom_Longitude__c as longitude,
          Phone as phone,
          Website as email,
          Owner.Name as contact_person,
          Description as notes
      FROM Account 
      WHERE Custom_Latitude__c BETWEEN :min_lat AND :max_lat
      AND Custom_Longitude__c BETWEEN :min_lng AND :max_lng
      AND Custom_Latitude__c IS NOT NULL 
      AND IsDeleted = FALSE
```

## Schritt 5: Feldmapping anpassen

Falls Ihre Feldnamen anders sind, passen Sie das Mapping an:

```yaml
crm:
  field_mapping:
    customer_id: "id"              # Ihr Feld für Kunden-ID
    customer_name: "company_name"  # Ihr Feld für Kundenname
    full_address: "full_address"   # Zusammengesetzte Adresse
    street: "street_address"       # Ihr Straßenfeld
    city: "city_name"              # Ihr Stadtfeld
    postal_code: "zip_code"        # Ihr PLZ-Feld
    country: "country_code"        # Ihr Länder-Feld
    latitude: "lat"                # Ihr Latitude-Feld
    longitude: "lng"               # Ihr Longitude-Feld
    phone: "phone_number"          # Ihr Telefon-Feld
    email: "email_address"         # Ihr E-Mail-Feld
    contact_person: "contact"      # Ihr Kontaktperson-Feld
    notes: "comments"              # Ihr Notizen-Feld
```

## Schritt 6: Geocoding für bestehende Adressen

Falls Sie noch keine GPS-Koordinaten haben, können Sie diese automatisch generieren:

```sql
-- Zeige Adressen ohne GPS-Koordinaten
SELECT id, company_name, CONCAT(street, ', ', postal_code, ' ', city) as address
FROM customers 
WHERE (latitude IS NULL OR longitude IS NULL)
AND street IS NOT NULL 
AND city IS NOT NULL
LIMIT 10;
```

Das System kann automatisch Geocoding durchführen. Erstellen Sie ein kleines PHP-Skript:

```php
<?php
// geocode-addresses.php
require_once 'vendor/autoload.php';

use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\GeoService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$config = new ConfigService();
$logger = new Logger('geocoder');
$logger->pushHandler(new StreamHandler('php://stdout'));
$geo = new GeoService($config, $logger);

// Ihre Datenbankverbindung
$pdo = new PDO("mysql:host=localhost;dbname=mein_crm", "user", "password");

// Kunden ohne GPS-Koordinaten laden
$stmt = $pdo->query("SELECT id, street, city, postal_code FROM customers WHERE latitude IS NULL LIMIT 100");

while ($row = $stmt->fetch()) {
    $address = "{$row['street']}, {$row['postal_code']} {$row['city']}";
    echo "Geocoding: {$address}\n";
    
    $coords = $geo->addressToCoordinates($address);
    
    if ($coords) {
        $updateStmt = $pdo->prepare("UPDATE customers SET latitude = ?, longitude = ? WHERE id = ?");
        $updateStmt->execute([$coords['latitude'], $coords['longitude'], $row['id']]);
        echo "  -> {$coords['latitude']}, {$coords['longitude']}\n";
    } else {
        echo "  -> Nicht gefunden\n";
    }
    
    // Rate Limiting für OpenStreetMap
    sleep(1);
}
```

## Schritt 7: Testen

```bash
# CRM-Verbindung testen
php bin/paj-gps-calendar setup

# Trockenlauf
php bin/paj-gps-calendar check --dry-run

# Debug-Modus
php bin/paj-gps-calendar check --dry-run -vvv
```

## Häufige CRM-Systeme

### vtiger CRM
```yaml
crm:
  queries:
    find_customers_in_radius: |
      SELECT 
          accountid as customer_id,
          accountname as customer_name,
          CONCAT(bill_street, ', ', bill_code, ' ', bill_city) as full_address,
          bill_street as street,
          bill_city as city,
          bill_code as postal_code,
          bill_country as country,
          custom_latitude as latitude,
          custom_longitude as longitude,
          phone,
          email1 as email,
          '' as contact_person,
          description as notes
      FROM vtiger_account 
      WHERE custom_latitude BETWEEN :min_lat AND :max_lat
```

### SuiteCRM
```yaml
crm:
  queries:
    find_customers_in_radius: |
      SELECT 
          id as customer_id,
          name as customer_name,
          CONCAT(billing_address_street, ', ', billing_address_postalcode, ' ', billing_address_city) as full_address,
          billing_address_street as street,
          billing_address_city as city,
          billing_address_postalcode as postal_code,
          billing_address_country as country,
          custom_latitude_c as latitude,
          custom_longitude_c as longitude,
          phone_office as phone,
          email1 as email,
          assigned_user_name as contact_person,
          description as notes
      FROM accounts 
      WHERE custom_latitude_c BETWEEN :min_lat AND :max_lat
```

### Eigenes CRM-System
Passen Sie die SQL-Queries einfach an Ihre Tabellenstruktur an. Die einzigen Anforderungen sind:
- GPS-Koordinaten (latitude/longitude) müssen vorhanden sein
- Die Query muss die im Feldmapping definierten Spalten zurückgeben
- Nur Lesezugriff auf die Datenbank erforderlich

Das System ist sehr flexibel und kann an praktisch jede SQL-Datenbank angepasst werden!
