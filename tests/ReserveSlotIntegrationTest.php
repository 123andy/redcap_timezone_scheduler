<?php namespace Stanford\TimezoneScheduler;

// Path to redcap_connect.php (tests/ -> module -> modules -> www)
require_once __DIR__ . '/../../../redcap_connect.php';

use DateTime;
use ExternalModules\ExternalModules;

/**
 * Integration tests for reserveSlot() lock handling and orphan rollback.
 *
 * These hit real REDCap data, so they run only when the example projects are present and
 * the module is configured (otherwise they skip). They use the dev example appointment
 * project (pid 47) + slot database (pid 40) and a throwaway record that is removed in
 * tearDown, so they are idempotent.
 *
 * Run: docker compose exec web sh /var/www/html/modules/timezone_scheduler_v0.0.0/run-tests.sh
 */
class ReserveSlotIntegrationTest extends \ExternalModules\ModuleBaseTest
{
    const APPT_PID    = 47;
    const SLOT_PID    = 40;
    const CONFIG_KEY  = 'appt_slot_1-99';
    const APPT_FIELD  = 'appt_slot_1';
    const EVENT_ID    = 99;
    const INSTRUMENT  = 'example_a';
    const TEST_RECORD = '9001';

    /** @var int|string|null the slot reserved during a test, for cleanup */
    private $slotId = null;

    public function setUp(): void
    {
        parent::setUp();
        // Use the real project + DB-backed settings (not the in-memory test settings)
        $this->disableTestSettings();
        $_GET['pid'] = self::APPT_PID;
        ExternalModules::setProjectId(self::APPT_PID);

        if (!self::projectExists(self::APPT_PID) || !self::projectExists(self::SLOT_PID)) {
            $this->markTestSkipped("Example projects " . self::APPT_PID . "/" . self::SLOT_PID . " not present");
        }

        // Establish full REDCap project context (reserveSlot uses REDCap::isLongitudinal etc.,
        // which require PROJECT_ID + the $Proj/$longitudinal globals that a normal page bootstrap sets).
        if (!defined('PROJECT_ID')) define('PROJECT_ID', self::APPT_PID);
        $proj = new \Project(self::APPT_PID);
        $GLOBALS['Proj'] = $proj;
        $GLOBALS['longitudinal'] = $proj->longitudinal;
        $cfg = $this->module->get_tz_config(self::CONFIG_KEY);
        if (!$cfg || (string)($cfg['slot-project-id'] ?? '') !== (string)self::SLOT_PID) {
            $this->markTestSkipped("Module not configured (" . self::CONFIG_KEY . ") on pid " . self::APPT_PID);
        }
    }

    public function tearDown(): void
    {
        if ($this->slotId !== null) {
            try { $this->module->resetSlotAndAppointment(self::CONFIG_KEY, $this->slotId, 'phpunit cleanup', self::TEST_RECORD, 1); }
            catch (\Throwable $e) { /* slot may already be free / record may not exist */ }
        }
        try { \Records::deleteRecord(self::TEST_RECORD, \REDCap::getRecordIdField(), false, false, '', '', '', '', false, false, false); }
        catch (\Throwable $e) { /* record may not have been created */ }
        parent::tearDown();
    }

    private static function projectExists($pid)
    {
        $r = db_query("SELECT 1 FROM redcap_projects WHERE project_id = " . intval($pid));
        return $r && db_num_rows($r) > 0;
    }

    private function firstFutureAvailableSlot()
    {
        $now = new DateTime('now');
        foreach ($this->module->getSlots(self::CONFIG_KEY, true) as $sid => $s) {
            try { $dt = new DateTime(($s['date'] ?? '') . ' ' . ($s['time'] ?? '')); } catch (\Exception $e) { continue; }
            if ($dt > $now) return $sid;
        }
        return null;
    }

    /** MySQL IS_FREE_LOCK for this module's per-slot lock name (1 = released). */
    private function lockFree($slot)
    {
        $name = "tzs_slot_{$slot}_proj_" . self::SLOT_PID;
        $r = db_query("SELECT IS_FREE_LOCK('" . db_escape($name) . "') AS f");
        return (int) db_fetch_assoc($r)['f'];
    }

    public function testReserveWritesBothSidesAndReleasesLock()
    {
        $slot = $this->firstFutureAvailableSlot();
        if ($slot === null) $this->markTestSkipped("No future available slot in pid " . self::SLOT_PID);
        $this->slotId = $slot;

        $data = $this->module->reserveSlot(
            self::CONFIG_KEY, $slot, 'America/New_York', self::APPT_PID, self::TEST_RECORD, self::INSTRUMENT, self::EVENT_ID, 1
        );

        // Appointment (record) side
        $this->assertEquals($slot, $data[self::APPT_FIELD]);
        $rec = $this->module->getRecord(self::CONFIG_KEY, self::TEST_RECORD, 1);
        $this->assertEquals($slot, $rec[self::APPT_FIELD]);

        // Slot (slot DB) side
        $s = $this->module->getSlot(self::CONFIG_KEY, $slot);
        $this->assertNotEmpty($s['reserved_ts']);
        $this->assertEquals(self::TEST_RECORD, $s['source_record_id']);

        // Lock released by the finally block
        $this->assertSame(1, $this->lockFree($slot), "slot lock should be released after a successful reservation");
    }

    public function testFailedRecordSaveRollsBackSlotAndReleasesLock()
    {
        $slot = $this->firstFutureAvailableSlot();
        if ($slot === null) $this->markTestSkipped("No future available slot in pid " . self::SLOT_PID);
        $this->slotId = $slot;

        // Reserving against the WRONG event (event 2 / id 100, where appt_slot_1 is not
        // designated) makes the appointment-record save fail AFTER the slot has already been
        // persisted as reserved -- exercising the rollback path.
        $threw = false;
        try {
            $this->module->reserveSlot(
                self::CONFIG_KEY, $slot, 'America/New_York', self::APPT_PID, self::TEST_RECORD, self::INSTRUMENT, 100, 1
            );
        } catch (\Exception $e) {
            $threw = true;
        }
        $this->assertTrue($threw, "reserveSlot should throw when the appointment-record save fails");

        // Orphan rollback: the slot must be returned to available, not left reserved
        $s = $this->module->getSlot(self::CONFIG_KEY, $slot);
        $this->assertEmpty($s['reserved_ts'], "slot should be rolled back to available after a failed reservation");

        // Lock released by the finally block even on the failure path
        $this->assertSame(1, $this->lockFree($slot), "slot lock should be released after a failed reservation");
    }
}
