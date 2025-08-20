<?php

namespace PajGpsCalendar\Adapters;

/**
 * Interface für CRM-System Adapter
 * Ermöglicht die Anbindung verschiedener CRM-Systeme
 */
interface CrmAdapterInterface
{
    /**
     * Findet alle Kunden in einem bestimmten Radius um eine GPS-Position
     * 
     * @param float $latitude GPS Breitengrad
     * @param float $longitude GPS Längengrad  
     * @param int $radiusMeters Suchradius in Metern
     * @return array Array von Kunden-Objekten
     */
    public function findCustomersInRadius(float $latitude, float $longitude, int $radiusMeters): array;
    
    /**
     * Holt Kundendetails anhand der Kunden-ID
     * 
     * @param mixed $customerId Kunden-ID (kann string oder int sein)
     * @return array|null Kunden-Details oder null wenn nicht gefunden
     */
    public function getCustomerById($customerId): ?array;
    
    /**
     * Sucht Kunden anhand des Namens
     * 
     * @param string $customerName Kundenname (kann teilweise sein)
     * @return array Array von gefundenen Kunden
     */
    public function findCustomersByName(string $customerName): array;
    
    /**
     * Testet die Verbindung zum CRM-System
     * 
     * @return bool true wenn Verbindung erfolgreich
     */
    public function testConnection(): bool;
    
    /**
     * Gibt den Namen/Typ des CRM-Systems zurück
     * 
     * @return string Name des CRM-Systems
     */
    public function getSystemName(): string;
}
