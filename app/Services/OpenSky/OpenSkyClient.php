<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Services\OpenSky;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the OpenSky Network REST API.
 *
 * The arrival and departure endpoints stopped serving anonymous requests —
 * they answer 403 "You cannot access historical flights" — so an OAuth2 client
 * credentials pair is required. Register at opensky-network.org, create an API
 * client, then set OPENSKY_CLIENT_ID and OPENSKY_CLIENT_SECRET.
 *
 * Responses are NOT cached here. Flights are persisted in MongoDB by
 * FlightImporter, and that collection is what protects the credit allowance:
 * an airport and day already stored is never fetched again. Only the access
 * token and the remaining allowance are kept in Redis.
 */
class OpenSkyClient
{
    private const TOKEN_CACHE_KEY = 'opensky:access_token';

    private const RATE_LIMIT_CACHE_KEY = 'opensky:rate_limit_remaining';

    public function isConfigured(): bool
    {
        return filled(config('services.opensky.client_id'))
            && filled(config('services.opensky.client_secret'));
    }

    /**
     * @return array<int, Flight>
     */
    public function arrivals(string $icao, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->flights('arrival', $icao, $from, $to);
    }

    /**
     * @return array<int, Flight>
     */
    public function departures(string $icao, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->flights('departure', $icao, $from, $to);
    }

    /**
     * @return array<int, Flight>
     */
    private function flights(string $kind, string $icao, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->isConfigured()) {
            throw new OpenSkyException('OpenSky credentials are not configured.');
        }

        // OpenSky rejects windows wider than two days. Its own documentation
        // words the departures limit as "must cover more than two days", which
        // is a slip: a one day window is accepted, and this application has
        // never sent anything else.
        if ($from->diffInDays($to) > 2) {
            throw new OpenSkyException('The requested window exceeds the two day API limit.');
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->get(rtrim((string) config('services.opensky.base_url'), '/')."/flights/{$kind}", [
                'airport' => strtoupper($icao),
                'begin' => $from->getTimestamp(),
                'end' => $to->getTimestamp(),
            ]);

        $this->recordRateLimit($response);

        // A 404 simply means no flights were recorded in that window.
        $rows = $response->status() === 404 ? [] : $response->json();

        if ($response->failed() && $response->status() !== 404) {
            throw new OpenSkyException("OpenSky returned {$response->status()}: ".$response->body());
        }

        $rows ??= [];

        $flights = array_map(Flight::fromOpenSky(...), $rows);

        usort($flights, fn (Flight $a, Flight $b) => $a->arrivalTime <=> $b->arrivalTime);

        return $flights;
    }

    /**
     * OpenSky reports the remaining daily allowance on every response. Keep the
     * latest value so the interface can surface it and so a depleted quota is
     * visible in the logs before requests start failing.
     */
    private function recordRateLimit(\Illuminate\Http\Client\Response $response): void
    {
        $remaining = $response->header('X-Rate-Limit-Remaining');

        if ($remaining === '') {
            return;
        }

        Cache::put(self::RATE_LIMIT_CACHE_KEY, (int) $remaining, now()->addDay());

        if ((int) $remaining < 200) {
            Log::warning('OpenSky daily allowance nearly exhausted', ['remaining' => (int) $remaining]);
        }
    }

    public function creditsRemaining(): ?int
    {
        // Laravel's Redis store keeps numeric values unserialised so that
        // increment() works, which means they come back as strings.
        $value = Cache::get(self::RATE_LIMIT_CACHE_KEY);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Access tokens are short lived; cache them just short of their own expiry
     * so a request never travels with a token that expires in flight.
     */
    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()
                ->timeout(20)
                ->post((string) config('services.opensky.token_url'), [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('services.opensky.client_id'),
                    'client_secret' => config('services.opensky.client_secret'),
                ]);
        } catch (ConnectionException $e) {
            throw new OpenSkyException('Could not reach the OpenSky auth server: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            Log::warning('OpenSky authentication failed', ['status' => $response->status()]);

            throw new OpenSkyException("OpenSky authentication failed with status {$response->status()}.");
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 300);

        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $expiresIn - 60));

        return $token;
    }
}
