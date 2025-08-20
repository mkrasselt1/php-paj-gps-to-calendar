<?php

namespace PajGpsCalendar\Adapters;

use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\GeoService;
use Monolog\Logger;

/**
 * Beispiel-Adapter für verschiedene CRM-Systeme
 * Zeigt, wie Adapter für spezifische CRM-Systeme erstellt werden können
 */

/**
 * SalesForce CRM Adapter
 */
class SalesForceCrmAdapter extends MySqlCrmAdapter
{
    public function getSystemName(): string
    {
        return 'Salesforce CRM';
    }

    protected function getDefaultRadiusQuery(): string
    {
        return "
            SELECT 
                Id as customer_id,
                Name as customer_name,
                BillingStreet as street,
                BillingCity as city,
                BillingPostalCode as postal_code,
                BillingCountry as country,
                CONCAT(BillingStreet, ', ', BillingPostalCode, ' ', BillingCity) as full_address,
                Custom_Latitude__c as latitude,
                Custom_Longitude__c as longitude,
                Phone as phone,
                Website as email,
                Custom_Contact_Person__c as contact_person,
                Description as notes
            FROM Account 
            WHERE Custom_Latitude__c BETWEEN :min_lat AND :max_lat
            AND Custom_Longitude__c BETWEEN :min_lng AND :max_lng
            AND Custom_Latitude__c IS NOT NULL 
            AND Custom_Longitude__c IS NOT NULL
            AND IsActive = TRUE
        ";
    }

    protected function getDefaultFieldMapping(): array
    {
        return [
            'customer_id' => 'Id',
            'customer_name' => 'Name',
            'full_address' => 'full_address',
            'street' => 'BillingStreet',
            'city' => 'BillingCity',
            'postal_code' => 'BillingPostalCode',
            'country' => 'BillingCountry',
            'latitude' => 'Custom_Latitude__c',
            'longitude' => 'Custom_Longitude__c',
            'phone' => 'Phone',
            'email' => 'Website',
            'contact_person' => 'Custom_Contact_Person__c',
            'notes' => 'Description'
        ];
    }
}

/**
 * HubSpot CRM Adapter
 */
class HubSpotCrmAdapter extends MySqlCrmAdapter
{
    public function getSystemName(): string
    {
        return 'HubSpot CRM';
    }

    protected function getDefaultRadiusQuery(): string
    {
        return "
            SELECT 
                hs_object_id as customer_id,
                name as customer_name,
                address as street,
                city,
                zip as postal_code,
                country,
                CONCAT(address, ', ', zip, ' ', city) as full_address,
                hs_lat as latitude,
                hs_lng as longitude,
                phone as phone,
                domain as email,
                hs_primary_contact as contact_person,
                description as notes
            FROM companies 
            WHERE hs_lat BETWEEN :min_lat AND :max_lat
            AND hs_lng BETWEEN :min_lng AND :max_lng
            AND hs_lat IS NOT NULL 
            AND hs_lng IS NOT NULL
            AND lifecyclestage != 'marketingqualifiedlead'
        ";
    }
}

/**
 * Pipedrive CRM Adapter
 */
class PipedriveCrmAdapter extends MySqlCrmAdapter
{
    public function getSystemName(): string
    {
        return 'Pipedrive CRM';
    }

    protected function getDefaultRadiusQuery(): string
    {
        return "
            SELECT 
                id as customer_id,
                name as customer_name,
                address as full_address,
                address_street_number as street,
                address_locality as city,
                address_postal_code as postal_code,
                address_country as country,
                custom_latitude as latitude,
                custom_longitude as longitude,
                phone as phone,
                email as email,
                owner_name as contact_person,
                notes
            FROM organizations 
            WHERE custom_latitude BETWEEN :min_lat AND :max_lat
            AND custom_longitude BETWEEN :min_lng AND :max_lng
            AND custom_latitude IS NOT NULL 
            AND custom_longitude IS NOT NULL
            AND active_flag = 1
        ";
    }
}

/**
 * Sugar CRM Adapter
 */
class SugarCrmAdapter extends MySqlCrmAdapter
{
    public function getSystemName(): string
    {
        return 'Sugar CRM';
    }

    protected function getDefaultRadiusQuery(): string
    {
        return "
            SELECT 
                id as customer_id,
                name as customer_name,
                billing_address_street as street,
                billing_address_city as city,
                billing_address_postalcode as postal_code,
                billing_address_country as country,
                CONCAT(billing_address_street, ', ', billing_address_postalcode, ' ', billing_address_city) as full_address,
                custom_latitude_c as latitude,
                custom_longitude_c as longitude,
                phone_office as phone,
                email1 as email,
                assigned_user_name as contact_person,
                description as notes
            FROM accounts 
            WHERE custom_latitude_c BETWEEN :min_lat AND :max_lat
            AND custom_longitude_c BETWEEN :min_lng AND :max_lng
            AND custom_latitude_c IS NOT NULL 
            AND custom_longitude_c IS NOT NULL
            AND deleted = 0
        ";
    }
}

/**
 * Generischer SQL Adapter
 * Kann für beliebige CRM-Systeme konfiguriert werden
 */
class GenericSqlCrmAdapter extends MySqlCrmAdapter
{
    public function getSystemName(): string
    {
        return $this->config->get('crm.system_name', 'Generisches CRM');
    }

    public function findCustomersInRadius(float $latitude, float $longitude, int $radiusMeters): array
    {
        // Verwendet vollständig konfigurierbare SQL-Queries
        $sql = $this->config->get('crm.queries.find_customers_in_radius');
        
        if (!$sql) {
            throw new \Exception('CRM Query "find_customers_in_radius" nicht konfiguriert');
        }
        
        return parent::findCustomersInRadius($latitude, $longitude, $radiusMeters);
    }
}
