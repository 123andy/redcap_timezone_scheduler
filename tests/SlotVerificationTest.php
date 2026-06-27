<?php namespace Stanford\TimezoneScheduler;

// Path to redcap_connect.php (tests/ -> module -> modules -> www)
require_once __DIR__ . '/../../../redcap_connect.php';

use DateTime;

/**
 * Unit tests for the pure slot-verification logic (computeSlotVerification()).
 *
 * These exercise the cross-slot-database collision fix: appointments and slots are
 * keyed by a composite of "<slot_project_id>:<slot_id>" so that two different slot
 * databases sharing the same record id (slot_id) do not clobber one another.
 *
 * computeSlotVerification() is pure (no REDCap::getData / no project context), so no
 * test projects or PIDs are required -- only the harness bootstrap.
 *
 * Run (from the ExternalModules framework dir, path from get-phpunit-path.php):
 *   php <phpunit> --no-configuration <module>/tests/SlotVerificationTest.php
 */
class SlotVerificationTest extends \ExternalModules\ModuleBaseTest
{
    /** A future, reserved slot pointing back at the given appointment record. */
    private function reservedSlot($slot_id, $record, $event_id, $instance) {
        return [
            'slot_id'              => $slot_id,
            'title'                => 'Test Slot',
            'date'                 => '2099-12-31',
            'time'                 => '09:00',
            'reserved_ts'          => '2025-01-01 00:00:00',
            'source_project_id'    => 999,
            'source_project_title' => 'Study',
            'source_record_id'     => $record,
            'source_field'         => 'appt',
            'source_event_id'      => $event_id,
            'source_instance_id'   => $instance,
            'source_record_url'    => 'http://example/record',
            'project_filter'       => '',
            'slot_filter'          => '',
        ];
    }

    /** A normalized appointment reference, as produced by buildSlotVerificationResults(). */
    private function apptRef($slot_project_id, $slot_id, $record, $event_id, $instance, $config_key, $field = 'appt') {
        return [
            'slot_project_id'      => $slot_project_id,
            'appt_slot_id'         => $slot_id,
            'appt_project_id'      => 999,
            'appt_field'           => $field,
            'appt_record'          => $record,
            'appt_event_id'        => $event_id,
            'appt_repeat_instance' => $instance,
            'config_key'           => $config_key,
        ];
    }

    /**
     * Two different slot DBs each have a slot_id of 5, correctly reserved by different
     * appointment records. Before the fix (keying by slot_id alone) one row overwrote
     * the other and a false "claimed by more than one appointment" error was raised.
     */
    function testDifferentSlotDbsWithSameSlotIdDoNotCollide()
    {
        $configs = [
            'apptA-100' => ['slot-project-id' => 40, 'appt-field' => 'apptA'],
            'apptB-100' => ['slot-project-id' => 41, 'appt-field' => 'apptB'],
        ];
        $appt_refs = [
            $this->apptRef(40, 5, 1, 100, 1, 'apptA-100', 'apptA'),
            $this->apptRef(41, 5, 2, 100, 1, 'apptB-100', 'apptB'),
        ];
        $slots_by_config_key = [
            'apptA-100' => [5 => $this->reservedSlot(5, 1, 100, 1)],
            'apptB-100' => [5 => $this->reservedSlot(5, 2, 100, 1)],
        ];

        $results = $this->module->computeSlotVerification($configs, $appt_refs, $slots_by_config_key, new DateTime('now'));

        // Both slots survive under distinct composite keys (no overwrite)
        $this->assertCount(2, $results);
        $this->assertArrayHasKey('40:5', $results);
        $this->assertArrayHasKey('41:5', $results);

        // Each is correctly matched to its own appointment -> no errors
        $this->assertSame('', $results['40:5']['errors'], 'DB 40 slot 5 should verify cleanly');
        $this->assertSame('', $results['41:5']['errors'], 'DB 41 slot 5 should verify cleanly');

        // The two DBs kept their own reservation data (proves no clobber)
        $this->assertEquals(1, $results['40:5']['source_record_id']);
        $this->assertEquals(2, $results['41:5']['source_record_id']);
        $this->assertSame('Reserved', $results['40:5']['status']);
        $this->assertSame('Reserved', $results['41:5']['status']);
    }

    /**
     * Regression guard: a genuine double-booking *within the same* slot DB (two records
     * claiming the same slot) must still be flagged. The composite key only separates
     * different DBs; it must not mask real conflicts inside one DB.
     */
    function testSameSlotDbDoubleBookingIsStillFlagged()
    {
        $configs = [
            'apptA-100' => ['slot-project-id' => 40, 'appt-field' => 'apptA'],
        ];
        $appt_refs = [
            $this->apptRef(40, 5, 1, 100, 1, 'apptA-100', 'apptA'),
            $this->apptRef(40, 5, 2, 100, 1, 'apptA-100', 'apptA'), // second record claims the same slot
        ];
        $slots_by_config_key = [
            'apptA-100' => [5 => $this->reservedSlot(5, 1, 100, 1)],
        ];

        $results = $this->module->computeSlotVerification($configs, $appt_refs, $slots_by_config_key, new DateTime('now'));

        $this->assertArrayHasKey('40:5', $results);
        $this->assertStringContainsString('more than one', $results['40:5']['errors']);
    }

    /**
     * Sanity check on the status logic: an unreserved slot reads as "Available".
     */
    function testUnreservedSlotIsAvailable()
    {
        $configs = [
            'apptA-100' => ['slot-project-id' => 40, 'appt-field' => 'apptA'],
        ];
        $slot = $this->reservedSlot(7, 1, 100, 1);
        $slot['reserved_ts'] = null;
        $slot['source_record_id'] = null;
        $slots_by_config_key = ['apptA-100' => [7 => $slot]];

        $results = $this->module->computeSlotVerification($configs, [], $slots_by_config_key, new DateTime('now'));

        $this->assertArrayHasKey('40:7', $results);
        $this->assertSame('Available', $results['40:7']['status']);
        $this->assertSame('', $results['40:7']['errors']);
    }

    /** A future, unreserved slot. */
    private function availableSlot($slot_id, $date = '2099-12-31') {
        $slot = $this->reservedSlot($slot_id, 1, 100, 1);
        $slot['date'] = $date;
        $slot['reserved_ts'] = null;
        $slot['source_record_id'] = null;
        return $slot;
    }

    function testSummarizeSlotsCountsAndUtilization()
    {
        $now = new DateTime('now');
        $slots = [
            1 => $this->reservedSlot(1, 10, 100, 1),                 // booked, future
            2 => $this->reservedSlot(2, 11, 100, 1),                 // booked, future
            3 => $this->availableSlot(3),                            // available, future
            4 => $this->availableSlot(4, '2000-01-01'),             // available, past
            5 => array_merge($this->reservedSlot(5, 1, 100, 1),     // cancelled (reserved, no record)
                             ['source_record_id' => null]),
        ];

        $s = $this->module->summarizeSlots($slots, $now);

        $this->assertSame(5, $s['total']);
        $this->assertSame(2, $s['booked']);
        $this->assertSame(2, $s['available']);
        $this->assertSame(1, $s['available_future']);
        $this->assertSame(1, $s['cancelled']);
        $this->assertSame(1, $s['past']);
        $this->assertSame(4, $s['future']);
        // utilization = booked / (booked + available) = 2 / (2 + 2) = 50%
        $this->assertSame(50, $s['percent_used']);
    }

    function testSummarizeSlotsHandlesEmpty()
    {
        $s = $this->module->summarizeSlots([], new DateTime('now'));
        $this->assertSame(0, $s['total']);
        $this->assertSame(0, $s['percent_used']);
    }

    function testEscapeVerificationRowsEscapesFreeTextFields()
    {
        $rows = [
            '40:5' => [
                'title'          => '<script>alert(1)</script>',
                'note'           => 'A & B "quoted"',
                'slot_filter'    => '<img src=x onerror=alert(1)>',
                'project_filter' => 'plain',
                // these must NOT be altered (used by buttons / follow-up actions)
                'slot_url'       => 'https://x/redcap_v15/DataEntry/index.php?pid=40&id=5&page=slots',
                'slot_id'        => 5,
                'status'         => 'Reserved',
            ],
        ];

        $out = $this->module->escapeVerificationRows($rows);

        $this->assertStringNotContainsString('<script>', $out['40:5']['title']);
        $this->assertStringContainsString('&lt;script&gt;', $out['40:5']['title']);
        $this->assertStringNotContainsString('<img', $out['40:5']['slot_filter']);
        $this->assertStringContainsString('&amp;', $out['40:5']['note']);
        $this->assertStringContainsString('&quot;', $out['40:5']['note']);
        // unchanged: a plain value and the non-escaped fields (URL ampersands intact)
        $this->assertSame('plain', $out['40:5']['project_filter']);
        $this->assertStringContainsString('pid=40&id=5', $out['40:5']['slot_url']);
        $this->assertSame(5, $out['40:5']['slot_id']);
    }
}
