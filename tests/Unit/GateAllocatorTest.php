<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace Tests\Unit;

use App\Services\Gates\GateAllocator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The allocation rules, tested without a database or a clock.
 */
class GateAllocatorTest extends TestCase
{
    private GateAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allocator = new GateAllocator;
    }

    public function test_a_flight_takes_the_first_gate_in_order(): void
    {
        $result = $this->allocator->allocate(
            [$this->demand('F1', '10:00')],
            [$this->gate('A2'), $this->gate('A1')],
        );

        $this->assertSame('A1', $result['allocations'][0]['gate_code']);
        $this->assertSame([], $result['unallocated']);
    }

    public function test_gate_numbers_sort_naturally_not_alphabetically(): void
    {
        $result = $this->allocator->allocate(
            [$this->demand('F1', '10:00')],
            [$this->gate('A10'), $this->gate('A2')],
        );

        $this->assertSame('A2', $result['allocations'][0]['gate_code'], 'A2 must come before A10.');
    }

    public function test_overlapping_flights_get_different_gates(): void
    {
        $result = $this->allocator->allocate(
            [$this->demand('F1', '10:00'), $this->demand('F2', '11:00')],
            [$this->gate('A1'), $this->gate('A2')],
        );

        $gates = array_column($result['allocations'], 'gate_code');

        $this->assertCount(2, array_unique($gates), 'Two flights at once cannot share one gate.');
    }

    public function test_flights_that_do_not_overlap_share_a_gate(): void
    {
        $result = $this->allocator->allocate(
            [$this->demand('F1', '10:00'), $this->demand('F2', '14:00')],
            [$this->gate('A1'), $this->gate('A2')],
        );

        $gates = array_column($result['allocations'], 'gate_code');

        $this->assertSame(['A1', 'A1'], $gates, 'A free gate should be reused rather than spreading flights out.');
    }

    public function test_a_flight_starting_exactly_when_another_ends_reuses_the_gate(): void
    {
        // Intervals are half-open, so 10:00-11:30 and 11:30-13:00 do not clash.
        $result = $this->allocator->allocate(
            [$this->demand('F1', '10:00'), $this->demand('F2', '11:30')],
            [$this->gate('A1'), $this->gate('A2')],
        );

        $this->assertSame(['A1', 'A1'], array_column($result['allocations'], 'gate_code'));
    }

    public function test_a_closed_gate_is_skipped(): void
    {
        $result = $this->allocator->allocate(
            [$this->demand('F1', '10:00')],
            [$this->gate('A1'), $this->gate('A2')],
            [$this->closure('A1', '09:00', '12:00', 'maintenance')],
        );

        $this->assertSame('A2', $result['allocations'][0]['gate_code']);
    }

    public function test_a_closure_overlapping_only_partly_still_blocks(): void
    {
        // Closure ends at 10:30, the flight needs 10:00-11:30. One minute of
        // overlap is enough: the gate is unusable for that flight.
        $result = $this->allocator->allocate(
            [$this->demand('F1', '10:00')],
            [$this->gate('A1'), $this->gate('A2')],
            [$this->closure('A1', '08:00', '10:30')],
        );

        $this->assertSame('A2', $result['allocations'][0]['gate_code']);
    }

    public function test_a_closure_touching_the_edge_does_not_block(): void
    {
        // Ends exactly when the flight starts, so the gate is free again.
        $result = $this->allocator->allocate(
            [$this->demand('F1', '10:00')],
            [$this->gate('A1')],
            [$this->closure('A1', '08:00', '10:00')],
        );

        $this->assertSame('A1', $result['allocations'][0]['gate_code']);
    }

    public function test_a_closure_with_no_end_blocks_indefinitely(): void
    {
        $result = $this->allocator->allocate(
            [$this->demand('F1', '23:00')],
            [$this->gate('A1')],
            [$this->closure('A1', '08:00', null)],
        );

        $this->assertSame([], $result['allocations']);
        $this->assertSame(GateAllocator::ALL_GATES_CLOSED, $result['unallocated']['F1']);
    }

    public function test_an_airport_with_no_gates_reports_it(): void
    {
        $result = $this->allocator->allocate([$this->demand('F1', '10:00')], []);

        $this->assertSame(GateAllocator::NO_GATES, $result['unallocated']['F1']);
    }

    public function test_a_full_airport_is_distinguished_from_a_closed_one(): void
    {
        $result = $this->allocator->allocate(
            [$this->demand('F1', '10:00'), $this->demand('F2', '10:30')],
            [$this->gate('A1')],
        );

        $this->assertSame(GateAllocator::NO_FREE_GATE, $result['unallocated']['F2']);
        $this->assertSame(1, count($result['allocations']));
    }

    public function test_existing_allocations_are_respected(): void
    {
        $result = $this->allocator->allocate(
            [$this->demand('F2', '10:00')],
            [$this->gate('A1'), $this->gate('A2')],
            closures: [],
            existing: [[
                'flight_id' => 'F1', 'gate_code' => 'A1', 'gate_terminal' => 'T1',
                'from' => $this->at('10:00'), 'until' => $this->at('11:30'),
            ]],
        );

        $this->assertSame('A2', $result['allocations'][0]['gate_code'], 'A gate held by an earlier run stays held.');
    }

    public function test_the_same_input_always_gives_the_same_result(): void
    {
        $gates = [$this->gate('A3'), $this->gate('A1'), $this->gate('A2')];
        $demands = [$this->demand('F2', '10:15'), $this->demand('F1', '10:00'), $this->demand('F3', '10:30')];

        $first = $this->allocator->allocate($demands, $gates);
        $second = $this->allocator->allocate(array_reverse($demands), $gates);

        $this->assertSame(
            array_map(fn (array $a) => [$a['flight_id'], $a['gate_code']], $first['allocations']),
            array_map(fn (array $a) => [$a['flight_id'], $a['gate_code']], $second['allocations']),
            'Row order out of the database must not change the outcome.',
        );
    }

    public function test_the_window_carried_into_the_allocation_is_the_one_requested(): void
    {
        $result = $this->allocator->allocate([$this->demand('F1', '10:00')], [$this->gate('A1', 'T2')]);

        $allocation = $result['allocations'][0];

        $this->assertSame('T2', $allocation['gate_terminal']);
        $this->assertTrue($allocation['from']->equalTo($this->at('10:00')));
        $this->assertTrue($allocation['until']->equalTo($this->at('11:30')));
    }

    /** @return array<string, mixed> */
    private function demand(string $id, string $time, int $minutes = 90): array
    {
        return [
            'flight_id' => $id,
            'from' => $this->at($time),
            'until' => $this->at($time)->addMinutes($minutes),
        ];
    }

    /** @return array<string, mixed> */
    private function closure(string $gate, ?string $from, ?string $until, string $reason = ''): array
    {
        return [
            'gate_code' => $gate,
            'from' => $from === null ? null : $this->at($from),
            'until' => $until === null ? null : $this->at($until),
            'reason' => $reason,
        ];
    }

    /** @return array<string, string> */
    private function gate(string $code, string $terminal = 'T1'): array
    {
        return ['code' => $code, 'terminal' => $terminal, 'type' => 'jetbridge'];
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-01 '.$time, 'UTC');
    }
}
