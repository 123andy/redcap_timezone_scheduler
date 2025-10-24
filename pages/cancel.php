<?php

namespace Stanford\TimezoneScheduler;

use HtmlPage;
use \Exception;

/** @var TimezoneScheduler $module */

// https://redcap.local/redcap_v15.3.3/ExternalModules/?prefix=timezone_scheduler&page=cancel&pid=38&NOAUTH?config=appt_slot_1-88&key=73l8gRjwLftklgfdXT%2BMduB7a4PKbzSQAlJ03U14mx20Tnei5RxGvH5goVdohMjy

try {

    // Initial goal is to validate the key and config, then show a confirmation message or error message
    $errors = [];

    // Load the key which is encrypted context when reservation was made
    $key = $module->escape($_GET['key'] ?? '');

    // Decrupt the key to get the config, slot_id, record, event_id, repeat_instance, and reserved_ts
    list($config_key, $slot_id, $record, $event_id, $repeat_instance, $reserved_ts) = explode("|", decrypt($key));
    $module->emDebug("Canceling appt with config key $config_key / $record / $event_id / $repeat_instance / $slot_id / $reserved_ts");

    if (empty($config_key) || empty($slot_id) || empty($reserved_ts)) {
        $module->emError("Missing required parameters in cancel page");
        throw new \Exception("Missing required parameters in cancel URL");
    }

    // Get the slot record
    $slot = $module->getSlot($config_key, $slot_id);

    if (empty($slot)) {
        $module->emError("Unable to find slot for $slot_id with config $config_key in cancel page.");
        $errors[] = "Unable to retrieve appointment slot.";
    } else {
        // Found slot -- lets confirm that slot info matches url encoded info (e.g. that no one has changed the reservation since the cancel url was created)
        if ($slot['reserved_ts'] != $reserved_ts) {
            $module->emError("Cancel confirmation error: reserved ts does not match for slot $slot_id. Expected $reserved_ts, got " . $slot['reserved_ts']);
            $errors[] = "Error: This appointment slot has changed and this cancel link is no longer valid.";
        } else if ($slot['source_record_id'] != $record) {
            $module->emError("Cancel confirmation error: record does not match for slot $slot_id. Expected $record, got " . $slot['source_record_id']);
            $errors[] = "Error: This appointment slot has changed and this cancel link is no longer valid.";
        } else if ($slot['source_event_id'] != $event_id) {
            $module->emError("Cancel confirmation error: event does not match for slot $slot_id. Expected $event_id, got " . $slot['source_event_id']);
            $errors[] = "Error: This appointment slot has changed and this cancel link is no longer valid.";
        } else if ($slot['source_instance_id'] != $repeat_instance) {
            $module->emError("Cancel confirmation error: instance does not match for slot $slot_id. Expected $repeat_instance, got " . $slot['source_instance_id']);
            $errors[] = "Error: This appointment slot has changed and this cancel link is no longer valid.";
        }
        $module->emDebug("Slot found: ", $slot);
    }

    if (empty($errors)) {
        // Generate a confirmation token that can be passed in if the user confirms the cancellation
        $confirmation_token = encrypt($config_key . "|" . strtotime('now'));
        $description = $module->escape($slot['participant_description']) ?? "Unable to retrieve description";
        $module->emDebug("Generated confirmation token: $confirmation_token");
    } else {
        $module->emError("Errors found in cancel page: ", $errors);
    }


    // // If we get here, then we can cancel the appointment
    $objHtmlPage = new HtmlPage();
    $objHtmlPage->addStylesheet("dashboard_public.css", 'screen,print');
    $objHtmlPage->setPageTitle("Cancel Appointment",'');
    $objHtmlPage->PrintHeader();
    ?>

    <h3></h3>

    <div class="cancel-appointment">

        <div aria-modal="true" class="modal fade" id="cancelModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Cancellation</h5>
                </div>
                <div class="modal-body">
                    This will cancel the appointment:
                    <div class='alert alert-info mt-2 mb-2 p-2'><?php echo str_replace("\n", "<br/>", $description); ?></div>
                </div>
                <div class="modal-footer">
                    <!-- <button type="button" data-action="close" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" >Close</button> -->
                    <button id="cancel-appointment-button" type="button" data-action="delete" class="btn btn-sm btn-danger" data-bs-dismiss="modal" >Cancel Appointment</button>
                </div>
                </div>
            </div>
        </div>

        <div id="cancel-msg" class='cancel-msg fs-2 text-center mt-3'>
        </div>

        <div class='text-center'>
        <?php if (empty($errors)) : ?>
            <button id='show-cancel-modal' class="btn btn-primary">Cancel Your Appointment</button>
        <?php endif; ?>
        </div>
    <?php

    $objHtmlPage->PrintFooter();

    $module->injectJSMO([
        "key" => $key,
        "errors" => $errors,
        "token" => $confirmation_token ?? null,
        "description" => $description ?? null,
        "context" => "cancel"
        ], "cancelPageRequest"
    );
} catch (\Exception $e) {
    // Do nothing -- just a debug statement
    $module->emError("Timezone Exception Error in cancel.php: " . $e->getMessage());
    echo "<div style='color:red; font-weight: bold;'>Error: " . $e->getMessage() . "</div><br>";
}
