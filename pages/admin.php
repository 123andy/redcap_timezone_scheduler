<?php
namespace Stanford\TimezoneScheduler;

use DateTime;
use DateTimeZone;
use REDCap;

/** @var TimezoneScheduler $module */

$ical_feed_url = $module->getIcalFeedUrl();

// Overview data for the Instructions tab
$config_summary = $module->getConfigSummary();
$slot_db_stats  = $module->getSlotDbStats();

$enabled_count  = 0;
$disabled_count = 0;
foreach ($config_summary as $c) {
    if ($c['disabled']) { $disabled_count++; } else { $enabled_count++; }
}

// event_id => label, for display
$event_names = REDCap::getEventNames(true, false);

// Resolve a slot DB project title for display (best effort)
$slotDbTitle = function ($pid) {
    try {
        $p = new \Project($pid);
        return $p->project['app_title'] ?? ('Project ' . $pid);
    } catch (\Throwable $e) {
        return 'Project ' . $pid;
    }
};
$projectHomeUrl = function ($pid) {
    return APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION . '/index.php?pid=' . $pid;
};

?>

<h3>The Timezone Scheduler Admin Page</h3>
<p>From this page you can review all appointments on this project.  Appointments with potential issues are highlighted:</p>

<div class="container-fluid my-4">

  <!-- Tabs nav -->
  <ul class="nav nav-tabs" id="tabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab1-tab" data-bs-toggle="tab" data-bs-target="#tab1" type="button" role="tab" aria-controls="tab1" aria-selected="true"><h6><i class="fas fa-circle-info mr-1"></i> Overview</h6></button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab2-tab" data-bs-toggle="tab" data-bs-target="#tab2" type="button" role="tab" aria-controls="tab2" aria-selected="false"><h6><i class="fas fa-calendar-check mr-1"></i> Appointments</h6></button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab3-tab" data-bs-toggle="tab" data-bs-target="#tab3" type="button" role="tab" aria-controls="tab3" aria-selected="false"><h6><i class="fas fa-database mr-1"></i> Slots Database</h6></button>
    </li>
  </ul>

  <!-- Tabs content -->
  <div class="tab-content pt-3" id="myTabContent">
    <div class="tab-pane fade show active" id="tab1" role="tabpanel" aria-labelledby="tab1-tab">
      <p>The <b>Timezone Scheduler</b> lets participants book appointments from a separate
         <b>Slot Database</b> project and view available times in their own timezone. The selected
         slot's <code>slot_id</code> is stored in an appointment field on this project, and the slot
         record in the Slot DB is updated with a back-reference to this record. Use the tabs above to
         review booked appointments (<b>Appointments</b>), inspect every slot (<b>Slots Database</b>),
         and repair any inconsistencies that are flagged there.</p>

      <h5 class="mt-4"><i class="fas fa-cog"></i> Configuration in this project</h5>
      <?php if (empty($config_summary)): ?>
        <div class="alert alert-warning">
          <p class="mb-2"><b>This project has no Timezone Scheduler appointments configured yet.</b></p>
          <p class="mb-1">To get started:</p>
          <ol class="mb-0">
            <li>Create a <b>Slot Database</b> project to hold your available appointment slots. See the
              <a href="https://github.com/123andy/redcap_timezone_scheduler#readme" target="_blank" rel="noopener">module README on GitHub</a>
              for step-by-step instructions and an importable slot database template.</li>
            <li>Then configure this project: click the <b>External Modules</b> link in the left sidebar,
              find <b>Timezone Scheduler</b>, and choose <b>Configure</b> to map an appointment field to your Slot Database.</li>
          </ol>
        </div>
      <?php else: ?>
        <p><b><?= count($config_summary) ?></b> appointment field configuration(s)
           (<?= $enabled_count ?> enabled, <?= $disabled_count ?> disabled) drawing from
           <b><?= count($slot_db_stats) ?></b> slot database(s).</p>
        <table class="table table-sm table-striped" style="max-width:760px;">
          <thead><tr><th>Appointment Field</th><th>Event</th><th>Slot Database</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($config_summary as $c):
              $ev  = $event_names[$c['event_id']] ?? $c['event_id'];
              $pid = $c['slot_project_id'];
          ?>
            <tr>
              <td><code><?= $module->escape($c['appt_field']) ?></code></td>
              <td><?= $module->escape($ev) ?></td>
              <td><a href="<?= $module->escape($projectHomeUrl($pid)) ?>" target="_blank"><?= $module->escape($slotDbTitle($pid)) ?></a>
                  <span class="text-muted">(pid <?= $module->escape($pid) ?>)</span></td>
              <td><?= $c['disabled']
                    ? '<span class="badge bg-secondary">Disabled</span>'
                    : '<span class="badge bg-success">Enabled</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <h5 class="mt-4"><i class="fas fa-database"></i> Slot database status</h5>
      <?php if (empty($slot_db_stats)): ?>
        <p class="text-muted">No slot databases are referenced by this project's configuration.</p>
      <?php else: foreach ($slot_db_stats as $db): $pid = $db['slot_project_id']; ?>
        <div class="card mb-3" style="max-width:760px;">
          <div class="card-header">
            <a href="<?= $module->escape($projectHomeUrl($pid)) ?>" target="_blank"><?= $module->escape($slotDbTitle($pid)) ?></a>
            <span class="text-muted">(pid <?= $module->escape($pid) ?>)</span>
          </div>
          <div class="card-body">
            <div class="d-flex flex-wrap" style="gap:1.75rem;">
              <div><div class="h4 mb-0"><?= $db['total'] ?></div><small class="text-muted">Total slots</small></div>
              <div><div class="h4 mb-0 text-success"><?= $db['available_future'] ?></div><small class="text-muted">Available (upcoming)</small></div>
              <div><div class="h4 mb-0 text-primary"><?= $db['booked'] ?></div><small class="text-muted">Booked</small></div>
              <div><div class="h4 mb-0 text-secondary"><?= $db['cancelled'] ?></div><small class="text-muted">Blocked / cancelled</small></div>
              <div><div class="h4 mb-0"><?= $db['past'] ?></div><small class="text-muted">In the past</small></div>
            </div>
            <div class="mt-3">
              <small class="text-muted">Utilization (booked of bookable): <b><?= $db['percent_used'] ?>%</b></small>
              <div class="progress" style="height:8px; max-width:420px;">
                <div class="progress-bar" role="progressbar" style="width: <?= $db['percent_used'] ?>%;"
                     aria-valuenow="<?= $db['percent_used'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>

      <h5 class="mt-4"><i class="fas fa-calendar-alt"></i> iCal Subscription Feed</h5>
      <p>Subscribe to this feed in Outlook, Google Calendar, Apple Calendar, etc. to see all booked
         appointments for this project. Each calendar entry links back to its REDCap record.
         The feed contains the appointment date/time and the record ID only &mdash; as long as your record IDs
         do not contain PHI, no PHI is exposed in the feed.</p>
      <p class="text-muted" style="font-size:12px;">
         <i class="fas fa-exclamation-triangle"></i> Anyone with this URL can view the feed. Treat it like a password.</p>

      <div class="input-group" style="width:100%;">
        <input id="tz_ical_feed_url" type="text" class="form-control" readonly
               style="font-size:11px;"
               value="<?= $module->escape($ical_feed_url) ?>" onclick="this.select();">
        <button id="tz_copy_ical_url" type="button" class="btn btn-secondary" title="Copy to clipboard" style="font-size:11px;">
          <i class="fas fa-copy"></i> Copy
        </button>
      </div>

      <script>
        document.getElementById('tz_copy_ical_url').addEventListener('click', function () {
            var input = document.getElementById('tz_ical_feed_url');
            input.select();
            var done = function () {
                var btn = document.getElementById('tz_copy_ical_url');
                var html = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(function () { btn.innerHTML = html; }, 2000);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
            } else {
                document.execCommand('copy');
                done();
            }
        });
      </script>

      <h5 class="mt-4"><i class="fas fa-bug"></i> Problems or Feedback?</h5>
      <p>If you run into a bug, have a question, or want to request a feature, please report it as a
         GitHub issue on the module's repository. Including steps to reproduce and any relevant
         error messages helps a lot.</p>
      <p>
        <a href="https://github.com/123andy/redcap_timezone_scheduler/issues" target="_blank" rel="noopener"
           class="btn btn-sm btn-outline-secondary">
          <i class="fab fa-github"></i> Report an Issue on GitHub
        </a>
      </p>
    </div>
    <div class="tab-pane fade" id="tab2" role="tabpanel" aria-labelledby="tab2-tab">
      <h5><i class="fas fa-calendar-check mr-1"></i> Appointments</h5>
      <p>Every appointment booked through this project is listed below. Use the search box to filter the list,
        and review any row flagged with an error. After resolving an issue with an action button,
        refresh the page to confirm the error is cleared.</p>
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

      <h5><i class="fas fa-calendar-day mr-1"></i> Calendar View</h5>
      <p>Each day shows a <span class="badge bg-primary">booked / total</span> badge for its slots.
        <span class="ml-2"><span class="tz-cal-swatch tz-cal-open"></span> open &nbsp;
        <span class="tz-cal-swatch tz-cal-partial"></span> partially booked &nbsp;
        <span class="tz-cal-swatch tz-cal-full"></span> fully booked</span>
      </p>
      <div id="tz_admin_calendar"></div>

      <hr/>

      <h5><i class="fas fa-list mr-1"></i> All Slots</h5>
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
<link rel="stylesheet" href="<?=$module->getUrl('assets/bootstrap-datepicker.min.css',true)?>">
<script src="<?=$module->getUrl('assets/bootstrap-datepicker.min.js',true)?>"></script>
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

    /* Slots calendar view */
    #tz_admin_calendar .datepicker,
    #tz_admin_calendar .datepicker-inline { width: auto; }
    #tz_admin_calendar table { font-size: 13px; }
    #tz_admin_calendar td,
    #tz_admin_calendar th {
        width: 52px; min-width: 52px; height: 50px;
        vertical-align: top; padding-top: 5px; text-align: center;
    }
    /* keep the days themselves from being painted as solid blocks */
    #tz_admin_calendar td.day { background: none; }
    /* A div (not span) avoids bootstrap-datepicker's built-in ".datepicker td span" styling */
    #tz_admin_calendar .tz-cal-badge {
        display: block; float: none; white-space: nowrap;
        width: max-content; max-width: 100%; height: auto;
        font-size: 9px; line-height: 1.2; margin: 2px auto 0;
        padding: 0 5px; border-radius: 8px; background:#e9ecef; color:#333;
    }
    #tz_admin_calendar .tz-cal-full .tz-cal-badge    { background:#dc3545; color:#fff; }
    #tz_admin_calendar .tz-cal-partial .tz-cal-badge { background:#ffc107; color:#000; }
    #tz_admin_calendar .tz-cal-open .tz-cal-badge    { background:#28a745; color:#fff; }

    /* Legend swatches */
    .tz-cal-swatch { display:inline-block; width:12px; height:12px; border-radius:3px; vertical-align:middle; }
    .tz-cal-swatch.tz-cal-open    { background:#28a745; }
    .tz-cal-swatch.tz-cal-partial { background:#ffc107; }
    .tz-cal-swatch.tz-cal-full    { background:#dc3545; }
</style>

<hr/>

<?php

exit();
