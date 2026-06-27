<?php namespace Stanford\TimezoneScheduler;

// Path to redcap_connect.php (tests/ -> module -> modules -> www)
require_once __DIR__ . '/../../../redcap_connect.php';

use DateTime;

/**
 * Unit tests for the pure iCal builder (buildVCalendar()).
 *
 * buildVCalendar() takes plain event arrays (as produced by getIcalFeed()) plus a
 * server timezone and returns a Sabre VCalendar -- no REDCap data access -- so it is
 * unit testable without any test projects.
 */
class IcalFeedTest extends \ExternalModules\ModuleBaseTest
{
    private function event($overrides = []) {
        return array_merge([
            'uid'         => 'tzs-40-5@timezone-scheduler',
            'slot_id'     => 5,
            'date'        => '2099-12-31',
            'time'        => '09:00',
            'title'       => 'Onboarding Visit',
            'description' => 'Wed, Dec 31st at 9:00 AM PST',
            'record'      => 7,
            'url'         => 'https://redcap.example/redcap_v15.3.3/DataEntry/index.php?pid=99&id=7&page=visit',
        ], $overrides);
    }

    function testBookedAppointmentBecomesVeventWithRecordLink()
    {
        $ics = $this->module->buildVCalendar([$this->event()], 'America/Los_Angeles')->serialize();

        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('UID:tzs-40-5@timezone-scheduler', $ics);
        $this->assertStringContainsString('SUMMARY:Onboarding Visit', $ics);
        // Link back to the REDCap record appears both as URL property and in the description
        $this->assertStringContainsString('id=7', $ics);
        $this->assertStringContainsString('Record: 7', $ics);
    }

    function testServerTimeIsEmittedInUtc()
    {
        // 09:00 in America/Los_Angeles on Dec 31 (PST, UTC-8) == 17:00 UTC
        $ics = $this->module->buildVCalendar([$this->event()], 'America/Los_Angeles')->serialize();
        $this->assertStringContainsString('DTSTART:20991231T170000Z', $ics);
        // Default 30 minute duration
        $this->assertStringContainsString('DTEND:20991231T173000Z', $ics);
    }

    function testEventWithInvalidDateTimeIsSkipped()
    {
        $events = [
            $this->event(['date' => 'not-a-date', 'time' => '']),
            $this->event(['uid' => 'tzs-40-6@timezone-scheduler', 'slot_id' => 6]),
        ];
        $ics = $this->module->buildVCalendar($events, 'UTC')->serialize();

        // Only the one valid event should be present
        $this->assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('tzs-40-6@timezone-scheduler', $ics);
    }

    function testEmptyFeedProducesValidEmptyCalendar()
    {
        $ics = $this->module->buildVCalendar([], 'UTC')->serialize();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
    }

    function testCalendarNameIsEmittedWhenProvided()
    {
        $name = 'Appointments: My Study (pid 42)';
        $ics = $this->module->buildVCalendar([], 'UTC', $name)->serialize();
        $this->assertStringContainsString('X-WR-CALNAME:' . $name, $ics);
    }

    function testIcalFilenameIncludesProjectIdAndSluggedTitle()
    {
        $this->assertSame(
            'timezone_scheduler_pid42_My_Study_2025.ics',
            $this->module->getIcalFilename(42, 'My Study (2025)')
        );
        // No title -> still uniquely named by pid
        $this->assertSame(
            'timezone_scheduler_pid7.ics',
            $this->module->getIcalFilename(7, '')
        );
    }
}
