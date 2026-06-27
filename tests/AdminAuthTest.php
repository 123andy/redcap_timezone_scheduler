<?php namespace Stanford\TimezoneScheduler;

// Path to redcap_connect.php (tests/ -> module -> modules -> www)
require_once __DIR__ . '/../../../redcap_connect.php';

/**
 * Authorization tests for the admin-only AJAX actions.
 *
 * These actions require a login, but must NOT be callable by under-privileged project users.
 * In the test context there is no authenticated design-rights user, so the dispatcher must deny
 * every admin action (success:false) and perform no work. (No project context needed: the guard
 * throws before any project logic.)
 */
class AdminAuthTest extends \ExternalModules\ModuleBaseTest
{
    public function testAdminActionsAreDeniedWithoutDesignRights()
    {
        $adminActions = [
            'getSlotsVerificationData',
            'getAppointmentVerificationData',
            'resetAppointment',
            'resetSlotAndAppointment',
            'resetSlot',
            'cancelSlot',
        ];

        foreach ($adminActions as $action) {
            $res = $this->module->redcap_module_ajax(
                $action, [], null, null, null, null, null, null, null, null, null, null, null, null
            );
            $this->assertIsArray($res, "$action should return a result array");
            $this->assertFalse($res['success'] ?? true, "$action must be denied without design rights");
            $this->assertStringContainsStringIgnoringCase(
                'permission', $res['message'] ?? '', "$action should report a permission error"
            );
        }
    }

    public function testParticipantActionIsNotBlockedByTheDesignRightsGuard()
    {
        // getTimezones is a participant action. In this bare context it may fail for lack of
        // project context, but it must NOT be rejected by the design-rights guard.
        $res = $this->module->redcap_module_ajax(
            'getTimezones', [], null, null, null, null, null, null, null, null, null, null, null, null
        );
        $this->assertIsArray($res);
        $this->assertStringNotContainsStringIgnoringCase(
            'permission', $res['message'] ?? '', "participant actions must not be gated by the design-rights guard"
        );
    }

    public function testGetSlotActionIsNoLongerServed()
    {
        // The getSlot action (which returned a whole slot row, enabling cross-participant
        // enumeration) has been removed; the dispatcher must no longer recognize it.
        // (The framework also rejects it pre-dispatch since it's no longer in config.json.)
        $res = $this->module->redcap_module_ajax(
            'getSlot', ['config_key' => 'x', 'slot_id' => 1],
            null, null, null, null, null, null, null, null, null, null, null, null
        );
        $this->assertIsArray($res);
        $this->assertFalse($res['success'] ?? true, "getSlot action must no longer be served");
        // It must not return slot data
        $this->assertArrayNotHasKey('data', $res, "getSlot must not return any slot data");
    }
}
