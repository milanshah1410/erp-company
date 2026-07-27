<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Port;
use App\Models\ShippingLine;
use Illuminate\Database\Seeder;

/**
 * Baseline Import & Export masters for a new tenant: India plus its main
 * trading partners, the major Indian gateway ports (sea / air / ICD) and the
 * ocean carriers that call at them. Runs inside a TenantContext (called from
 * TenantDatabaseSeeder) so rows are tenant-stamped, and is idempotent — a
 * tenant is free to edit or deactivate anything seeded here.
 */
class EximMasterSeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['code' => 'IN', 'iso3' => 'IND', 'name' => 'India', 'currency_code' => 'INR', 'dial_code' => '+91', 'region' => 'Asia'],
            ['code' => 'CN', 'iso3' => 'CHN', 'name' => 'China', 'currency_code' => 'CNY', 'dial_code' => '+86', 'region' => 'Asia'],
            ['code' => 'BD', 'iso3' => 'BGD', 'name' => 'Bangladesh', 'currency_code' => 'BDT', 'dial_code' => '+880', 'region' => 'Asia'],
            ['code' => 'VN', 'iso3' => 'VNM', 'name' => 'Vietnam', 'currency_code' => 'VND', 'dial_code' => '+84', 'region' => 'Asia'],
            ['code' => 'SG', 'iso3' => 'SGP', 'name' => 'Singapore', 'currency_code' => 'SGD', 'dial_code' => '+65', 'region' => 'Asia'],
            ['code' => 'AE', 'iso3' => 'ARE', 'name' => 'United Arab Emirates', 'currency_code' => 'AED', 'dial_code' => '+971', 'region' => 'Middle East'],
            ['code' => 'SA', 'iso3' => 'SAU', 'name' => 'Saudi Arabia', 'currency_code' => 'SAR', 'dial_code' => '+966', 'region' => 'Middle East'],
            ['code' => 'GB', 'iso3' => 'GBR', 'name' => 'United Kingdom', 'currency_code' => 'GBP', 'dial_code' => '+44', 'region' => 'Europe'],
            ['code' => 'DE', 'iso3' => 'DEU', 'name' => 'Germany', 'currency_code' => 'EUR', 'dial_code' => '+49', 'region' => 'Europe'],
            ['code' => 'IT', 'iso3' => 'ITA', 'name' => 'Italy', 'currency_code' => 'EUR', 'dial_code' => '+39', 'region' => 'Europe'],
            ['code' => 'NL', 'iso3' => 'NLD', 'name' => 'Netherlands', 'currency_code' => 'EUR', 'dial_code' => '+31', 'region' => 'Europe'],
            ['code' => 'US', 'iso3' => 'USA', 'name' => 'United States', 'currency_code' => 'USD', 'dial_code' => '+1', 'region' => 'Americas'],
            ['code' => 'CA', 'iso3' => 'CAN', 'name' => 'Canada', 'currency_code' => 'CAD', 'dial_code' => '+1', 'region' => 'Americas'],
            ['code' => 'AU', 'iso3' => 'AUS', 'name' => 'Australia', 'currency_code' => 'AUD', 'dial_code' => '+61', 'region' => 'Oceania'],
            ['code' => 'ZA', 'iso3' => 'ZAF', 'name' => 'South Africa', 'currency_code' => 'ZAR', 'dial_code' => '+27', 'region' => 'Africa'],
        ];
        foreach ($countries as $c) {
            Country::updateOrCreate(['code' => $c['code']], $c + ['is_active' => true]);
        }

        $india = Country::where('code', 'IN')->first();

        // UN/LOCODE-coded Indian gateways: the ports most import/export cargo
        // actually moves through.
        $ports = [
            ['code' => 'INNSA', 'name' => 'Jawaharlal Nehru (Nhava Sheva)', 'type' => 'sea', 'city' => 'Navi Mumbai', 'state' => 'Maharashtra'],
            ['code' => 'INMUN', 'name' => 'Mundra', 'type' => 'sea', 'city' => 'Mundra', 'state' => 'Gujarat'],
            ['code' => 'INIXY', 'name' => 'Kandla (Deendayal)', 'type' => 'sea', 'city' => 'Kandla', 'state' => 'Gujarat'],
            ['code' => 'INMAA', 'name' => 'Chennai', 'type' => 'sea', 'city' => 'Chennai', 'state' => 'Tamil Nadu'],
            ['code' => 'INCOK', 'name' => 'Cochin', 'type' => 'sea', 'city' => 'Kochi', 'state' => 'Kerala'],
            ['code' => 'INCCU', 'name' => 'Kolkata', 'type' => 'sea', 'city' => 'Kolkata', 'state' => 'West Bengal'],
            ['code' => 'INBOM', 'name' => 'Mumbai (Chhatrapati Shivaji) Airport', 'type' => 'air', 'city' => 'Mumbai', 'state' => 'Maharashtra'],
            ['code' => 'INDEL', 'name' => 'Delhi (Indira Gandhi) Airport', 'type' => 'air', 'city' => 'New Delhi', 'state' => 'Delhi'],
            ['code' => 'INAMD', 'name' => 'Ahmedabad (Sardar Vallabhbhai Patel) Airport', 'type' => 'air', 'city' => 'Ahmedabad', 'state' => 'Gujarat'],
            ['code' => 'INBLR', 'name' => 'Bengaluru (Kempegowda) Airport', 'type' => 'air', 'city' => 'Bengaluru', 'state' => 'Karnataka'],
            ['code' => 'INTKD', 'name' => 'Tughlakabad ICD', 'type' => 'icd', 'city' => 'New Delhi', 'state' => 'Delhi'],
            ['code' => 'INSAU', 'name' => 'Sanand ICD', 'type' => 'icd', 'city' => 'Sanand', 'state' => 'Gujarat'],
        ];
        foreach ($ports as $p) {
            Port::updateOrCreate(['code' => $p['code']], $p + [
                'country_id' => $india?->id,
                'is_active' => true,
            ]);
        }

        $lines = [
            ['code' => 'MAERSK', 'name' => 'Maersk Line', 'scac_code' => 'MAEU'],
            ['code' => 'MSC', 'name' => 'Mediterranean Shipping Company', 'scac_code' => 'MSCU'],
            ['code' => 'CMACGM', 'name' => 'CMA CGM', 'scac_code' => 'CMDU'],
            ['code' => 'HAPAG', 'name' => 'Hapag-Lloyd', 'scac_code' => 'HLCU'],
            ['code' => 'ONE', 'name' => 'Ocean Network Express', 'scac_code' => 'ONEY'],
        ];
        foreach ($lines as $l) {
            ShippingLine::updateOrCreate(['code' => $l['code']], $l + ['is_active' => true]);
        }
    }
}
