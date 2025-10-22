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
