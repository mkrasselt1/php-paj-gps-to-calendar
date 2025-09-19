<?php

namespace PajGpsCalendar\Helpers;

/**
 * Text-Hilfsfunktionen für die Bereinigung von Eingaben
 */
class TextHelper
{
    /**
     * Bereinigt Firmennamen von problematischen Sonderzeichen
     * Behält nur ASCII-Zeichen und regionale Sonderzeichen (Umlaute)
     * 
     * @param string $companyName Der zu bereinigende Firmenname
     * @return string Der bereinigte Firmenname
     */
    public static function sanitizeCompanyName(string $companyName): string
    {
        if (empty($companyName)) {
            return $companyName;
        }
        
        // Trimme Whitespace
        $companyName = trim($companyName);
        
        // Entferne oder ersetze problematische Zeichen für Kalender und iCal
        // Backticks und andere potentiell problematische Zeichen entfernen
        $problematicChars = [
            '`',        // Backtick
            '´',        // Akut
            '"',        // Anführungszeichen
            '"',        // Geschweifte Anführungszeichen
            '"',        // Geschweifte Anführungszeichen
            '‚',        // Einfache Anführungszeichen unten
            chr(8216),  // Einfache Anführungszeichen oben links
            chr(8217),  // Einfache Anführungszeichen oben rechts
            '„',        // Deutsche Anführungszeichen unten
            '…',        // Auslassungspunkte
            '–',        // En-Dash
            '—',        // Em-Dash
            '™',        // Trademark
            '®',        // Registered
            '©',        // Copyright
            '§',        // Paragraph
            '°',        // Grad-Zeichen (oft problematisch)
            '¡',        // Umgekehrtes Ausrufezeichen
            '¿',        // Umgekehrtes Fragezeichen
            '×',        // Multiplikationszeichen
            '÷',        // Divisionszeichen
            '±',        // Plus-Minus
            '¢',        // Cent
            '£',        // Pfund
            '¥',        // Yen
            '€',        // Euro (könnte problematisch sein)
            '¤',        // Allgemeines Währungszeichen
            '¦',        // Unterbrochener Balken
            '¨',        // Trema
            '¯',        // Makron
            '¸',        // Cedille
            '¹',        // Hochgestellt 1
            '²',        // Hochgestellt 2
            '³',        // Hochgestellt 3
            '¼',        // Ein Viertel
            '½',        // Ein Halb
            '¾',        // Drei Viertel
        ];
        
        // Entferne problematische Zeichen
        $companyName = str_replace($problematicChars, '', $companyName);
        
        // Doppelte Leerzeichen durch einfache ersetzen
        $companyName = preg_replace('/\s+/', ' ', $companyName);
        
        // Nochmals trimmen nach der Bereinigung
        $companyName = trim($companyName);
        
        // Steuerzeichen entfernen (außer normalen Leerzeichen)
        $companyName = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $companyName);
        
        return $companyName;
    }
    
    /**
     * Bereinigt allgemeine Texte von Steuerzeichen und normalisiert Whitespace
     * 
     * @param string $text Der zu bereinigende Text
     * @return string Der bereinigte Text
     */
    public static function sanitizeText(string $text): string
    {
        if (empty($text)) {
            return $text;
        }
        
        // Trimme Whitespace
        $text = trim($text);
        
        // Steuerzeichen entfernen (außer normalen Leerzeichen, Tabs und Zeilenwechseln)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Doppelte Leerzeichen durch einfache ersetzen
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Nochmals trimmen nach der Bereinigung
        $text = trim($text);
        
        return $text;
    }
}
