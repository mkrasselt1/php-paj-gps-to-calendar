#!/bin/bash

# ============================================================================
# PAJ GPS to Calendar - Tägliche rückwärts GPS-Analyse
# ============================================================================
#
# Dieses Script führt eine systematische Bulk-Analyse historischer GPS-Daten
# durch, indem es Tag für Tag rückwärts geht und das History-Kommando ausführt.
#
# Verwendung:
#   ./scripts/daily_analysis_backward.sh
#
# Konfiguration:
#   - START_DATE: Von diesem Datum beginnen (Format: YYYY-MM-DD)
#   - END_DATE: Bis zu diesem Datum gehen (Format: YYYY-MM-DD)
#   - DRY_RUN: true = nur testen, false = echte Kalendereinträge erstellen
#   - FORCE_UPDATE: true = bestehende Einträge aktualisieren
#   - DELAY_SECONDS: Pause zwischen Tagen in Sekunden
#
# Beispiel:
#   START_DATE="2025-08-08" END_DATE="2024-08-18" ./scripts/daily_analysis_backward.sh
#
# ============================================================================

# Konfiguration (kann über Umgebungsvariablen überschrieben werden)
START_DATE="${START_DATE:-2025-08-08}"
END_DATE="${END_DATE:-2024-08-18}"
DRY_RUN="${DRY_RUN:-false}"  # Auf false setzen für echte Ausführung
FORCE_UPDATE="${FORCE_UPDATE:-true}"  # Auf true setzen um bestehende Einträge zu aktualisieren
DELAY_SECONDS="${DELAY_SECONDS:-1}"

# Basis-Verzeichnis (automatisch erkennen oder über Parameter setzen)
if [ -z "$BASE_DIR" ]; then
    # Versuche das Basis-Verzeichnis automatisch zu finden
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    BASE_DIR="$(dirname "$SCRIPT_DIR")"
fi

cd "$BASE_DIR"

echo "🚀 Starte tägliche rückwärts GPS-Analyse"
echo "📅 Von: $START_DATE bis: $END_DATE"
echo "⚙️  Modus: $([ "$DRY_RUN" = true ] && echo "DRY-RUN" || echo "LIVE")"
echo "🔄 Force-Update: $([ "$FORCE_UPDATE" = true ] && echo "AN" || echo "AUS")"
echo ""

# Datum-Konvertierung für verschiedene Systeme
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS
    current_date=$(date -j -f "%Y-%m-%d" "$START_DATE" "+%Y-%m-%d")
    end_timestamp=$(date -j -f "%Y-%m-%d" "$END_DATE" "+%s")
else
    # Linux
    current_date="$START_DATE"
    end_timestamp=$(date -d "$END_DATE" "+%s")
fi

day_counter=1
success_count=0
error_count=0

# Tag für Tag rückwärts durchgehen
while true; do
    # Aktuelles Datum formatieren
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        formatted_date=$(date -j -f "%Y-%m-%d" "$current_date" "+%d.%m.%Y")
        current_timestamp=$(date -j -f "%Y-%m-%d" "$current_date" "+%s")
    else
        # Linux  
        formatted_date=$(date -d "$current_date" "+%d.%m.%Y")
        current_timestamp=$(date -d "$current_date" "+%s")
    fi
    
    # Prüfe ob wir das End-Datum erreicht haben
    if [ "$current_timestamp" -lt "$end_timestamp" ]; then
        break
    fi
    
    echo "📍 Tag $day_counter: Analysiere $formatted_date"
    
    # Kommando zusammenbauen
    cmd="php bin/paj-gps-calendar history --from=\"$formatted_date\" --to=\"$formatted_date\""
    if [ "$DRY_RUN" = true ]; then
        cmd="$cmd --dry-run"
    fi
    if [ "$FORCE_UPDATE" = true ]; then
        cmd="$cmd --force-update"
    fi
    
    # Kommando ausführen
    echo "  🔧 Führe aus: $cmd"
    if eval "$cmd"; then
        echo "  ✅ Erfolgreich"
        ((success_count++))
    else
        exit_code=$?
        echo "  ❌ KRITISCHER FEHLER (Exit Code: $exit_code)"
        echo ""
        echo "🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨"
        echo "🚨                    KRITISCHER FEHLER AUFGETRETEN                    🚨"
        echo "🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨🚨"
        echo ""
        echo "💥 Das PHP-Kommando ist mit einem Fehler beendet worden!"
        echo "📅 Datum: $formatted_date (Tag $day_counter)"
        echo "🔧 Kommando: $cmd"
        echo "📋 Exit Code: $exit_code"
        echo ""
        echo "🛑 DAS SCRIPT WIRD GESTOPPT UM WEITERE FEHLER ZU VERMEIDEN!"
        echo ""
        echo "🔍 Mögliche Ursachen:"
        echo "   - CalDAV Authentifizierung fehlgeschlagen"
        echo "   - Ungültiges iCalendar Format"
        echo "   - Server-Verbindungsprobleme"
        echo "   - Zu große Event-Beschreibungen"
        echo "   - Ungültige Zeichen in Kundendaten"
        echo ""
        echo "📋 Bisher verarbeitete Tage: $((day_counter - 1))"
        echo "✅ Erfolgreiche Tage: $success_count"
        echo "❌ Fehlgeschlagene Tage: 1 (aktueller Tag)"
        echo ""
        echo "💡 Prüfen Sie die Logs für detaillierte Fehlermeldungen:"
        echo "   tail -f logs/application.log"
        echo ""
        exit $exit_code
    fi
    
    # Einen Tag zurückgehen
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        current_date=$(date -j -v-1d -f "%Y-%m-%d" "$current_date" "+%Y-%m-%d")
    else
        # Linux
        current_date=$(date -d "$current_date - 1 day" "+%Y-%m-%d")
    fi
    
    ((day_counter++))
    
    # Kurze Pause
    if [ "$DELAY_SECONDS" -gt 0 ]; then
        echo "  ⏳ Warte $DELAY_SECONDS Sekunden..."
        sleep "$DELAY_SECONDS"
    fi
    
    echo ""
done

# Zusammenfassung
total_days=$((day_counter - 1))
echo "📊 ANALYSE ABGESCHLOSSEN"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📅 Zeitraum: $START_DATE bis $END_DATE"
echo "📈 Tage gesamt: $total_days"
echo "✅ Erfolgreich: $success_count"
echo "❌ Fehlgeschlagen: $error_count"

if [ "$DRY_RUN" = true ]; then
    echo "⚠️  DRY-RUN Modus war aktiviert - keine Kalendereinträge wurden erstellt!"
else
    echo "📅 Kalendereinträge wurden in den NextCloud Kalender geschrieben"
fi

echo ""
echo "🎉 Bulk-Analyse beendet!"
