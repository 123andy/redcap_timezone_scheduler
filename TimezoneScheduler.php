<?php
namespace Stanford\TimezoneScheduler;
use REDCap;
use DateTime;
use DateTimeZone;
use Exception;

require_once "classes/TimezoneException.php";
require_once "emLoggerTrait.php";

class TimezoneScheduler extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $config = array();   // An array of configuration settings with a concatenate unique key
    public $errors = array();   // A place to store any errors that get written to a setting for display

    const DEFAULT_APPOINTMENT_DESCRIPTION_FORMAT = "{title} (#{slot_id})\n{date} at {time} {server-tza} ({client-time} {client-tza})";
    const DEFAULT_APPT_BUTTON_LABEL = "Select An Appointment";


    public function redcap_data_entry_form( int $project_id, $record, string $instrument, int $event_id, $group_id, $repeat_instance ) {
        //$this->emDebug(__FUNCTION__ . " called for project " . implode(",",func_get_args()));

        $config = $this->filter_tz_config($instrument, $event_id);

        $this->injectJSMO([
            "config" => $config,
            "record_id" => $record,
            "context" => __FUNCTION__
        ], "initializeInstrument");

        $this->injectHTML();

    }


    public function redcap_survey_page( int $project_id, $record, string $instrument, int $event_id, $group_id, string $survey_hash, $response_id, $repeat_instance ) {
        $config = $this->filter_tz_config($instrument, $event_id);

        $this->injectJSMO([
            "config" => $config,
            "record_id" => $record,
            "context" => __FUNCTION__
        ], "initializeInstrument");

        $this->injectHTML();
    }


    /**
     * Get the appointment id value (aka slot_id) for the current record
     * @param string $config_key The configuration key to use
     * @param int $record The record id
     * @param int $event_id The event id
     * @param int $repeat_instance The repeat instance
     * @return mixed The slot_id or null if not found
     * @throws TimezoneException
     */
    public function getCurrentAppointmentId($config_key, $record, $event_id, $repeat_instance) {
        $this->emDebug("getCurrentAppointmentId called with config_key: $config_key, record: $record, event_id: $event_id, repeat_instance: $repeat_instance");
        $config = $this->get_tz_config($config_key);
        $slot_id_field = $config['appt-field'] ?? null;

        if (empty($slot_id_field)) {
            $this->emError("Invalid configuration - missing slot id field for config_key: $config_key", $config);
            throw new TimezoneException("Invalid configuration - missing slot id field for config_key: $config_key");
        }

        // Get the slot_id from the current record
        // TODO -- handle repeat instances?  switch to json?
        $redcap_data = REDCap::getData('array', [$record], [$slot_id_field], $event_id);
        $this->emDebug("Redcap data for record $record: ", $redcap_data);
        if (empty($redcap_data) || empty($redcap_data[$record]) || empty($redcap_data[$record][$event_id]) || empty($redcap_data[$record][$event_id][$slot_id_field])) {
            $this->emDebug("No slot_id found for record $record in event $event_id in field $slot_id_field", $redcap_data);
            throw new TimezoneException("No slot_id found for record $record in event $event_id in field $slot_id_field");
        }
        $slot_id = $redcap_data[$record][$event_id][$slot_id_field] ?? null;

        // if (empty($slot_id)) {
        //     $this->emDebug("Empty slot_id found for record $record in field $slot_id_field");
        //     throw new TimezoneException("Empty slot_id found for record $record in field $slot_id_field");
        // }

        return $slot_id;
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
            $title = $data['title'] ?? '';
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
                'title' => $title,
                'text' => $client_dt->format('D, M jS @ ga T'),
                'client_dt' => $client_dt->format('Y-m-d H:i'),
                'server_dt' => $server_dt->format('Y-m-d H:i'),
                'diff' => $diff->format('%a days %h hours')
            ];
        }
        return $appointments;
    }


// Given an array of slot records, build the appointment options
    public function getAppointmentOptions2($config_key, $slots, $client_timezone, $filter_past = true) {
        $config = $this->get_tz_config($config_key);
        $appointments = [];
        $now_dt = new DateTime("now");
        $client_dtz = new DateTimeZone($client_timezone);
        $this->emDebug("Client timezone: ", $client_dtz->getName());

        $description_format = $config['appt-description-format'] ?? self::DEFAULT_APPOINTMENT_DESCRIPTION_FORMAT;

        foreach ($slots as $slot_id => $data) {
            $date = $data['date'];
            $time = $data['time'];
            $title = $data['title'] ?? '';
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

            // Convert appointment time to client timezone
            $client_dt = clone $server_dt;
            $client_dt->setTimezone($client_dtz);

            $codex = [
                '{slot_id}' => $slot_id,
                '{title}' => $title,
                '{date}' => $date,
                '{time}' => $time,
                '{server-time}' => $server_dt->format('g:i A'),
                '{server-nicedate}' => $server_dt->format('D, M jS, Y'),
                '{server-date}' => $server_dt->format('m/d/Y'),
                '{server-ts}' => $server_ts,
                '{server-tza}' => $server_dt->format('T'),
                '{client-time}' => $client_dt->format('g:i A'),
                '{client-nicedate}' => $client_dt->format('D, M jS, Y'),
                '{client-tza}' => $client_dt->format('T'),
                '{client-date}' => $client_dt->format('m/d/Y'),
                '{client-ts}' => $client_dt->format('Y-m-d H:i'),
                '{client-tz}' => $client_dtz->getName(),
                '{diff}' => $diff->format('%a days %h hours')
            ];

            $appt_description = str_replace( array_keys($codex), array_values($codex), $description_format );

            // Replace anything between <== and ==> with nothing if timezones match
            if ($client_dt->format('T') == $server_dt->format('T')) {
                $re = '/<==[.\s\w\W]*==>/mU';
                $appt_description = preg_replace($re, '', $appt_description);
            } else {
                $appt_description = str_replace( ['<==', '==>'], '', $appt_description);
            }


            $appointments[] = [
                'id' => strval($slot_id),
                'title' => $title,
                'description' => $appt_description,
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


    // Return the slot record for the specified slot_id
    public function getSlot($config_key, $slot_id) {
        $this->emDebug("getSlot called with config_key: $config_key, slot_id: $slot_id");
        $config = $this->get_tz_config($config_key);

        // Pull config data for only selected record:
        // switching to json format so we can easily re-save and don't need the event_id...
        $slot_project_id = $config['slot-project-id'] ?? null;
        $redcap_query = REDCap::getData($slot_project_id, 'json', [$slot_id]);
        $redcap_data = json_decode($redcap_query, true);
        // $this->emDebug("redcap_data for slot_id $slot_id: ", $redcap_data);
        if (empty($redcap_data)) {
            $this->emError("Unable to locate slot data for config_key: $config_key with slot_id: $slot_id");
        } else {
            // Just take the current (only) record returned
            $redcap_data = current($redcap_data);
        }
        return $redcap_data;
    }


    // Save a slot via json where slot_data contains record_id and all data -- return true/false depending on success
    public function saveSlot($config_key, $slot_data) {
        $this->emDebug("saveSlot called with config_key: $config_key, slot: ", $slot_data);
        $config = $this->get_tz_config($config_key);
        $slot_project_id = $config['slot-project-id'] ?? null;
        if (!$slot_project_id) {
            $this->emError("Invalid configuration - missing slot project id for config_key: $config_key", $config);
            return false;
        }

        // Save data to slot database
        $params = array(
            'project_id' => $slot_project_id,
            'dataFormat'=>'json',
            'data'=> json_encode([$slot_data]),
            'overwriteBehavior' => 'overwrite'
        );
        $result = REDCap::saveData($params);
        if(!empty($result['errors'])) {
            $this->emError("Error saving slot data for config_key: $config_key, slot:", $slot_data, $result['errors']);
            return false;
        }
        $this->emDebug("REDCap saveData result: ", $result);
        return true;
    }


    public function cancelAppointment($slot_id, $config_key, $record, $event_id, $repeat_instance) {
        $this->emDebug("cancelAppointment called with config_key: $config_key, slot_id: $slot_id, record: $record, event_id: $event_id, repeat_instance: $repeat_instance");
        $config = $this->get_tz_config($config_key);
        $slot_project_id = $config['slot-project-id'] ?? null;
        if (!$slot_project_id) {
            $this->emError("Invalid configuration - missing slot project id for config_key: $config_key", $config);
            return false;
        }

        // First get the slot db record
        $slot = $this->getSlot($config_key, $slot_id);
        $this->emDebug("Slot record for slot_id $slot_id: ", $slot);
        if (empty($slot)) {
            $this->emError("Unable to locate slot data for config_key: $config_key with slot_id: $slot_id prior to clearing slot");
        } else {
            // Clear out the reservation fields
            $slot['source_project_id'] = null;
            $slot['source_record_id'] = null;
            $slot['source_field'] = null;
            $slot['source_event_id'] = null;
            $slot['source_instance_id'] = null;
            $slot['source_record_url'] = null;
            $slot['reserved_ts'] = null;
            $slot['participant_timezone'] = null;
            $slot['participant_description'] = null;
            $slot['slots_complete'] = 0;

            // Save the cleared slot
            $save = $this->saveSlot($config_key, $slot);
            if (!$save) {
                $this->emError("Error clearing slot data for config_key: $config_key, slot:", $slot);
                return [
                    "success" => false,
                    "message" => "Error clearing slot db data for config_key: $config_key, slot_id: $slot_id"
                ];
            }
        }

        // Now lets clear the current record
        $data = [];
        $data[$config['appt-field']] = null;
        if ($config['appt-datetime-field']) $data[$config['appt-datetime-field']] = null;
        if ($config['appt-participant-formatted-date-field']) $data[$config['appt-participant-formatted-date-field']] = null;
        if ($config['slot-record-url-field']) $data[$config['slot-record-url-field']] = null;
        if ($config['slot-record-url-field']) $data[$config['slot-record-url-field']] = null;
        $params = [
            'data' => [$record => [ $event_id => $data ]],
            'overwriteBehavior' => 'overwrite'
        ];
        $q = REDCap::saveData($params);
        $this->emDebug("REDCap::saveData on clear result: ", $q);
        $result = [
            "success" => true,
            "data" => $data
        ];
        return $result;
    }

    public function reserveSlot($slot_id, $config_key, $timezone, $text, $server_dt, $project_id, $record, $instrument, $event_id, $repeat_instance) {
        $config = $this->get_tz_config($config_key);
        $slot_project_id = $config['slot-project-id'] ?? null;

        /*
            [slot_id] => 13
                [title] =>
                [date] => 2025-09-25
                [time] => 08:00
                [project_filter] => 38
                [custom_field_1] =>
                [filter] =>
            [source_project_id] =>
            [source_record_id] =>
            [source_field] =>
            [source_event_id] =>
            [source_instance_id] =>
            [source_record_url] =>
            [reserved_ts] =>
            [participant_timezone] =>
            [participant_description] =>
            [slots_complete] => 0
        */

        // Lock Slot
        $lock_name = "tzs_slot_" . $slot_id . "_proj_" . $slot_project_id;
        if (!$this->getLock($lock_name)) {
            $this->emError("Unable to obtain lock for $lock_name");
            return [
                "success" => false,
                "message" => "Unable to obtain a lock for the requested slot - please try again."
            ];
        } else {
            $this->emDebug("Lock obtained for $lock_name");
        }

        // First get the slot record
        $slot = $this->getSlot($config_key, $slot_id);
        $this->emDebug("Slot record for slot_id $slot_id: ", $slot);
        if (empty($slot)) {
            $this->emError("Unable to locate slot data for config_key: $config_key with slot_id: $slot_id prior to reservation");
            $this->releaseLock($lock_name);
            return [
                "success" => false,
                "message" => "Unable to locate the requested slot - please try again."
            ];
        }

        // Load Slot
        $result = [];
        if ($slot['reserved_ts']) {
            $this->emDebug("Slot $slot_id is already reserved");
            $result = [
                "success" => false,
                "message" => "Slot is already reserved - please try again."
            ];
        } else {
            // Find description for slot and timezone
            $results = $this->getAppointmentOptions2($config_key, [$slot_id => $slot], $timezone);
            $appointment = $results[0] ?? null;

            if (empty($appointment)) {
                $this->emError("Unable to locate appointment data for slot_id $slot_id in timezone $timezone");
                $this->releaseLock($lock_name);
                return [
                    "success" => false,
                    "message" => "Unable to locate the requested slot in the selected timezone - please try again."
                ];
            }

            // Reserve slot
            $slot['source_project_id'] = $project_id;
            $slot['source_record_id'] = $record;
            $slot['source_field'] = $config['appt-field'] ?? '';
            $slot['source_event_id'] = $event_id;
            $slot['source_instance_id'] = $repeat_instance;
            // https://redcap.local/redcap_v15.3.3/DataEntry/index.php?pid=38&id=1&page=test_form&event_id=88&instance=1
            // TODO replace with a EM-mediated redirect to get rid of the version number...
            $slot['source_record_url'] = APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION .
                "/DataEntry/index.php?pid=$project_id&id=$record&page=$instrument&event_id=$event_id&instance=$repeat_instance";
            $slot['reserved_ts'] = date('Y-m-d H:i:s');
            $slot['participant_timezone'] = $timezone;
            $slot['participant_description'] = $appointment['description'] ?? $appointment['text'] ?? 'Unable to parse appointment';
            // $slot['participant_description'] = $text;
            $slot['slots_complete'] = 2;

            $save = $this->saveSlot($config_key, $slot);
            if ($save) {
                // Lets also update the current record so that the slot_id is saved here as well
                $data = [];
                $data[$config['appt-field']] = $slot_id;
                if ($config['appt-datetime-field']) {
                    $data[$config['appt-datetime-field']] = $server_dt;
                }
                if ($config['appt-participant-formatted-date-field']) {
                    $data[$config['appt-participant-formatted-date-field']] = $text;
                }
                // if ($config['appt-description-field']) {
                //     $data[$config['appt-description-field']] = $description;
                // }
                if ($config['slot-record-url-field']) {
                    //https://redcap.local/redcap_v15.3.3/DataEntry/index.php?pid=40&id=14&page=slots
                    $data[$config['slot-record-url-field']] = APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION . '/DataEntry/index.php?pid=' . $slot_project_id . '&id=' . $slot_id . '&page=slots';
                }

                $q = REDCap::saveData('array', [$record => [ $event_id => $data ]]);
                $this->emDebug("REDCap::saveData result: ", $q);
                $result = [
                    "success" => true,
                    "data" => $data
                ];
            } else {
                $result = [
                    "success" => false,
                    "message" => "Failed to reserve slot - please try again.",
                ];
            }
        }

        // Release Lock
        $this->releaseLock($lock_name);
        return $result;
    }


    // public function saveAppointment($payload) {
    //     $this->emDebug("saveAppointment called with payload: ", $payload);
    //     // Here you would typically make an AJAX call to save the appointment
    // }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
        $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        // Return success and then data if true or message if false
        $this->emDebug("$action called with payload: ", $payload);

        try {
            switch($action) {
                case "getTimezones":
                    // GOOD! Now using groups to sort by continent
                    $timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
                    // $options = array_map(fn($tz) => ["id" => $tz, "text" => $tz], $timezones);
                    $grouped = [];
                    foreach ($timezones as $tz) {
                        $parts = explode('/', $tz, 2);
                        $region = $parts[0];
                        if (!isset($grouped[$region])) {
                            $grouped[$region] = [];
                        }
                        $grouped[$region][] = ["id" => $tz, "text" => $tz];
                    }
                    $options = [];
                    foreach ($grouped as $region => $zones) {
                        $options[] = [
                            "text" => $region,
                            "children" => $zones
                        ];
                    }
                    $result = [
                        "success" => true,
                        "data" => $options
                    ];
                    break;
                case "getAppointmentOptions":
                    // GOOD: Return just the available appointment slots
                    $this->emDebug("getAppointmentOptions called with payload: ", $payload);
                    $config_key = $payload['config_key'] ?? null;
                    $timezone = $payload['timezone'] ?? null;
                    if (empty($timezone)) {
                        $timezone = date_default_timezone_get();
                        $this->emDebug("No client timezone provided, using server default: ", $timezone);
                    }
                    // Query all available slots
                    $slots = $this->getSlots($config_key, true);

                    // Convert slots into appointment options
                    // TODO: Consider grouping the options by date (if there are multiple per day, perhaps, otherwise there would be too many groups) - could have 'group by day, week, month options?'
                    $appointment_options = $this->getAppointmentOptions2($config_key, $slots, $timezone);

                    $count = count($appointment_options);
                    $message = $count . " appointment" . ($count === 1 ? "" : "s") . " available";
                    if ($count > 0) {
                        array_unshift($appointment_options, [
                            'id' => '',
                            'text' => 'Select an appointment...'
                        ]);
                    }
                    $result = [
                        "success" => true,
                        "timezone" => $timezone,
                        "message" => $message,
                        "count" => $count,
                        "data" => $appointment_options
                    ];
                    break;
                case "getSlot":
                    // Return just the requested slot
                    // TODO: Review return format -- should go through a formatting function for dates...
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
                case "getAppointmentData":
                    // Called when an appointment field contains a slot_id and we need to return the appointment data for that slot
                    $config_key = $payload['config_key'];
                    $slot_id = $this->getCurrentAppointmentId($config_key, $record, $event_id, $repeat_instance);

                    // Make sure we have a slot_id
                    if (!$slot_id) {
                        $result = [
                            "success" => false,
                            "message" => "No appointment saved for this record"
                        ];
                        $this->emDebug("No slot_id found for record $record with $config_key");
                    } else {
                        $this->emDebug("Found existing slot_id: $slot_id for record $record");
                        $timezone = $payload['timezone'] ?? date_default_timezone_get();
                        $result = [];
                        // Now get the slot record from the slot db
                        $slot = $this->getSlot($config_key, $slot_id);
                        if (empty($slot)) {
                            throw new TimezoneException("Record $record has appointment slot_id saved ($slot_id), but unable to locate record slot database: $config_key");
                        } else {
                            $this->emDebug("Slot record for slot_id $slot_id: ", $slot);
                            // Sanity check - make sure the slot is actually reserved for this record
                            if ($slot['source_record_id'] != $record || $slot['source_event_id'] != $event_id || $slot['source_instance_id'] != $repeat_instance) {
                                $this->emError("Record $record has appointment slot_id saved ($slot_id), but that slot's source data does not match.");
                                // For now, I'm going to keep going, but perhaps we should throw a TimezoneException here...
                            }

                            $result = [
                                "success" => true,
                                "data" => [
                                    "id" => $slot_id,
                                    "description" => $slot['participant_description'] ?? "Missing description for slot $slot_id",
                                    "timezone" => $slot['participant_timezone']
                                ]
                            ];

                            // $appointments = $this->getAppointmentOptions2($config_key, [$slot_id => $slot], $timezone, false);
                            // $this->emDebug("Appointment data for slot_id $slot_id: ", $appointments);
                            // $result = [
                            //     "success" => true,
                            //     "data" => $appointments[0] ?? null
                            // ];
                        }
                    }
                    break;
                // case "saveAppointment":
                //     // Save an appointment after selection
                //     $this->emDebug("saveAppointment called with payload: ", $payload);
                //     $result = $this->saveAppointment($payload['field_name'], $payload['slot_id'], $payload['timezone']);
                //     break;

                case "reserveSlot":
                    // Reserve a specific appointment slot
                    $this->emDebug("reserveSlot called with payload: ", $payload);
                    $slot_id = $payload['slot_id'];
                    $config_key = $payload['config_key'];
                    $timezone = $payload['timezone'] ?? null;

                    // TODO: I think text and server+dt are redundant since they can be derived from the slot_id and timezone
                    $text = $payload['text'] ?? null;
                    $server_dt = $payload['server_dt'] ?? null;
                    $result = $this->reserveSlot($slot_id, $config_key, $timezone, $text, $server_dt, $project_id, $record, $instrument, $event_id, $repeat_instance);
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

                case "cancelAppointment":
                    $this->emDebug("cancelAppointment called with payload: ", $payload);
                    $config_key = $payload['config_key'] ?? null;
                    $slot_id = $payload['slot_id'] ?? null;
                    $result = $this->cancelAppointment($slot_id, $config_key, $record, $instrument, $event_id, $repeat_instance);
                    // $result = [
                    //     "success"=>true,
                    //     "user_id"=>$user_id
                    // ];
                    break;
                default:
                    // Action not defined
                    throw new Exception ("Action $action is not defined");
            }
         } catch (TimezoneException $e) {
            $this->emError("TimezoneException caught in redcap_module_ajax for action $action: " . $e->getMessage(), $e, $payload);
            $result = [
                "success" => false,
                "message" => $e->getMessage()
            ];
        } catch (Exception $e) {
            $this->emError("Unknown Exception caught in redcap_module_ajax for action $action: " . $e->getMessage(), $e, $payload);
            $result = [
                "success" => false,
                "message" => "An exception occurred -- please check the server logs"
            ];
        }

        // Return is left as php object, is converted to json automatically
        return $result;
    }

    // Inject HTML for timezone selector functionality
    public function injectHTML() {
        ?>
            <!-- Button to trigger appointment modal -->
            <button type="button" id="tz_selector_button" class="btn-primaryrc btn btn-xs float-right" data-toggle="modal" data-target="#tz_select_modal">
                Edit Timezone
            </button>
            <button type="button" id="tz_selector_cancel" class="btn-danger btn btn-xs float-right">
                Cancel
            </button>

            <!-- Template container for appointment slot id field -->
            <div id="tz_select_container_template" class="tz_select_container" style="display:none;">
                <div class="select-value" style="width:90%; display:none;">
                        <button type="button" data-action="select-appt" class="btn-primaryrc btn btn-sm" data-toggle="modal" data-target="#tz_select_appt_modal">
                            <i class="fas fa-calendar"></i><span class='button-text pl-2'>Select An Appointment</span>
                        </button>
                </div>
                <div class="display-value" style="width:90%; display:none;">
                    <div class="form-control selected-appointment" style="font-size: 13px;">
                        <i class="fas fa-calendar"></i> <span class="appt-text">PLACEHOLDER</span></div>
                    <div class="pt-1">
                        <span style="font-size: 12px; color: #888;" class="slot-id"></span>
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

            <div id="tz_select_confirm_modal" class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Template Header</h5>
                        <!-- <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button> -->
                    </div>
                    <div class="modal-body">
                        Are you sure?
                    </div>
                    <div class="modal-footer">
                        <button type="button" data-action="cancel" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" >Go Back</button>
                        <button type="button" data-action="delete" class="btn btn-sm btn-danger" data-bs-dismiss="modal" >Cancel Appointment</button>
                        <button type="button" data-action="ok" class="btn btn-sm btn-primary" data-bs-dismiss="modal">OK</button>
                    </div>
                    </div>
                </div>
            </div>

            <style>
                .select2-container--default .select2-results__group {
                    cursor: default;
                    display: block;
                    background-color: #337ab7;
                    color: white;
                }

                li.select2-results__option {
                    border-top: 1px solid #eee;
                }
            </style>
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

        $result = [];
        foreach ($this->config as $key => $item) {
            if ($item['disabled']) continue;  // skip disabled configurations
            if ($item['appt-event-id'] != $event_id) continue; // skip wrong event
            $field = $item['appt-field'];
            if (!in_array($field, $fields)) continue; // skip if field not in current instrument
            $result[$field] = [
                "config_key" => $key,
                "appt-button-label" => $item['appt-button-label'] ?? self::DEFAULT_APPT_BUTTON_LABEL,
            ];
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
                $field = $instance['appt-field'] ?? null;
                $event_id = $instance['appt-event-id'] ?? null;
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
     * This function retrieves a DB lock
     *
     * @param $lock_name
     * @return bool
     */
    private function getLock($lock_name) {
        $result = $this->query("SELECT GET_LOCK(?, 5)", [$lock_name]);
        $row = $result->fetch_row();
        if ($row[0] !== 1) {
            $this->emDebug("Unable to obtain lock: $lock_name");
            $status = false;
        } else {
            $this->emDebug("Obtained Lock: $lock_name");
            $status = true;
        }
        return $status;
    }

    /**
     * This function releases the DB lock
     *
     * @param $lock_name
     * @return void
     */
    private function releaseLock($lock_name) {
        // Obtain lock for reward library
        if ($lock_name != null) {
            $result = $this->query("select RELEASE_LOCK(?)", [$lock_name]);
            $row = $result->fetch_row();
            $this->emDebug("Released Lock: " . $lock_name . ", with status " . $row[0]);
        }
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
        if ($this->emLoggerDebug) $cmds[] = "module.debugger=true";
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


    ////////////////////////////////

    public function redcap_every_page_before_render( int $project_id ) {
    //    $this->emDebug(__FUNCTION__ . " called for project $project_id");
    }


    public function redcap_save_record( int $project_id, $record, string $instrument, int $event_id, $group_id, $survey_hash, $response_id, $repeat_instance ) {
       $this->emDebug(__FUNCTION__ . " called for project $project_id");
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



}
