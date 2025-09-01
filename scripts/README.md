# Scripts Directory

Dieses Verzeichnis enthält Hilfs-Scripts für die PAJ GPS to Calendar Integration.

## 📜 Verfügbare Scripts

### `daily_analysis_backward.sh`
**Bash-Script für Bulk-Analyse historischer GPS-Daten**

Führt eine systematische Analyse historischer GPS-Daten durch, indem es Tag für Tag rückwärts geht und das `history` Kommando ausführt.

#### Verwendung:
```bash
# Standard-Konfiguration verwenden
./scripts/daily_analysis_backward.sh

# Mit eigenen Parametern
START_DATE="2025-08-08" END_DATE="2024-08-18" DRY_RUN=true ./scripts/daily_analysis_backward.sh
```

#### Konfiguration:
- `START_DATE`: Von diesem Datum beginnen (Format: YYYY-MM-DD)
- `END_DATE`: Bis zu diesem Datum gehen (Format: YYYY-MM-DD)
- `DRY_RUN`: `true` = nur testen, `false` = echte Kalendereinträge erstellen
- `FORCE_UPDATE`: `true` = bestehende Einträge aktualisieren
- `DELAY_SECONDS`: Pause zwischen Tagen in Sekunden

#### Beispiel-Ausgabe:
```
🚀 Starte tägliche rückwärts GPS-Analyse
📅 Von: 2025-08-08 bis 2024-08-18
⚙️  Modus: LIVE
🔄 Force-Update: AN

📍 Tag 1: Analysiere 08.08.2025
  🔧 Führe aus: php bin/paj-gps-calendar history --from="08.08.2025" --to="08.08.2025" --force-update
  ✅ Erfolgreich
```

### `daily_analysis_backward.php`
**PHP-Script für Bulk-Analyse historischer GPS-Daten**

Alternative PHP-Implementierung der Bulk-Analyse mit Symfony Console Integration.

#### Verwendung:
```bash
php scripts/daily_analysis_backward.php
```

## 🔒 Sicherheit

Diese Scripts enthalten **keine sensiblen Daten** und können bedenkenlos ins Git-Repository aufgenommen werden.

## 📋 Voraussetzungen

- Bash (für `.sh` Scripts)
- PHP (für `.php` Scripts)
- Ausführbare Berechtigungen: `chmod +x scripts/*.sh`
