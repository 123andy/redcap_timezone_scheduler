<?php namespace Stanford\TimezoneScheduler;

// Path to redcap_connect.php (tests/ -> module -> modules -> www)
require_once __DIR__ . '/../../../redcap_connect.php';

use DateTime;

/**
 * Pure unit tests for the appointment-rendering logic. getAppointmentOptions() only depends on
 * get_tz_config() for two format strings, so seeding the public $config property makes it fully
 * testable with no project context. Also covers getUniqueAppointmentParticipantDates() and
 * slotToCalendarConfig(), which are pure.
 *
 * Times are interpreted in the server's default timezone; tests that need a "matching" client
 * timezone use the server timezone itself, and a "differing" one picks any other zone, so the
 * assertions don't depend on what the server timezone happens to be.
 */
class AppointmentOptionsTest extends \ExternalModules\ModuleBaseTest
{
    private $serverTz;

    public function setUp(): void
    {
        parent::setUp();
        $this->serverTz = date_default_timezone_get();
    }

    public function tearDown(): void
    {
        // Module instances are cached/shared across test classes; clear the seeded config so it
        // doesn't leak into other tests (e.g. integration tests that load real project settings).
        $this->module->config = [];
        parent::tearDown();
    }

    private function differingTz()
    {
        return $this->serverTz === 'UTC' ? 'America/New_York' : 'UTC';
    }

    /** Seed config and call getAppointmentOptions. */
    private function options($slots, $clientTz, $descFormat = null, $textFormat = null, $filterPast = true)
    {
        $cfg = [];
        if ($descFormat !== null) $cfg['appt-description-format'] = $descFormat;
        if ($textFormat !== null) $cfg['appt-text-date-format'] = $textFormat;
        $this->module->config = ['k' => $cfg];
        return $this->module->getAppointmentOptions('k', $slots, $clientTz, $filterPast);
    }

    private function futureSlot($overrides = [])
    {
        return array_merge(['date' => '2099-12-31', 'time' => '09:00', 'title' => 'Visit'], $overrides);
    }

    function testTokenSubstitutionAndShape()
    {
        $res = $this->options([5 => $this->futureSlot()], $this->serverTz, "{title} (#{slot_id}) {date} {time} {client-tz}");
        $this->assertCount(1, $res);
        $o = $res[0];
        $this->assertSame('5', $o['id']);                          // id is a string
        $this->assertSame('2099-12-31 09:00', $o['server_dt']);
        $this->assertSame('2099-12-31', $o['participant_date']);
        $this->assertStringContainsString('Visit (#5) 2099-12-31 09:00', $o['text']);
        $this->assertStringContainsString($this->serverTz, $o['text']); // {client-tz} = full zone name
    }

    function testTimezoneMatchStripsHiddenSection()
    {
        // client tz == server tz -> the <== ... ==> block is removed entirely
        $res = $this->options([5 => $this->futureSlot()], $this->serverTz, "A<==B==>C");
        $this->assertSame('AC', $res[0]['text']);
    }

    function testTimezoneDifferKeepsContentButRemovesMarkers()
    {
        // client tz != server tz -> markers removed, inner content (incl. newlines) kept
        $res = $this->options([5 => $this->futureSlot()], $this->differingTz(), "A<==B\nB2==>C");
        $this->assertSame("AB\nB2C", $res[0]['text']);
    }

    function testDiffToken()
    {
        $res = $this->options([5 => $this->futureSlot()], $this->serverTz, "{diff}");
        $this->assertMatchesRegularExpression('/^\d+ days \d+ hours$/', $res[0]['text']);
    }

    function testPastSlotsFilteredByDefault()
    {
        $slots = [1 => $this->futureSlot(['date' => '2000-01-01']), 2 => $this->futureSlot()];
        $res = $this->options($slots, $this->serverTz, "{slot_id}");
        $this->assertCount(1, $res);
        $this->assertSame('2', $res[0]['id']);
    }

    function testPastSlotsIncludedWhenFilterDisabled()
    {
        $slots = [1 => $this->futureSlot(['date' => '2000-01-01']), 2 => $this->futureSlot()];
        $res = $this->options($slots, $this->serverTz, "{slot_id}", null, false);
        $this->assertCount(2, $res);
    }

    function testSlotWithMissingDateOrTimeIsSkipped()
    {
        $slots = [1 => $this->futureSlot(['time' => '']), 2 => $this->futureSlot(['time' => '10:00'])];
        $res = $this->options($slots, $this->serverTz, "{slot_id}");
        $this->assertCount(1, $res);
        $this->assertSame('2', $res[0]['id']);
    }

    function testDefaultFormatStripsServerParentheticalWhenTimezonesMatch()
    {
        // Default description format contains "(#{slot_id})" plus a server parenthetical inside
        // <== ==>. When client tz == server tz, only the "(#5)" paren should remain.
        $res = $this->options([5 => $this->futureSlot()], $this->serverTz);
        $this->assertStringContainsString('Visit (#5)', $res[0]['text']);
        $this->assertSame(1, substr_count($res[0]['text'], '('), "server parenthetical should be stripped");
    }

    function testGetUniqueAppointmentParticipantDates()
    {
        $appts = [
            ['id' => '1', 'participant_date' => '2099-12-31'],
            ['id' => '2', 'participant_date' => '2099-12-31'],
            ['id' => '3', 'participant_date' => '2100-01-01'],
            ['id' => '4', 'participant_date' => ''],   // skipped
        ];
        $u = $this->module->getUniqueAppointmentParticipantDates($appts);
        $this->assertSame(['1', '2'], $u['2099-12-31']);
        $this->assertSame(['3'], $u['2100-01-01']);
        $this->assertArrayNotHasKey('', $u);
    }

    function testSlotToCalendarConfig()
    {
        $c = $this->module->slotToCalendarConfig(['date' => '2099-12-31', 'time' => '09:00', 'title' => 'Visit']);
        $this->assertSame('2099-12-31', $c['startDate']);
        $this->assertSame('09:00', $c['startTime']);
        $this->assertSame('10:00', $c['endTime']);            // default 1 hour duration
        $this->assertSame($this->serverTz, $c['timeZone']);
        // NOTE: name/description are still hardcoded placeholders ("Test" / "description placeholder")
        // -- a known stub bug, not asserted here.

        $this->assertNull($this->module->slotToCalendarConfig([]));
    }
}
