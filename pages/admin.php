<?php
namespace Stanford\TimezoneScheduler;

use DateTime;
use DateTimeZone;
use REDCap;

/** @var TimezoneScheduler $module */

?>

<h3>Timezone Scheduler Admin Page</h3>
<p>This is currently a testing area for development -- in the final version we could put documentation or, potentially,
    we could build a better user interface for managing the module settings.</p>
<hr/>

<?php



// Replace this with your module code
echo "Hello from $module->PREFIX";

echo "<h4>Currently working on a way to review all appointments and slots to identify any problems</h4>";

$field = "appt_slot_1";
$field2 = "example_appt";


$event_id = 88;
$config_id = $field . "-" . $event_id;
$config_id2 = $field2 . "-" . $event_id;

$record = 3;
$repeat_instance = null;


$appts = $module->getRecords($config_id);
echo "<br/>Records for config $config_id:  " . count($appts) . "<br/>";

$appts2 = $module->getRecords($config_id2);
echo "<br/>Records for config $config_id2:  " . count($appts2) . "<br/>";


$slots = $module->getSlots($config_id, false);
echo "<br/>Slots for config $config_id:  " . count($slots) . "<br/>";
echo "<pre>";
print_r(current($appts));
print_r($slots[4]);
echo "</pre>";

$slots2 = $module->getSlots($config_id2, false);
echo "<br/>Slots for config $config_id2:  " . count($slots2) . "<br/>";

// Loop through appts
$config = $module->get_tz_config($config_id);
$appt_data = [];
$slots_used = [];
$slot_data = [];

foreach ($appts as $appt) {

    $error = [];
    $actions = [];

    $appt_record = $appt[REDCap::getRecordIdField()];
    $appt_event_id = REDCap::getEventIdFromUniqueEvent($appt['redcap_event_name'] ?? null);
    $appt_instance = $appt['redcap_repeat_instance'] ?? null;
    $appt_repeat_instance = $appt_instance ? $appt_instance : 1;
    $appt_project_id = $module->getProjectId();
    $appt_instrument = $module->getFormForField($config['appt-field']);
    $appt_url = APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION .
            "/DataEntry/index.php?pid=$appt_project_id&id=$appt_record&page=$appt_instrument&event_id=$appt_event_id&instance=$appt_repeat_instance";

    $appt_slot_id = $appt[$config['appt-field']];    // Should be guaranteed to have a value due to filterLogic in getRecords
    $slots_used[] = $appt_slot_id;
    $slot = $slots[$appt_slot_id] ?? null;
    if (!$slot) {
        $error[] = "No slot db entry found for slot_id $appt_slot_id";
        $actions[] = "Reset Appointment";
    } else {
        $slot_url = APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION .
            "/DataEntry/index.php?pid=" . $config['slot-project-id'] . "&id=$slot_id&page=slots";

        // Check that slot matches record, event, instance
        if ($appt_record != $slot['source_record_id']) {
            $error[] = "Appointment record $appt_record does not match Slot DB record {$slot['source_record_id']}";
        }
        if ($appt_event_id != $slot['source_event_id']) {
            $error[] = "Appointment event $appt_event_id does not match Slot DB event {$slot['source_event_id']}";
        }
        if ($appt_repeat_instance != $slot['source_instance_id']) {
            $error[] = "Appointment instance $appt_repeat_instance does not match Slot DB instance {$slot['source_instance_id']}";
        }

        // Check datetime field if stored on the appt record
        if (!empty($config['appt-datetime-field'])) {
            $slot_dt = $slot['date'] . " " . $slot['time'];
            $appt_dt = $appt[$config['appt-datetime-field']] ?? null;
            if ($appt_dt && $slot_dt != $appt_dt) {
                $error[] = "Appointment datetime ($appt_dt) stored in " . $appt[$config['appt-datetime-field']] . " does not match Slot DB datetime ($slot_dt)";
            }
        }
        $actions[] = "Reset SlotDB";
        $actions[] = "Cancel SlotDB";
    }

    // Unique appt is config_key + record + instance
    // $actions[] = "Reset Appt";

    $appt_data[] = [
        'status' => empty($error) ? "OK" : "ERROR",
        'config_id' => $config_id,
        'appt_slot_id' => $appt_slot_id,
        'appt_record' => $appt_record,
        'appt_event_name' => $appt['redcap_event_name'] ?? null,
        'appt_instance' => $appt_repeat_instance,
        'appt_url' => $appt_url,
        'slot_db_url' => $slot_url ?? null,
        'errors' => implode('<br/>', $error),
        'actions' => implode('<br/>', $actions)
    ];
}


$other_slots = array_diff_key($slots, array_flip($slots_used));
foreach ($other_slots as $slot_id => $slot) {
    $actions = [];
    $error = [];

    $source_project_id = $slot['source_project_id'] ?? null;
    if (!empty($source_project_id) && $source_project_id != $module->getProjectId()) {
        // Skip slots that are reserved by other projects
        $module->emDebug("Skipping slot $slot_id reserved by other project $source_project_id");
        continue;
    }

    $status = empty($slot['reserved_ts']) ? "UNUSED" : "ORPHANED";
    if ($status == "UNUSED") {
        $actions[] = "Cancel Slot";
    } else {
        $actions[] = "Reset Slot";
    }

    $slot_record = $slot['source_record_id'] ?? null;
    if (!empty($slot_record)) {
        $error[] = "Slot $slot_id points to record $slot_record, but that record does not point back to this slot.";
    }

    $slot_project_id = $slot['source_project_id'] ?? null;
    $slot_record_id = $slot['source_record_id'] ?? null;
    $slot_event_id = $slot['source_event_id'] ?? null;
    $slot_instance_id = $slot['source_instance_id'] ?? null;
    $slot_field = $slot['source_field'] ?? null;
    $slot_form = empty($slot_field) ? null : $module->getFormForField($slot_field);

    $slot_appt_url = APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION .
            "/DataEntry/index.php?pid=$slot_project_id&id=$slot_record_id&page=$slot_form&event_id=$slot_event_id&instance=$slot_instance_id";


    $slot_data[] = [
        'status' => $status,
        'slot_id' => $slot_id,
        'title' => $slot['title'] ?? null,
        'datetime' => ($slot['date'] ?? null) . " " . ($slot['time'] ?? null),
        'project_id' => $slot_project_id,
        'record' => $slot_record_id,
        'field' => $slot_field,
        'event_name' => $slot_event_id ? REDCap::getEventNames(true, false, $slot_event_id) : null,
        'instance' => $slot_instance_id ?? null,
        'slot_db_url' => APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION .
            "/DataEntry/index.php?pid=" . $config['slot-project-id'] . "&id=$slot_id&page=slots",
        'errors' => implode('<br/>', $error),
        'actions' => implode('<br/>', $actions)
    ];
}


echo "<pre>";
print_r($appt_data);
echo "</pre>";

echo "<pre>";
print_r($slot_data);
echo "</pre>";








exit();



$result = $module->test($config_id, $record, $event_id, $repeat_instance);
echo "<br/>Test Result:<br/><pre>";
print_r($result);
echo "</pre>";
exit();



$form = $module->getFormForField($field);
echo "<br/>Form for field $field: " . ($form ? $form : "not found");

// $project = $module->getProject();
// $repeating_forms = $project->getRepeatingForms($event_id);

$repeating_forms = $module->getRepeatingForms($event_id);
echo "<br/>Repeating forms: " . (empty($repeating_forms) ? "none" : implode(", ", $repeating_forms));

echo "<br/>Is $form a repeating form? " . (in_array($form, $repeating_forms) ? "yes" : "no");



$params = [
    'return_format' => 'json',
    'records' => '2',
    'events' => $event_id
];

$qa = REDCap::getData('array', '2', null, [$event_id]);
$qj = REDCap::getData($params);
echo "<br/>Data for record 2 (array):<br/><pre>";
print_r($qa);
echo "</pre>";
echo "<br/>Data for record 2 (json):<br/><pre>";
// print_r($qj);
$b = json_decode($qj, true);
$pretty = json_encode($b, JSON_PRETTY_PRINT);
echo $pretty;
echo "</pre>";






echo "<br/>";
echo $module->getUrl("pages/cancel.php", true);


// $p = $module->getProjectSetting('timezone-database');
// echo "<br/>Project Setting timezone-database: " . ($p ? $p : "not set");
// $q = $module->getTimezoneList();
// echo "<br/>Timezone List count: " . count($q);
// echo "<pre>";
// print_r($q);
// echo "</pre>";


if ($module->isAuthenticated() && $module->getUser()->hasDesignRights()) {
    echo "<br/>User is authenticated and has design rights.";
} else {
    echo "<br/>User is NOT authenticated or does NOT have design rights.";
}

if($module->isAuthenticated()){
    echo "<br/>Is Authenticated!";
    $user = $module->getUser();
    // $module->emDebug("User is authenticated: ", $user, $user->hasDesignRights());
    // $rights = $module->getRights($user->getU)
}

exit();


$dtz = new DateTimeZone('America/New_York');
$dt = new DateTime("now", $dtz);
$offset = $dtz->getOffset($dt); // Offset in seconds
$abbreviation = $dt->format('H:i T'); // Timezone abbreviation, e.g., PST or CET
echo "$tz | Offset: $offset | Abbreviation: $abbreviation\n";
$dt->setTimezone(new DateTimeZone('Europe/Prague'));
$offset = $dtz->getOffset($dt); // Offset in seconds
$abbreviation = $dt->format('H:i T'); // Timezone abbreviation, e.g., PST or CET
echo "<br/>$tz | Offset: $offset | Abbreviation: $abbreviation\n";


print "<pre>";
print_r($dtz);
print "<br/>DateTimeZone::listIdentifiers(DateTimeZone::ALL):<br/>";
print_r(DateTimeZone::listIdentifiers(DateTimeZone::ALL));
print "<br/>DateTimeZone::listAbbreviations():<br/>";
print_r(DateTimeZone::listAbbreviations());
echo "</pre>";




// $module->load_module_config();


// // $tz = $module->filter_tz_config("test_form",88);
// $tz = $module->filter_tz_config("form2",88);

// echo "<pre>";
// print_r($module->config);
// echo "=====";
// print_r($tz);
// echo "</pre>";

// Use DateTimeZone;
// $timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
// echo "<pre>";
// print_r($timezones);
// echo "</pre>";

//file_put_contents('timezones.json', json_encode($timezones, JSON_PRETTY_PRINT));
