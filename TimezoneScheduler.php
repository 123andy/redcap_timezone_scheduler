<?php
namespace Stanford\TimezoneScheduler;
use REDCap;
use DateTime;
use DateTimeZone;
require_once "emLoggerTrait.php";

class TimezoneScheduler extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $config = array();

    public function __construct() {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    public function redcap_data_entry_form( int $project_id, $record, string $instrument, int $event_id, $group_id, $repeat_instance ) {
        $this->emDebug(__FUNCTION__ . " called for project " . implode(",",func_get_args()));

        $config = $this->filter_tz_config($instrument, $event_id);

        // Example of injecting a JavaScript module object
        $this->injectJSMO([
            "config" => $config,
            // "project_id" => $project_id,
            // "record" => $record,
            // "instrument" => $instrument,
            // "event_id" => $event_id,
            // "group_id" => $group_id,
            // "repeat_instance" => $repeat_instance,
            "context" => __FUNCTION__
        ], "ExampleFunction");

        $this->injectTimezoneSelector();

    }


    public function redcap_every_page_before_render( int $project_id ) {
       $this->emDebug(__FUNCTION__ . " called for project $project_id");
    }


    public function redcap_save_record( int $project_id, $record, string $instrument, int $event_id, $group_id, $survey_hash, $response_id, $repeat_instance ) {
       $this->emDebug(__FUNCTION__ . " called for project $project_id");
    }


    public function redcap_survey_page( int $project_id, $record, string $instrument, int $event_id, $group_id, string $survey_hash, $response_id, $repeat_instance ) {
        $this->emDebug(__FUNCTION__ . " called for project " . implode(",", func_get_args()));

        $this->injectJSMO([
            "project_id" => $project_id,
            "record" => $record,
            "instrument" => $instrument,
            "event_id" => $event_id,
            "group_id" => $group_id,
            "survey_hash" => $survey_hash,
            "response_id" => $response_id,
            "repeat_instance" => $repeat_instance,
            "context" => __FUNCTION__
        ], "InitFunction");
    }

    /**
     * Injects a JavaScript Module Object (JSMO) into the page.
     * This method initializes the JSMO with the provided data and an optional initialization method.
     * @param array $data An associative array of initial data to be loaded into the JSMO.  This can alternately be done with an ajax call.
     * @param string $init_method The name of the method to call after the JSMO is initialized.
     */
    public function injectJSMO(array $data = [], string $init_method = "") {
        $this->emDebug("Injecting JavaScript Module Object");
        $this->initializeJavascriptModuleObject();

        $cmds = [];
        $cmds[] = "const module = " . $this->getJavascriptModuleObjectName();
        if (!empty($data)) $cmds[] = "module.data = " . json_encode($data);
        if (!empty($init_method)) $cmds[] = "module.afterRender(module." . $init_method . ")";

        // $this->emDebug($cmds);
        $spacer=";\n".str_repeat(" ",16);
        ?>
        <script src="<?=$this->getUrl("assets/jsmo.js",true)?>"></script>
        <script>
            (function() {
                <?php echo implode($spacer, $cmds) . "\n" ?>
            })()
        </script>
        <?php
    }


    public function queryAppointments($config_id, $timezone) {
        $this->emDebug("queryAppointments called with config_id: ", $config_id);
        $this->load_module_config();
        $config = $this->config[$config_id] ?? [];
        $slot_project_id = $config['slot-project-id'] ?? null;
        if (!$slot_project_id) {
            return [
                "success" => false,
                "message" => "Invalid config_id"
            ];
        }

        $redcap_data = REDCap::getData($slot_project_id, 'array');
        // $this->emDebug("Redcap data from project $slot_project_id: ", $redcap_data);

        $appointments = [];
        $this_project_id = $this->getProjectId();

        $default_timezone = date_default_timezone_get();
        $this->emDebug("Default timezone: ", $default_timezone);
        $now = new DateTime("now");

        $client_timezone = new DateTimeZone($timezone);
        $this->emDebug("Client timezone: ", $client_timezone->getName());

        foreach ($redcap_data as $slot_id => $events) {
            foreach ($events as $event_id => $data) {
                // Filter for projects
                if (!empty($data['project_filter']) && $data['project_filter'] !== $this_project_id) {
                    $this->emDebug("Skipping slot $slot_id due to project filter");
                    continue;
                }

                if (!empty($data['source_record_id'])) {
                    $this->emDebug("Skipping slot $slot_id because it is already taken");
                    continue;
                }

                $ts_string = $data['date'] . ' ' . $data['time'];
                $dt = new DateTime($ts_string);

                if ($dt < $now) {
                    $this->emDebug("Skipping slot $slot_id because it is in the past: $ts_string");
                    continue;
                }

                $dt->setTimezone($client_timezone);
                $appointments[] = [
                    'id' => $slot_id,
                    'text' => $dt->format('D, M jS ga (Y-m-d H:i T)'),
                    'tz' => $timezone,
                    'server_dt' => $ts_string,
                    'client_dt' => $dt->format('Y-m-d H:i')
                ];
            }
        }

        return [
            "success" => true,
            "data" => $appointments
        ];
    }



    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
        $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        switch($action) {
            case "getTimezones":
                $this->emDebug("getTimezones called with payload: ", $payload);
                $result = [
                    "success" => true,
                    "timezones" => DateTimeZone::listIdentifiers(DateTimeZone::ALL)
                ];
                break;
            case "getAppointments":
                // Return just the available appointment slots
                $this->emDebug("getAppointments called with payload: ", $payload);
                $result = $this->queryAppointments($payload['config_id'], $payload['timezone']);
                break;

            case "selectSlot":
                // Return just the current appointment slot (for when the value is set)
                $this->emDebug("selectSlot called with payload: ", $payload);
                $result = [
                    "success" => true,
                    "message" => "Slot selected successfully"
                ];
                break;
            case "selectAvailableSlots":
                // Return just the available appointment slots
                $this->emDebug("selectAvailableSlots called with payload: ", $payload);
                $result = [
                    "success" => true,
                    "available_slots" => [] // $this->getAvailableSlots()
                ];
                break;

            case "TestAction":
                $result = [
                    "success"=>true,
                    "user_id"=>$user_id
                ];
                break;
            default:
                // Action not defined
                throw new \Exception ("Action $action is not defined");
        }

        // Return is left as php object, is converted to json automatically
        return $result;
    }

    public function injectTimezoneSelector() {
        ?>
            <!-- Button to trigger modal -->
            <button type="button" id="tz_selector_button" class="btn-primaryrc btn btn-xs float-right" data-toggle="modal" data-target="#tz_select_modal">
                Edit Timezone
            </button>
            <div id="tz_select_modal" class="modal fade" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Select Timezone</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body" style="width:100%;">
                            <p>Timezone selection content goes here.</p>
                            <select id="tz_select" class="form-control"></select>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            <button id="tz_select_save_button" type="button" class="btn btn-primary">Save</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php
    }

    # Function to filter timezone configuration based on instrument and event (and active)
    public function filter_tz_config($instrument, $event_id) {
        if (empty($this->config)) {
            $this->load_module_config();
        }

        // Get all fields in $instrument
        $fields = REDCap::getFieldNames($instrument);
        // $this->emDebug("Fields for instrument $instrument: " . implode(", ", $fields));

        // Filter the configuration based on the instrument and event_id and has a key of the order
        // in the repeating subsettings of the module config
        $filtered = array_filter($this->config, function($item) use ($fields, $event_id) {
            return in_array($item['slot-id-field'], $fields) &&
                $item['slot-id-field-event-id'] == $event_id &&
                $item['disabled'] == false;
        });
        return $filtered;
    }


    public function load_module_config() {
        $instances = $this->getSubSettings('instances');
        $this->config = $instances;
        // $config = [];
        // foreach($instances as $instance) {
        //     // Load each instance's configuration
        //     $config[$instance['slot-id-field']] = $instance;
        // }

        // $this->emDebug($config);
    }



}
