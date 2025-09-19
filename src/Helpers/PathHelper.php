<?php

namespace PajGpsCalendar\Helpers;

class PathHelper
{
    /**
     * Konvertiert relative Pfade zu absoluten Pfaden, Windows-kompatibel
     * 
     * @param string $path Der zu konvertierende Pfad
     * @param string $basePath Basispfad (standardmäßig Projekt-Root)
     * @return string Absoluter Pfad
     */
    public static function resolveProjectPath(string $path, string $basePath = null): string
    {
        // Prüfe ob Pfad bereits absolut ist
        if (self::isAbsolutePath($path)) {
            return $path;
        }
        
        // Bestimme Projekt-Root wenn nicht angegeben
        if ($basePath === null) {
            $basePath = self::getProjectRoot();
        }
        
        // Konvertiere Pfad-Separatoren für aktuelles OS
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        
        return $basePath . DIRECTORY_SEPARATOR . $path;
    }
    
    /**
     * Prüft ob ein Pfad absolut ist (Windows und Unix)
     * 
     * @param string $path Der zu prüfende Pfad
     * @return bool True wenn absolut, false wenn relativ
     */
    public static function isAbsolutePath(string $path): bool
    {
        // Unix/Linux: beginnt mit /
        if (strpos($path, '/') === 0) {
            return true;
        }
        
        // Windows: beginnt mit C:\ oder ähnlich
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return true;
        }
        
        // UNC-Pfad: \\server\share
        if (strpos($path, '\\\\') === 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Ermittelt den Projekt-Root-Pfad
     * 
     * @return string Absoluter Pfad zum Projekt-Root
     */
    public static function getProjectRoot(): string
    {
        // Versuche zuerst mit realpath
        $projectRoot = realpath(__DIR__ . '/../../');
        
        if ($projectRoot) {
            return $projectRoot;
        }
        
        // Fallback für Systeme ohne realpath-Unterstützung
        return dirname(dirname(__DIR__));
    }
    
    /**
     * Erstellt ein Verzeichnis falls es nicht existiert (Windows-kompatibel)
     * 
     * @param string $directory Das zu erstellende Verzeichnis
     * @param int $permissions Die Berechtigungen (Unix-Systeme)
     * @return bool True wenn erfolgreich oder bereits vorhanden
     */
    public static function ensureDirectoryExists(string $directory, int $permissions = 0755): bool
    {
        if (is_dir($directory)) {
            return true;
        }
        
        return mkdir($directory, $permissions, true);
    }
}
