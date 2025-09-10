<?php
namespace Stanford\TimezoneScheduler;
use REDCap;
use DateTime;
use DateTimeZone;

require_once "emLoggerTrait.php";

class TimezoneScheduler extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $config = array();   // An array of configuration settings with a concatenate unique key
    public $errors = array();   // A place to store any errors that get written to a setting for display

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
            "record_id" => $record,
            "context" => __FUNCTION__
        ], "initializeInstrument");

        $this->injectTimezoneSelector();

    }


    public function redcap_every_page_before_render( int $project_id ) {
    //    $this->emDebug(__FUNCTION__ . " called for project $project_id");
    }


    public function redcap_save_record( int $project_id, $record, string $instrument, int $event_id, $group_id, $survey_hash, $response_id, $repeat_instance ) {
       $this->emDebug(__FUNCTION__ . " called for project $project_id");
    }


    public function redcap_survey_page( int $project_id, $record, string $instrument, int $event_id, $group_id, string $survey_hash, $response_id, $repeat_instance ) {
        $this->emDebug(__FUNCTION__ . " called for project " . implode(",", func_get_args()));

        $config = $this->filter_tz_config($instrument, $event_id);

        // Example of injecting a JavaScript module object
        $this->injectJSMO([
            "config" => $config,
            "record_id" => $record,
            "context" => __FUNCTION__
        ], "initializeInstrument");

        $this->injectTimezoneSelector();
    }


    // NOT USED
    public function formatAppointments(array $appointments, string $timezone): array {
        $this->emDebug("formatAppointments called with timezone: ", $timezone);

        $formatted = [];
        $client_tz = new DateTimeZone($timezone);
        foreach ($appointments as $appointment) {
            $server_dt = new DateTime($appointment['date'] . " " . $appointment['time']);
            $client_dt = new DateTime($appointment['date'] . " " . $appointment['time'], $client_tz);

            $formatted[] = [
                'id' => $appointment['slot_id'],
                'text' => $server_dt->format('D, M jS ga (Y-m-d H:i T)'),
                'tz' => $timezone,
                'server_dt' => $server_dt->format('Y-m-d H:i'),
                'client_dt' => $client_dt->format('Y-m-d H:i')
            ];
        }

        return $formatted;
    }

    // Given an array of slot records, build the appointment options
    public function getAppointmentOptions($slots, $client_timezone, $filter_past = true) {
        $appointments = [];
        $now_dt = new DateTime("now");
        $client_dtz = new DateTimeZone($client_timezone);
        $this->emDebug("Client timezone: ", $client_dtz->getName());


        foreach ($slots as $slot_id => $data) {
            $date = $data['date'];
            $time = $data['time'];
            if (empty($date) || empty($time)) {
                $this->emError("Skipping slot $slot_id due to missing date or time", $data);
                continue;
            }

            $server_ts = $date . ' ' . $time;
            $server_dt = new DateTime($server_ts);

            if ($filter_past) {
                // Filter out past slots
                if ($server_dt < $now_dt) {
                    $this->emDebug("Skipping slot $slot_id because it is in the past: $server_ts");
                    continue;
                }
            }

            // Calculate how far in the future the slot is
            $diff = $now_dt->diff($server_dt);

            // Convert appointment time to client
            $client_dt = clone $server_dt;
            $client_dt->setTimezone($client_dtz);
            $appointments[] = [
                'id' => strval($slot_id),
                'text' => $client_dt->format('D, M jS @ ga T'),
                'client_dt' => $client_dt->format('Y-m-d H:i'),
                'server_dt' => $server_dt->format('Y-m-d H:i'),
                'diff' => $diff->format('%a days %h hours')
            ];
        }
        return $appointments;
    }

    // Get all available slots as defined by the config_key
    public function getSlots($config_key, $filter_available = true) {
        $this->emDebug("getSlots called with config_key: $config_key and filter_available:", $filter_available);
        $config = $this->get_tz_config($config_key);
        $slot_project_id = $config['slot-project-id'] ?? null;
        if (!$slot_project_id) {
            $this->emError("Invalid configuration - missing slot project id for config_key: $config_key", $config);
            return false;
        }

        // Load data from slot database
        $redcap_data = REDCap::getData($slot_project_id, 'array');
        $slots = [];
        // Filter as configured
        foreach ($redcap_data as $slot_id => $events) {
            foreach ($events as $event_id => $data) {
                // Apply project filter
                if (!empty($data['project_filter']) && $data['project_filter'] !== $this->getProjectId()) {
                    $this->emDebug("Skipping slot $slot_id due to project_id filter");
                    continue;
                }

                // Filter already reserved slots based on argument
                if ($filter_available && !empty($data['source_record_id'])) {
                    // Filter out taken slots
                    $this->emDebug("Skipping slot $slot_id because it is already taken");
                    continue;
                }

                $slots[$slot_id] = $data;
            }
        }
        return $slots;
    }


    // public function getConfigByIndex($index) {
    //     $this->load_module_config();
    //     return $this->config[$index] ?? null;
    // }


    // Return the slot record for the specified slot_id
    public function getSlot($config_key, $slot_id) {
        $this->emDebug("getSlot called with config_key: $config_key, slot_id: $slot_id");
        $config = $this->get_tz_config($config_key);

        // Pull config data for only selected record:
        $slot_project_id = $config['slot-project-id'] ?? null;
        $redcap_data = REDCap::getData($slot_project_id, 'array', [$slot_id]);
        $slot = [];
        if (empty($redcap_data[$slot_id])) {
            $this->emError("Unable to locate appointment data for config_key: $config_key with slot_id: $slot_id");
        } else {
            $slot = current($redcap_data[$slot_id]);
        }
        return $slot;
    }

    public function reserveSlot($slot_id, $config_key, $project_id, $record, $event_id, $repeat_instance) {
        $this->emDebug("reserveSlot called with slot_id: $slot_id, config_key: $config_key");
        $config = $this->get_tz_config($config_key);
        $slot_project_id = $config['slot-project-id'] ?? null;

        // Lock Slot
        // Load Slot


        // TODO TEMP
        return [
            "success" => false,
            "error" => "Failed to reserve slot",
            "message" => "Slot reserved successfully"
        ];
    }


    public function saveAppointment($payload) {
        $this->emDebug("saveAppointment called with payload: ", $payload);
        // Here you would typically make an AJAX call to save the appointment
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
        $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        // Return success and then data if true or message if false
        switch($action) {
            case "getTimezones":
                $timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
                $options = array_map(fn($tz) => ["id" => $tz, "text" => $tz], $timezones);
                $result = [
                    "success" => true,
                    "data" => $options
                ];
                break;
            case "getAppointmentOptions":
                // Return just the available appointment slots
                $this->emDebug("getAppointmentOptions called with payload: ", $payload);
                $config_key = $payload['config_key'] ?? null;
                $timezone = $payload['timezone'] ?? null;
                if (empty($timezone)) {
                    $timezone = date_default_timezone_get();
                    $this->emDebug("No client timezone provided, using server default: ", $timezone);
                }
                $slots = $this->getSlots($config_key);
                $appointment_options = $this->getAppointmentOptions($slots, $timezone);

                $message = count($appointment_options) . " appointments available";
                if (count($appointment_options) > 0) {
                    array_unshift($appointment_options, [
                        'id' => '',
                        'text' => 'Select an appointment...'
                    ]);
                }
                $result = [
                    "success" => true,
                    "timezone" => $timezone,
                    "message" => $message,
                    "data" => $appointment_options
                ];
                //sleep(1);
                break;
            case "getSlot":
                // Return just the requested slot
                $this->emDebug("getSlot called with payload: ", $payload);
                $config_key = $payload['config_key'] ?? null;
                $slot_id = $payload['slot_id'] ?? null;
                $slot = $this->getSlot($config_key, $slot_id);
                if (empty($slot)) {
                    $result = [
                        "success" => false,
                        "message" => "No appointment slot found"
                    ];
                } else {
                    $result = [
                        "success" => true,
                        "data" => $slot
                    ];
                }
                break;
            case "saveAppointment":
                // Save an appointment after selection
                $this->emDebug("saveAppointment called with payload: ", $payload);
                $result = $this->saveAppointment($payload['field_name'], $payload['slot_id'], $payload['timezone']);
                break;

            case "reserveSlot":
                // Reserve a specific appointment slot
                $this->emDebug("reserveSlot called with payload: ", $payload);
                $slot_id = $payload['slot_id'];
                $config_key = $payload['config_key'];
                $result = $this->reserveSlot($slot_id, $config_key, $project_id, $record, $event_id, $repeat_instance);
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

    // Inject HTML for timezone selector functionality
    public function injectTimezoneSelector() {
        ?>
            <!-- Button to trigger modal -->
            <button type="button" id="tz_selector_button" class="btn-primaryrc btn btn-xs float-right" data-toggle="modal" data-target="#tz_select_modal">
                Edit Timezone
            </button>
            <button type="button" id="tz_selector_cancel" class="btn-danger btn btn-xs float-right">
                Cancel
            </button>

            <!-- Template container for select slot field -->
            <div id="tz_select_container_template" class="tz_select_container" style="display:none;">
                <div class="select-value" style="width:90%;">
                        <button type="button" data-action="select-appt" class="btn-primaryrc btn btn-xs" data-toggle="modal" data-target="#tz_select_appt_modal">
                            <i class="fas fa-calendar"></i> Select An Appt
                        </button>
                </div>
                <div class="display-value" style="width:90%;">
                    <div class="form-control fs-6 selected-appointment" >
                        <i class="fas fa-calendar"></i> Mon, Jan 5th at 10:00 AM PST
                    </div>
                    <div class="pt-1">
                        <span style="font-size: 12px; color: #888;">(Slot #1234)</span>
                        <button type="button" data-action="cancel-appt" class="btn-secondary btn btn-xs float-right">
                            <i class="fas fa-times"></i> Cancel/Reschedule Appt
                        </button>
                    </div>
                </div>
            </div>

            <div id="tz_select_appt_modal" class="modal fade" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Select Appointment</h5>
                        </div>
                        <div class="modal-body" style="width:100%;">
                            <p>Select an appointment from the list below:</p>
                            <select id="tz_select_appt" class="form-control">
                                <option value="">Loading...</option>
                            </select>
                            <div>
                                <br/>
                                <span id="tz_display"></span>.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button id='tz_select_edit_timezone_button' type="button" class="btn-secondary btn btn-xs me-auto" data-toggle="modal" data-target="#tz_select_timezone_modal" data-dismiss="modal">
                                <i class="fas fa-edit"></i> Change Timezone
                            </button>
                            <button type="button" class="btn btn-secondary btn-xs" data-dismiss="modal">Close</button>
                            <button id="tz_select_save_button" type="button" class="btn btn-primaryrc btn-xs">Save</button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="tz_select_timezone_modal" class="modal fade" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Select Timezone</h5>
                            <!--button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button-->
                        </div>
                        <div class="modal-body" style="width:100%;">
                            <p>Select the timezone to use when viewing appointments:</p>
                            <select id="tz_select_timezone" class="form-control"></select>
                            <!--button type="button" class="btn btn-secondary" data-dismiss="modal">Use Browser Default</button-->
                        </div>
                        <div class="modal-footer">
                            <!--button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button-->
                            <button data-target="#tz_select_appt_modal" data-toggle="modal" data-dismiss="modal" id="tz_select_save_timezone_button" type="button" class="btn btn-primary btn-xs">Set Timezone</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php
    }


    // Get a given timezone configuration (can be enabled or disabled)
    public function get_tz_config($key) {
        if (empty($this->config)) {
            $this->load_tz_configs();
        }
        if (!isset($this->config[$key])) {
            $this->emError("Missing configuration key requested: $key", $this->config);
            return false;
        }
        if (empty($key)) {
            $this->emError("Empty key passed to getConfig - invalid");
            return false;
        }
        return $this->config[$key] ?? false;
    }

    // Function to filter timezone configuration based on instrument and event and not disabled and return
    // minimal data to client module
    public function filter_tz_config($instrument, $event_id) {
        if (empty($this->config)) {
            $this->load_tz_configs();
        }

        // Get all fields in $instrument
        $fields = REDCap::getFieldNames($instrument);
        // $this->emDebug("Fields for instrument $instrument: " . implode(", ", $fields));

        // Filter the configuration based on the instrument and event_id and has a key of the order
        // in the repeating subsettings of the module config
        // $filtered = array_filter($this->config, function($item) use ($fields, $event_id) {
        //         return in_array($item['slot-id-field'], $fields) &&
        //             $item['slot-id-field-event-id'] == $event_id &&
        //             $item['disabled'] == false;
        //     }
        // );
        // return $filtered;

        $result = [];
        foreach ($this->config as $key => $item) {
            if ($item['disabled']) continue;  // skip disabled configurations
            if ($item['slot-id-field-event-id'] != $event_id) continue; // skip wrong event
            $field = $item['slot-id-field'];
            if (!in_array($field, $fields)) continue; // skip if field not in instrument
            $result[$field] = ["config_key" => $key];
        }
        return $result;
    }

    // Builds an array of configured instances using field-event as a unique key
    private function load_tz_configs() {
        $instances = $this->getSubSettings('instances');
        if (empty($this->config)) {
            $this->config = [];
            foreach ($instances as $index => $instance) {
                // Load each instance's configuration
                $slot_project_id = $instance['slot-project-id'] ?? null;
                $field = $instance['slot-id-field'] ?? null;
                $event_id = $instance['slot-id-field-event-id'] ?? null;
                if (is_null($slot_project_id) || is_null($field) || is_null($event_id)) {
                    $this->emError('Skipping invalid configuration for instance ' . $index, $instance);
                    // skip the configuration
                    continue;
                }

                $key = $field . '-' . $event_id;
                if (isset($this->config[$key])) {
                    $this->emError('Duplicate configuration detected for instance ' . $index . ' with key ' . $key . ' - skipping this instance', $instance);
                    // skip the configuration
                    continue;
                }
                $this->config[$key] = array_merge(["key" => $key], $instance);
            }
        }
    }




	/**
	 * @return void
	 */
    private function lock($recordIds, $project_id = null){
		if(empty($recordIds)){
			// do nothing
			return;
		}


		$pid = is_null($project_id) ? $this->module->getProjectId() : $project_id;

		$query = $this->module->createQuery();
		$query->add("
			select
				record,
				event_id,
				instance,
				form_name
			from ". $this->module->getDataTable($pid)." d
			join redcap_metadata m
				on
					d.project_id = m.project_id
					and d.field_name = m.field_name
			where
				d.project_id = ?
				and
		", $pid);

		$query->addInClause('record', $recordIds);

		$query->add("group by record, event_id, instance, form_name");

		$results = $query->execute();

		$query = $this->module->createQuery();
		$query->add("insert ignore into redcap_locking_data (project_id, record, event_id, form_name, instance, timestamp) values");

		$addComma = false;
		while($row = $results->fetch_assoc()){
			if($addComma){
				$query->add(',');
			}
			else{
				$addComma = true;
			}

			$record = $row['record'];
			$eventId = $row['event_id'];
			$formName = $row['form_name'];
			$instance = $row['instance'];

			if($instance === null){
				$instance = 1;
			}

			$query->add("(?, ?, ?, ?, ? , now())", [$pid, $record, $eventId, $formName, $instance]);
		}

		$query->execute();
	}

	/**
	 * @return void
	 */
	private function unlock($recordIds, $project_id = null){
		$pid = is_null($project_id) ? $this->module->getProjectId() : $project_id;

		$query = $this->module->createQuery();
		$query->add("
			delete from redcap_locking_data
			where project_id = ?
			and
		", [$pid]);

		$query->addInClause('record', $recordIds);

		$query->execute();
	}

	/**
	 * @return bool
	 */
	private function isLocked($recordId, $project_id = null){
		$pid = is_null($project_id) ? $this->module->getProjectId() : $project_id;

		$result = $this->module->query("
			select 1
			from redcap_locking_data
			where
				project_id = ?
				and record = ?
		", [$pid, $recordId]);

		return $result->fetch_assoc() !== null;
	}


    /**
     * Injects a JavaScript Module Object (JSMO) into the page.
     * This method initializes the JSMO with the provided data and an optional initialization method.
     * @param array $data An associative array of initial data to be loaded into the JSMO.  This can alternately be done with an ajax call.
     * @param string $init_method The name of the method to call after the JSMO is initialized.
     */
    public function injectJSMO(array $data = [], string $init_method = "") {
        // $this->emDebug("Injecting JavaScript Module Object");
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


}
