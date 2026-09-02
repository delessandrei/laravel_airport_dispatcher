<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Services\OpenSky;

use Carbon\CarbonImmutable;

/**
 * A single observed flight leg.
 *
 * OpenSky reports what actually flew, derived from ADS-B receivers — not a
 * published timetable. Times are therefore observations: firstSeen is when the
 * aircraft was first tracked after departure, lastSeen when tracking ended on
 * arrival.
 */
final readonly class Flight
{
    public function __construct(
        public string $icao24,
        public ?string $callsign,
        public ?string $departureAirport,
        public ?string $arrivalAirport,
        public CarbonImmutable $departureTime,
        public CarbonImmutable $arrivalTime,
        public bool $isDemo = false,
        // Confidence signals. OpenSky estimates both endpoint airports from
        // track data; these say how far the aircraft actually was from the
        // airport it was attributed to, and how many candidates competed.
        // A large vertical distance means it was still at altitude, so the
        // estimate is a guess rather than an observed landing or take-off.
        public ?int $departureHorizDistance = null,
        public ?int $departureVertDistance = null,
        public ?int $arrivalHorizDistance = null,
        public ?int $arrivalVertDistance = null,
        public ?int $departureCandidates = null,
        public ?int $arrivalCandidates = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromOpenSky(array $row): self
    {
        return new self(
            icao24: (string) ($row['icao24'] ?? ''),
            callsign: self::cleanCallsign($row['callsign'] ?? null),
            departureAirport: $row['estDepartureAirport'] ?? null,
            arrivalAirport: $row['estArrivalAirport'] ?? null,
            departureTime: CarbonImmutable::createFromTimestampUTC((int) ($row['firstSeen'] ?? 0)),
            arrivalTime: CarbonImmutable::createFromTimestampUTC((int) ($row['lastSeen'] ?? 0)),
            departureHorizDistance: self::intOrNull($row['estDepartureAirportHorizDistance'] ?? null),
            departureVertDistance: self::intOrNull($row['estDepartureAirportVertDistance'] ?? null),
            arrivalHorizDistance: self::intOrNull($row['estArrivalAirportHorizDistance'] ?? null),
            arrivalVertDistance: self::intOrNull($row['estArrivalAirportVertDistance'] ?? null),
            departureCandidates: self::intOrNull($row['departureAirportCandidatesCount'] ?? null),
            arrivalCandidates: self::intOrNull($row['arrivalAirportCandidatesCount'] ?? null),
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /** OpenSky pads callsigns to eight characters with trailing spaces. */
    private static function cleanCallsign(?string $callsign): ?string
    {
        $callsign = trim((string) $callsign);

        return $callsign === '' ? null : $callsign;
    }

    public function carrier(): ?string
    {
        return Airlines::nameFor($this->callsign);
    }

    public function duration(): string
    {
        $minutes = $this->departureTime->diffInMinutes($this->arrivalTime);

        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }

    /** The airport at the other end of the leg, relative to the one being viewed. */
    public function counterpart(string $viewedIcao): ?string
    {
        return strcasecmp((string) $this->arrivalAirport, $viewedIcao) === 0
            ? $this->departureAirport
            : $this->arrivalAirport;
    }
}
