<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Services\OpenSky;

/**
 * Maps the three-letter ICAO prefix of a callsign to an airline name.
 *
 * OpenSky reports callsigns, not airlines, so this lookup is what turns
 * "WZZ3421" into "Wizz Air" in the interface. Unknown prefixes are left alone.
 */
final class Airlines
{
    public const MAP = [
        'ROT' => 'Tarom', 'WZZ' => 'Wizz Air', 'RYR' => 'Ryanair', 'EZY' => 'easyJet',
        'DLH' => 'Lufthansa', 'BAW' => 'British Airways', 'AFR' => 'Air France',
        'KLM' => 'KLM', 'SWR' => 'Swiss', 'AUA' => 'Austrian', 'TAP' => 'TAP Air Portugal',
        'IBE' => 'Iberia', 'VLG' => 'Vueling', 'EWG' => 'Eurowings', 'THY' => 'Turkish Airlines',
        'UAE' => 'Emirates', 'QTR' => 'Qatar Airways', 'AEE' => 'Aegean', 'LOT' => 'LOT',
        'CSA' => 'Czech Airlines', 'FDX' => 'FedEx', 'DHK' => 'DHL Air', 'BCS' => 'European Air Transport',
    ];

    public static function nameFor(?string $callsign): ?string
    {
        if ($callsign === null || strlen($callsign) < 3) {
            return null;
        }

        return self::MAP[strtoupper(substr($callsign, 0, 3))] ?? null;
    }

    /** @return array<int, string> */
    public static function prefixes(): array
    {
        return array_keys(self::MAP);
    }
}
