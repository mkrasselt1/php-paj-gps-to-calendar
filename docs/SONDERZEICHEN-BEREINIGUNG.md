# Sonderzeichen-Bereinigung in Firmennamen

## Übersicht

Ab sofort werden problematische Sonderzeichen in Firmennamen automatisch bereinigt, bevor sie in Kalendertiteln und -beschreibungen verwendet werden. Dies verhindert Darstellungsprobleme und Fehler in verschiedenen Kalendersystemen.

## Was wird bereinigt

### Entfernte Zeichen
- **Backticks** (`)
- **Verschiedene Anführungszeichen** (", ", ", ‚, ', ', „)
- **Trademark/Copyright-Zeichen** (™, ®, ©)
- **Mathematische Symbole** (×, ÷, ±)
- **Währungszeichen** (¢, £, ¥, €, ¤)
- **Typografische Zeichen** (…, –, —, °, §)
- **Diakritische Zeichen** (´, ¨, ¯, ¸)
- **Sonstige problematische Zeichen** (¡, ¿, ¦, etc.)

### Behaltene Zeichen
- **Deutsche Umlaute** (Ä, Ö, Ü, ä, ö, ü, ß)
- **Standard ASCII-Zeichen** (A-Z, a-z, 0-9)
- **Grundlegende Satzzeichen** (. , ; : ! ? - ( ) [ ] { })
- **Mathematische Grundzeichen** (+ - * / = % &)
- **Leerzeichen** (normalisiert)

## Beispiele

| Original | Bereinigt |
|----------|-----------|
| `Firma \`mit\` Backticks` | `Firma mit Backticks` |
| `Café "Zur Sonne"` | `Café Zur Sonne` |
| `Firma™ mit ® Zeichen` | `Firma mit Zeichen` |
| `Test–Firma—GmbH` | `TestFirmaGmbH` |
| `Müller °C Solutions` | `Müller C Solutions` |
| `Büro Ä Ö Ü ß` | `Büro Ä Ö Ü ß` _(bleibt unverändert)_ |

## Technische Details

### Automatische Bereinigung
Die Bereinigung erfolgt automatisch in der `MySqlCrmAdapter`-Klasse in der `normalizeCustomerData()`-Methode. Alle CRM-Daten werden vor der Verwendung bereinigt.

### Verwendete Klasse
- **Datei**: `src/Helpers/TextHelper.php`
- **Methode**: `TextHelper::sanitizeCompanyName()`

### Erweiterte Funktionen
Die `TextHelper`-Klasse bietet auch eine allgemeine `sanitizeText()`-Methode für andere Textbereinigungen.

## Auswirkungen

### Positive Effekte
- ✅ Keine Darstellungsprobleme in Kalendern mehr
- ✅ Kompatibilität mit allen Kalendersystemen
- ✅ Bessere Lesbarkeit der Kalendereinträge
- ✅ Vermeidung von iCal-Format-Fehlern

### Wichtige Hinweise
- Deutsche Umlaute und andere regionale Zeichen bleiben erhalten
- Die Bereinigung ist "verlustfrei" - keine wichtigen Informationen gehen verloren
- Mehrfache Leerzeichen werden zu einfachen Leerzeichen normalisiert
- Führende und nachfolgende Leerzeichen werden entfernt

## Konfiguration

Die Sonderzeichen-Bereinigung ist standardmäßig aktiviert und benötigt keine zusätzliche Konfiguration. Sie kann über den Code angepasst werden, falls andere Bereinigungsregeln gewünscht sind.
