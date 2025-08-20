# Windows Installation - Anleitung

## Problem mit install.bat?

Falls das Installationsskript hängt, versuchen Sie diese Alternativen:

### Option 1: Vereinfachtes Skript
```cmd
install-simple.bat
```

### Option 2: PowerShell Skript (empfohlen)
```powershell
PowerShell -ExecutionPolicy Bypass -File install.ps1
```

### Option 3: Manuelle Installation
```cmd
# 1. PHP und Composer prüfen
php -v
composer --version

# 2. Verzeichnisse erstellen
mkdir logs
mkdir config

# 3. Dependencies installieren
composer install --no-dev --optimize-autoloader

# 4. Konfiguration kopieren
copy config\config.example.yaml config\config.yaml

# 5. Testen
php bin\paj-gps-calendar --version
```

## Häufige Probleme

### 1. "Composer hängt"
- **Lösung**: Internet-Verbindung prüfen
- **Alternative**: `composer install --verbose` für Details

### 2. "PHP nicht gefunden"
- **Lösung**: PHP im PATH hinzufügen oder direkt mit Pfad:
  ```cmd
  C:\php\php.exe bin\paj-gps-calendar --version
  ```

### 3. "Composer nicht gefunden"
- **Lösung**: Composer installieren: https://getcomposer.org/download/
- **Alternative**: composer.phar verwenden:
  ```cmd
  php composer.phar install
  ```

### 4. "Permission denied"
- **Lösung**: Als Administrator ausführen
- **Command Prompt**: Rechtsklick → "Als Administrator ausführen"
- **PowerShell**: Rechtsklick → "Als Administrator ausführen"

## Nach erfolgreicher Installation

1. **Konfiguration bearbeiten:**
   ```cmd
   notepad config\config.yaml
   ```

2. **System testen:**
   ```cmd
   php bin\paj-gps-calendar test:paj
   php bin\paj-gps-calendar test:crm
   php bin\paj-gps-calendar test:calendar
   ```

3. **Erste Ausführung:**
   ```cmd
   php bin\paj-gps-calendar check --dry-run
   ```

4. **Automatisierung (Task Scheduler):**
   - Aufgabe erstellen für alle 3 Minuten
   - Programm: `C:\php\php.exe`
   - Argumente: `bin\paj-gps-calendar check`
   - Arbeitsverzeichnis: `C:\pfad\zu\php-paj-gps-to-calendar`
