<?php
namespace Stanford\TimezoneScheduler;

use DateTime;
use DateTimeZone;
use REDCap;

/** @var TimezoneScheduler $module */

?>

<h3>The Timezone Scheduler Admin Page</h3>
<p>From this page you can review all appointments on this project.  Appointments with potential issues are highlighted:</p>

<div class="container-fluid my-4">

  <!-- Tabs nav -->
  <ul class="nav nav-tabs" id="tabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab1-tab" data-bs-toggle="tab" data-bs-target="#tab1" type="button" role="tab" aria-controls="tab1" aria-selected="true"><h6>Instructions</h6></button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab2-tab" data-bs-toggle="tab" data-bs-target="#tab2" type="button" role="tab" aria-controls="tab2" aria-selected="false"><h6>Appointments</h6></button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab3-tab" data-bs-toggle="tab" data-bs-target="#tab3" type="button" role="tab" aria-controls="tab3" aria-selected="false"><h6>Slots Database</h6></button>
    </li>
  </ul>

  <!-- Tabs content -->
  <div class="tab-content pt-3" id="myTabContent">
    <div class="tab-pane fade show active" id="tab1" role="tabpanel" aria-labelledby="tab1-tab">
      <p>This is the content for Tab 1.</p>
    </div>
    <div class="tab-pane fade" id="tab2" role="tabpanel" aria-labelledby="tab2-tab">
      <p>Below are all appointments for this project.  Use the search box in the upper corner to filter the list.
        Any appointments with errors should be reviewed/corrected.  Upon making changes via an action button,
        you should refresh the page to ensure any errors are resolved.
      </p>
      <div style="width: 100%; max-width: 900px; margin: 0;">
        <table id="Appointments" class="table table-striped" style="max-width: 100%">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Config</th>
                    <th>Record</th>
                    <th>Instance</th>
                    <th>Slot#</th>
                    <th>Slot Date/Time</th>
                    <th>Errors</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <!-- Populate with your data rows -->
            </tbody>
        </table>
      </div>
    </div>

    <!-- Tab 3: Slots Database -->
    <div class="tab-pane fade" id="tab3" role="tabpanel" aria-labelledby="tab3-tab">
      <p>Below is a list of all slots, including some slots that may be present in the appointments tab.</p>
      <div style="width: 100%; max-width: 900px; margin: 0;">
        <table id="Slots" class="table table-striped" style="max-width: 100%">
            <thead>
                <tr>
                    <th>Slot ID</th>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Appt Link</th>
                    <th>Errors</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <!-- Populate with your data rows -->
            </tbody>
        </table>
      </div>
    </div>
  </div>
</div>



<!-- Modal for confirming actions -->
<div id="tz_select_confirm_modal" aria-modal="true" class="modal top-modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">Template Title</h5>
        </div>
        <div class="modal-body">
            Template Body
        </div>
        <div class="modal-footer">
            <button type="button" data-action="cancel" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" >Go Back</button>
            <button type="button" data-action="delete" class="btn btn-sm btn-danger" data-bs-dismiss="modal" >Cancel Appointment</button>
            <button type="button" data-action="ok" class="btn btn-sm btn-primary" data-bs-dismiss="modal">OK</button>
        </div>
        </div>
    </div>
</div>



<?php
// TODO: Separate out HTML into parts we actually want, like just the notifications
// $module->injectHTML();
$module->initializeJavascriptModuleObject();
$data = [];
?>
<script src="<?=$module->getUrl("assets/admin_jsmo.js",true)?>"></script>
<script>
    (function() {
        <?php
            echo "const module = " . $module->getJavascriptModuleObjectName() . ";\n";
            echo "module.data = " . json_encode($data) . ";\n";
            if ($module->emLoggerDebugMode()) echo "module.debugger=true;\n";
            echo "module.afterRender(module.loadTables);\n";
        ?>
    })()
</script>

<style>
    .table .btn {
        min-width: 40px;
    }
    .table .btn + .btn {
      margin-top: 4px;
      display: block;
    }

    .fa-circle-info:hover {
        cursor: help;
    }
</style>

<hr/>

<?php

exit();

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


// $appts = $module->getRecords($config_id);
// echo "<br/>Records for config $config_id:  " . count($appts) . "<br/>";

// $appts2 = $module->getRecords($config_id2);
// echo "<br/>Records for config $config_id2:  " . count($appts2) . "<br/>";


$slots = $module->getSlots($config_id, false);
echo "<br/>Slots for config $config_id:  " . count($slots) . "<br/>";
echo "<pre>";
// print_r(current($appts));
print_r($slots[4]);
echo "</pre>";


$svd = $module->verifySlots($config_id);
echo "<br/>Slot Verification Data for config $config_id:<br/><pre>";
print_r($svd);
echo "</pre>";



exit();

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
