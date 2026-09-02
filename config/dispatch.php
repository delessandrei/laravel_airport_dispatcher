<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

return [

    // Cron expressions. Read through config() rather than env() directly, so
    // the schedule survives config:cache.
    'cron' => env('DISPATCH_CRON', '0 * * * *'),
    'allocate_cron' => env('DISPATCH_ALLOCATE_CRON', 'everyThirtySeconds'),
    'validate_cron' => env('DISPATCH_VALIDATE_CRON', 'everyThirtySeconds'),
    'report_cron' => env('DISPATCH_REPORT_CRON', '5 * * * *'),

    'gate' => [

        // How long a flight holds a gate.
        'occupancy_minutes' => (int) env('GATE_OCCUPANCY_MINUTES', 90),

        /*
         * Where the occupancy window sits relative to T, the moment the flight
         * touches this airport: landing for an arrival, take-off for a departure.
         *
         * An arriving aircraft occupies its gate after landing, so its offset is
         * zero. A departing one occupies its gate before take-off, so its offset
         * is negative — set it to 0 to read the requirement literally instead,
         * with the gate held for the 90 minutes following departure.
         */
        'offset_arrival_minutes' => (int) env('GATE_OCCUPANCY_OFFSET_ARRIVAL_MINUTES', 0),
        'offset_departure_minutes' => (int) env('GATE_OCCUPANCY_OFFSET_DEPARTURE_MINUTES', -90),

    ],

    'validate' => [

        /*
         * The validator looks at allocations overlapping a window around now,
         * not at every allocation ever made. Grace reaches back over what has
         * just finished; horizon reaches forward to conflicts about to bite.
         */
        'grace_minutes' => (int) env('GATE_VALIDATE_GRACE_MINUTES', 15),
        'horizon_minutes' => (int) env('GATE_VALIDATE_HORIZON_MINUTES', 90),

    ],

];
