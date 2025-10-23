<?php
namespace Stanford\TimezoneScheduler;
use REDCap;
use DateTime;
use DateTimeZone;
use DateInterval;
use Exception;

require_once "classes/TimezoneException.php";
require_once "emLoggerTrait.php";

class TimezoneScheduler extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $config = array();   // An array of configuration settings with a concatenate unique key
    public $errors = array();   // A place to store any errors that get written to a setting for display

    const DEFAULT_APPT_TEXT_DATE_FORMAT = "{client-nicedate} at {client-time} {client-tza}";
    const DEFAULT_APPT_DESCRIPTION_FORMAT = "{title} (#{slot_id})\n{client-nicedate} at {client-time} {client-tza}<==\n({server-time} {server-tza})==>";
    const DEFAULT_APPT_BUTTON_LABEL = "Select An Appointment";

    public $lock_name = '';

    // For client fields, we need many more formats to match REDCap client validation
    const VALIDATION_CLIENT_CONVERSION_INDEX = [
        'datetime_ymd' => 'Y-m-d H:i',
        'datetime_mdy' => 'm-d-Y H:i',
        'datetime_dmy' => 'd-m-Y H:i',
        'date_ymd' => 'Y-m-d',
        'date_mdy' => 'm-d-Y',
        'date_dmy' => 'd-m-Y',
        'datetime_seconds_ymd' => 'Y-m-d H:i:s',
        'datetime_seconds_dmy' => 'd-m-Y H:i:s',
        'datetime_seconds_mdy' => 'm-d-Y H:i:s'
    ];

    // For server date, we always use the YMD style
    const VALIDATION_SERVER_CONVERSION_INDEX = [
        'datetime_ymd' => 'Y-m-d H:i',
        'datetime_mdy' => 'Y-m-d H:i',
        'datetime_dmy' => 'Y-m-d H:i',
        'date_ymd' => 'Y-m-d',
        'date_mdy' => 'Y-m-d',
        'date_dmy' => 'Y-m-d',
        'datetime_seconds_ymd' => 'Y-m-d H:i:s',
        'datetime_seconds_dmy' => 'Y-m-d H:i:s',
        'datetime_seconds_mdy' => 'Y-m-d H:i:s'
    ];

    const RESERVED_FIELD_NAMES = [
        'redcap_event_name',
        'redcap_repeat_instrument',
        'redcap_repeat_instance'
    ];

    public function redcap_data_entry_form( int $project_id, $record, string $instrument, int $event_id, $group_id, $repeat_instance ) {
        //$this->emDebug(__FUNCTION__ . " called for project " . implode(",",func_get_args()));

        $config = $this->filter_tz_config($instrument, $event_id);

        $this->injectJSMO([
            "config" => $config,
            "record_id" => $record,
            "repeat_instance" => $repeat_instance,
            "context" => __FUNCTION__
        ], "initializeInstrument");

        $this->injectHTML();

    }


    public function redcap_survey_page( int $project_id, $record, string $instrument, int $event_id, $group_id, string $survey_hash, $response_id, $repeat_instance ) {
        $config = $this->filter_tz_config($instrument, $event_id);

        $this->injectJSMO([
            "config" => $config,
            "record_id" => $record,
            "repeat_instance" => $repeat_instance,
            "context" => __FUNCTION__
        ], "initializeInstrument");

        $this->injectHTML();
    }


    /**
     * Verify the slots in the slot database for consistency
     * @param string $config_key The configuration key to use
     * @return array The slots with status, errors, and actions added
     * @throws TimezoneException
     *
     */
    public function verifySlots() {
        $this->load_tz_configs();
        $q = [];
        $now_dt = new DateTime("now");
        $results = [];
        $appt_map = [];

        foreach($this->config as $config_key => $config) {
            // $this->emDebug("Checking slots for config $config_key: ", $config);

            // Get All Appointments for this config
            $appt_field = $config['appt-field'];
            $appts = $this->getRecords($config_key);
            $this->emDebug("Found " . count($appts) . " appointments for config_key $config_key");
            foreach ($appts as $appt) {
                $appt_event_name = $appt['redcap_event_name'] ?? null;
                $appt_instance = $appt['redcap_repeat_instance'] ?? null;

                $appt_record = $appt[REDCap::getRecordIdField()];
                $appt_event_id = REDCap::getEventIdFromUniqueEvent($appt_event_name);
                $appt_repeat_instance = $appt_instance ? $appt_instance : 1;
                $appt_slot_id = $appt[$appt_field];

                if (!isset($appt_map[$appt_slot_id])) {
                    $appt_map[$appt_slot_id] = [];
                }

                $appt_map[$appt_slot_id][] = [
                    'appt_project_id' => $this->getProjectId(),
                    'appt_field' => $appt_field,
                    'appt_record' => $appt_record,
                    'appt_event_id' => $appt_event_id,
                    'appt_repeat_instance' => $appt_repeat_instance,
                    'config_key' => $config_key
                ];
            }
        }
        // $this->emDebug("Appointment Maps for all config_keys:", $appt_map);

        foreach($this->config as $config_key => $config) {
            // Get All Slots for this config
            $slots = $this->getSlots($config_key, false);
            $this->emDebug("Verifying " . count($slots) . " slots for config_key $config_key");
            $slot_project_id = $config['slot-project-id'];

            // Get all the slots for this config
            foreach ($slots as $slot_id => $slot) {
                if (isset($results[$slot_id]) && $results[$slot_id]['slot_project_id'] === $slot_project_id) {
                    $results[$slot_id]['config_keys'][] = $config_key;
                    continue;
                }
                $errors = [];
                $actions = [];

                $status = "Available";
                $reserved = $slot['reserved_ts'] ?? null;
                $slot_ts = $slot['date'] . " " . $slot['time'];
                $slot_dt = new DateTime($slot_ts);
                $is_past = $now_dt > $slot_dt;
                $record = $slot['source_record_id'] ?? null;

                // Go through logic to determine status
                if (empty($record)) {
                    if ($reserved) {
                        // Only cancelled records are reserved without a record
                        if ($is_past) {
                            $status = "Cancelled (past)";
                            $note = $slot['source_project_title'] ?? '';
                        } else {
                            // Previously cancelled
                            $status = "Cancelled";
                            $note = $slot['source_project_title'] ?? '';
                            $actions[] = [
                                "label" => "UnCancel (make available)",
                                "action" => "resetSlot",
                                "params" => [
                                    "config_key" => $config_key,
                                    "slot_id" => $slot_id
                                ]
                            ];
                        }
                    } else {
                        // Available records are unreserved without a record
                        $status = "Available";
                        $actions[] = [
                            "label" => "Cancel Slot",
                            "action" => "cancelSlot",
                            "params" => [
                                "config_key" => $config_key,
                                "slot_id" => $slot_id
                            ]
                        ];
                    }
                } else {
                    // Has a record
                    if ($reserved) {
                        if ($is_past) {
                            $status = "Reserved (past)";
                        } else {
                            $status = "Reserved";
                            $actions[] = [
                                "label" => "Reset Appt and Slot",
                                "action" => "resetSlotAndAppointment",
                                "params" => [
                                    "config_key" => $config_key,
                                    "record" => $record,
                                    "instance" => $slot['source_instance_id'],
                                    "slot_id" => $slot['slot_id']
                                ]
                            ];
                        }

                        // See if it points to an apptointment, if so, verify the appointment also points back to this slot
                        $source_field = $slot['source_field'] ?? null;
                        $source_project_id = $slot['source_project_id'] ?? null;
                        $source_record_id = $slot['source_record_id'] ?? null;
                        $source_event_id = $slot['source_event_id'] ?? null;
                        $source_instance_id = $slot['source_instance_id'] ?? null;

                        if (empty($appt_map[$slot_id])) {
                            $errors[] = "Slot $slot_id points to appointment record $source_record_id / field $source_field in project $source_project_id, but that field does not point back to this slot.";
                        } else {
                            if (count($appt_map[$slot_id]) > 1) {
                                $errors[] = "Slot $slot_id is claimed by more than one appointment: <pre>" . json_encode($appt_map[$slot_id] . "</pre>");
                            } else {
                                $appt_info = $appt_map[$slot_id][0];
                                if ($appt_info['appt_record'] != $source_record_id
                                    || $appt_info['appt_event_id'] != $source_event_id
                                    || $appt_info['appt_repeat_instance'] != $source_instance_id
                                ) {
                                    $errors[] = "Slot $slot_id does not match the appointment record that claims it: project $source_project_id, record $source_record_id, field $source_field - please investigate.";
                                }
                            }
                        }
                    } else {
                        // Has record but isn't reserved -- this shouldn't happen
                        $errors[] = "Slot $slot_id is assigned to record $record but is not marked as reserved.";
                        $status = "Error";
                        $actions[] = [
                            "label" => "Reset Slot",
                            "action" => "resetSlot",
                            "params" => [
                                "config_key" => $config_key,
                                "slot_id" => $slot_id
                            ]
                        ];
                        $actions[] = [
                            "label" => "Cancel Slot",
                            "action" => "cancelSlot",
                            "params" => [
                                "config_key" => $config_key,
                                "slot_id" => $slot_id
                            ]
                        ];
                    }
                }

                $results[$slot_id] = [
                    'slot_project_id' => $slot_project_id,
                    'config_keys' => [$config_key],
                    'slot_id' => $slot_id,
                    'slot_url' => APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION . "/DataEntry/index.php?pid=" . $config['slot-project-id'] . "&id=$slot_id&page=slots",
                    'date' => $slot['date'],
                    'project_filter' => $slot['project_filter'] ?? '',
                    'slot_filter' => $slot['slot_filter'] ?? '',
                    'time' => $slot['time'],
                    'is_past' => $is_past,
                    'note' => $note ?? '',
                    'status' => $status,
                    'errors' => implode('|', $errors),
                    'actions' => $actions,
                    'source_record_url' => $slot['source_record_url'] ?? '',
                    'source_record_id' => $record,
                    'title' => $slot['title'] ?? ''
                ];
            }
        }
        return $results;
    }

    /**
     * Verify that the appointment record matches the slot database entry.
     */
    public function verifyAppointments($config_key) {
        $config = $this->get_tz_config($config_key);

        // Get Config Properties
        $slot_project_id = $config['slot-project-id'];
        $appt_field = $config['appt-field'];
        $appt_event_id = $config['appt-event-id'];

        $appts = $this->getRecords($config_key);
        $slots = $this->getSlots($config_key, false);

        $slots_used = [];   // Keep track of slots that are used
        $result = [];

        foreach ($appts as $appt) {
            $errors = [];
            $actions = [];

            $appt_record = $appt[REDCap::getRecordIdField()];
            $appt_event_name = $appt['redcap_event_name'] ?? null;
            $appt_event_id = REDCap::getEventIdFromUniqueEvent($appt_event_name);
            $appt_instance = $appt['redcap_repeat_instance'] ?? null;
            $appt_repeat_instance = $appt_instance ? $appt_instance : 1;
            $appt_project_id = $this->getProjectId();
            $appt_instrument = $this->getFormForField($appt_field);
            $appt_url = APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION .
                    "/DataEntry/index.php?pid=$appt_project_id&id=$appt_record&page=$appt_instrument&event_id=$appt_event_id&instance=$appt_repeat_instance";
            $appt_slot_id = $appt[$appt_field];    // Should be guaranteed to have a value due to filterLogic in getRecords

            // Keep track of all used slots
            $slots_used[] = $appt_slot_id;

            // Build the data array
            $data = [
                'config_key' => $config_key,
                'appt_record' => $appt_record,
                'appt_event_name' => $appt_event_name,
                'appt_instance' => $appt_repeat_instance,
                'appt_instrument' => $appt_instrument,
                'appt_url' => $appt_url,
                'appt_slot_id' => $appt_slot_id,
                'slot_dt' => '123'
                // 'slot_db_url' => $slot_url ?? null,
                // 'errors' => implode('<br/>', $error),
                // 'actions' => implode('<br/>', $actions)
            ];

            $slot = $slots[$appt_slot_id] ?? null;


            $appt_error = 0;
            $slot_error = 0;
            if (empty($slot)) {
                $errors[] = "The appointment references a slot_id ($appt_slot_id) that does not exist in the slot database.";
                $appt_error = 1;
            } else {
                $slot_url = APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION . "/DataEntry/index.php?pid=" . $slot_project_id . "&id=$appt_slot_id&page=slots";
                $slot_dt = $slot['date'] . " " . $slot['time'];
                $data['slot_db_url'] = $slot_url;
                $data['slot_dt'] = $slot_dt;
                if (empty($slot['reserved_ts'])) {
                    $errors[] = "Record $appt_record is pointing to Slot $appt_slot_id, but slot $appt_slot_id does not point back.";
                    $appt_error = 1;
                } else {
                    // Check that slot matches record, event, instance
                    if ($appt_record != $slot['source_record_id']) {
                        $errors[] = "Appt $appt_record points to Slot $appt_slot_id, but this slot refers to a different appointment record: {$slot['source_record_id']}!";
                        $slot_error = 1;
                    }
                    if ($appt_event_id != $slot['source_event_id']) {
                        $errors[] = "Appt $appt_record points to Slot $appt_slot_id, but this slot refers to a different event ({$slot['source_event_id']} instead of $appt_event_id)";
                        $slot_error = 1;
                    }
                    if ($appt_repeat_instance != $slot['source_instance_id']) {
                        $errors[] = "Appt $appt_record points to Slot $appt_slot_id, but this slot refers to a different instance ({$slot['source_instance_id']} instead of $appt_repeat_instance)";
                        $slot_error = 1;
                    }

                    // $actions[] = "Reset Appt and Slot";
                    $actions[] = [
                        "label" => "Reset Appt and Slot",
                        "action" => "resetSlotAndAppointment",
                        "params" => [
                            "config_key" => $config_key,
                            "record" => $appt_record,
                            "instance" => $appt_repeat_instance,
                            "slot_id" => $slot['slot_id']
                        ]
                    ];
                }

                // Check datetime field if stored on the appt record
                if (!empty($config['appt-datetime-field'])) {
                    $appt_dt = $appt[$config['appt-datetime-field']] ?? null;
                    if ($appt_dt && $slot_dt != $appt_dt) {
                        $errors[] = "Slot $appt_slot_id refers to a different datetime ($slot_dt) than was stored in this record ($appt_dt)";
                    }
                }

            }


            if ($appt_error) {
                $actions[] = [
                    "label" => "Reset Appt Only",
                    "action" => "resetAppointment",
                    "params" => [
                        "config_key" => $config_key,
                        "record" => $appt_record,
                        "instance" => $appt_repeat_instance
                    ]
                ];
            }
                    // Add action buttons for slot errors
            if ($slot_error) {
                $actions[] = [
                    "label" => "Reset Appt Only",
                    "action" => "resetAppointment",
                    "params" => [
                        "config_key" => $config_key,
                        "record" => $appt_record,
                        "instance" => $appt_repeat_instance
                    ]
                ];
                $actions[] = [
                    "label" => "Reset Slot Only",
                    "action" => "resetSlot",
                    "params" => [
                        "config_key" => $config_key,
                        "slot_id" => $appt_slot_id
                    ]
                ];
            }

            $data['status'] = empty($errors) ? "OK" : "ERROR";
            $data['errors'] = implode('|', $errors);
            $data['actions'] = $actions;
            $result[] = $data;
        }
        return $result;
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
    public function getCurrentAppointmentId($config_key, $record, $repeat_instance) {
        $this->emDebug("getCurrentAppointmentId called with config_key: $config_key, record: $record, repeat_instance: $repeat_instance");

        // Get the slot_id from the current record
        $data = $this->getRecord($config_key, $record, $repeat_instance);

        $config = $this->get_tz_config($config_key);
        $slot_id_field = $config['appt-field'] ?? null;
        $event_id = $config['appt-event-id'] ?? null;

        if (empty($slot_id_field)) {
            $this->emError("Invalid configuration - missing slot id field for config_key: $config_key", $config);
            throw new TimezoneException("Invalid configuration - missing slot id field for config_key: $config_key");
        }

        $slot_id = $data[$slot_id_field] ?? null;
        return $slot_id;
    }

    /**
     * Get the full record data for the current record - handles repeating forms if needed
     * @param string $config_key The configuration key to use
     * @param int $record The record id
     * @param int $repeat_instance The repeat instance
     * @return array The record data as an associative array or empty array if not found
     * @throws TimezoneException with any errors encountered
     */
    public function getRecord($config_key, $record, $repeat_instance) {
        $config = $this->get_tz_config($config_key);
        $appt_field = $config['appt-field'] ?? null;
        $event_id = $config['appt-event-id'] ?? null;
        if (empty($appt_field) || empty($event_id)) {
            $this->emError("Invalid configuration - missing appt field or event_id for config_key: $config_key", $config);
            throw new TimezoneException("Invalid configuration - missing appt field or event id for config_key: $config_key");
        }

        $appt_field_form = $this->getFormForField($appt_field);
        $is_repeating = in_array($appt_field_form, $this->getRepeatingForms($event_id));
        //$valid_fields = array_merge( self::RESERVED_FIELD_NAMES, REDCap::getFieldNames($slot_id_field_form), [$slot_id_field_form . '_complete']);
        $params = [
            'return_format' => 'json',
            'records' => $record,
            'events' => $event_id
        ];
        try {
            $q = REDCap::getData($params);
            $qa = json_decode($q, true);
        } catch (Exception $e) {
            $this->emError("Error retrieving record $record for config_key $config_key: " . $e->getMessage());
            throw new TimezoneException("Error retrieving record $record for config_key $config_key.  Please check the system logs for details.");
        }

        if (!empty($qa['errors'])) {
            $this->emError("Error retrieving record $record for config_key $config_key: ", $qa['errors']);
            throw new TimezoneException("Error retrieving record $record for config_key $config_key.  Please check the system logs for details.");
        }

        // Loop through responses to find proper instance/entry
        // $this->emDebug("Redcap data for record $record: ", $qa);
        $result = [];

        $record_exists = false;
        $instance_exists = false;

        $this->emDebug("Looking for record $record, event_id $event_id, form $appt_field_form, " .
            "repeat_instance $repeat_instance, is_repeating: " . ($is_repeating ? "true" : "false"));

        foreach ($qa as $entry) {
            // TODO: Consider removing any fields other than 'special ones' that are not from the current form -- something REDCap should normally do automatically...

            // Sanity check for record existing
            if ($entry[REDCap::getRecordIdField()] == $record) {
                $record_exists = true;
            }

            // $this->emDebug("Entry: ", $entry);
            if ($is_repeating
                && $entry['redcap_repeat_instrument'] == $appt_field_form
                && $entry['redcap_repeat_instance'] == $repeat_instance
            ) {
                $this->emDebug("Found entry:", array_filter($entry));
                $instance_exists = true;
                $result = $entry;
                break;
            } elseif (
                !$is_repeating
                && $entry['redcap_repeat_instrument'] == ""
                && $entry['redcap_repeat_instance'] == ""
            ) {
                // Found the correct non-repeating form entry
                // $this->emDebug("Found correct non-repeating entry:", array_filter($entry));
                $result = $entry;
                break;
            } else {
                // $this->emDebug("Skipping non-matching entry:", array_filter($entry));
            }
        }

        if (empty($result)) {
            if ($record_exists) {
                // I'm not going to throw an error if the record doesn't exist, since it might be a new record/instance
            } else {
                $this->emError("Unable to locate record $record for config_key $config_key with event_id $event_id and repeat_instance $repeat_instance", $qa);
                $msg = "Unable to locate record $record " . ($is_repeating ? ", repeat instance $repeat_instance" : "") . ".  Has this record/instance been created yet?";
                throw new TimezoneException($msg);
            }
        }
        return $result;
    }


    /**
     * Get all records for the current config_key
     * where the slot_id field has a value.
     * Note: handles repeating forms if needed
     * @param string $config_key The configuration key to use
     * @return array The record data as an associative array or empty array if not found
     * @throws TimezoneException with any errors encountered
     */
    public function getRecords($config_key) {
        $config = $this->get_tz_config($config_key);
        $appt_field = $config['appt-field'] ?? null;
        $event_id = $config['appt-event-id'] ?? null;
        $appt_field_form = $this->getFormForField($appt_field);
        $is_repeating = in_array($appt_field_form, $this->getRepeatingForms($event_id));

        $valid_fields = [$appt_field, $this->getRecordIdField(), $appt_field_form . '_complete'];
        if (!empty($config['appt-datetime-field'])) {
            $valid_fields[] = $config['appt-datetime-field'];
        }
        if (!empty($config['appt-participant-text-date-field'])) {
            $valid_fields[] = $config['appt-participant-text-date-field'];
        }
        if (!empty($config['appt-description-field'])) {
            $valid_fields[] = $config['appt-description-field'];
        }
        if (!empty($config['appt-cancel-url-field'])) {
            $valid_fields[] = $config['appt-cancel-url-field'];
        }
        if (!empty($config['slot-record-url-field'])) {
            $valid_fields[] = $config['slot-record-url-field'];
        }

        $params = [
            'return_format' => 'json',
            'fields' => $valid_fields,
            'events' => $event_id,
            'filterLogic' => "[$appt_field] <> ''"
        ];
        try {
            $q = REDCap::getData($params);
            $qa = json_decode($q, true);
        } catch (Exception $e) {
            $this->emError("Error retrieving all records for config_key $config_key: " . $e->getMessage());
            throw new TimezoneException("Error retrieving records for config_key $config_key.  Please check the system logs for details.");
        }
        return $qa;
    }


    // Given an array of slot records, build the appointment options
    public function getAppointmentOptions($config_key, $slots, $client_timezone, $filter_past_dates = true) {
        $config = $this->get_tz_config($config_key);
        $appointments = [];
        $now_dt = new DateTime("now");
        $client_dtz = new DateTimeZone($client_timezone);
        // $this->emDebug("Client timezone: $client_timezone");

        $description_format = $config['appt-description-format'] ?? self::DEFAULT_APPT_DESCRIPTION_FORMAT;
        $text_date_format = $config['appt-text-date-format'] ?? self::DEFAULT_APPT_TEXT_DATE_FORMAT;

        // Load data before doing custom piping
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

            // Filter out past slots
            if ($filter_past_dates) {
                if ($server_dt < $now_dt) {
                    $this->emDebug("Skipping slot $slot_id because it is in the past: $server_ts");
                    continue;
                }
            }

            // Calculate interval for how far in the future the slot is
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
            $appt_participant_text_date = str_replace( array_keys($codex), array_values($codex), $text_date_format );


            // Replace anything between <== and ==> with nothing if timezones match
            if ($client_dt->format('T') == $server_dt->format('T')) {
                $re = '/<==[.\s\w\W]*==>/mU';   // This overly complex regex allows for linefeeds between the start and end tokens
                $appt_description = preg_replace($re, '', $appt_description);
            } else {
                $appt_description = str_replace( ['<==', '==>'], '', $appt_description);
            }

            $appointments[] = [
                'id' => strval($slot_id),
                'title' => $appt_participant_text_date,
                'text' => $appt_description,
                'participant_text_date' => $appt_participant_text_date,
                'server_dt' => $server_dt->format('Y-m-d H:i')
                // 'description' => $appt_description,
                // 'description' => $client_dt->format('D, M jS \a\t g:ia T'),
                // 'client_dt' => $client_dt->format('Y-m-d H:i'),
                //'diff' => $diff->format('%a days %h hours')
            ];
        }
        return $appointments;
    }


    /**
     * Get the slot filter value from the current record if defined in config
     * @param string $config_key The configuration key to use
     * @param int $record The record id
     * @param int $repeat_instance The repeat instance
     * @return string The slot filter value or empty string if not defined
     */
    public function getSlotFilterValue($config_key, $record, $repeat_instance) {
        $this->emDebug("getSlotFilterValue called with config_key: $config_key, record: $record, repeat_instance: $repeat_instance");
        $config = $this->get_tz_config($config_key);
        $slot_filter_field = $config['slot-filter-field'] ?? null;
        $slot_filter_value = '';
        if (!empty($slot_filter_field)) {
            // Get the slot_filter value from the current record
            $data = $this->getRecord($config_key, $record, $repeat_instance);
            $slot_filter_value = $data[$slot_filter_field] ?? '';
        }
        return $slot_filter_value;
    }


    /**
     * get slots from the slot project, filtered as needed
     *  - always filters out slots that are reserved for other projects
     *
     * @param string $config_key The configuration key to use
     * @param bool $only_show_available Whether to filter out already reserved slots (default true)
     * @param string $slot_filter_value An optional slot filter value to filter slots by - only applies if $only_show_available is true
     * @return array An associative array of slots or false if an error occurred
     * @throws TimezoneException if the configuration is invalid
     */
    public function getSlots($config_key, $only_show_available = true, $slot_filter_value = '') {
        $this->emDebug("getSlots for $config_key");
        $config = $this->get_tz_config($config_key);
        $slot_project_id = $config['slot-project-id'] ?? null;
        if (!$slot_project_id) {
            $this->emError("Invalid configuration - missing slot project id for config_key: $config_key", $config);
            return false;
        }

        // Load data from slot database
        $q = REDCap::getData($slot_project_id, 'json');
        $results = json_decode($q,true);

        // Filter as configured
        $slots = [];
        foreach ($results as $data) {
            $slot_id = $data['slot_id'] ?? null;

            // If a slot is reserved for another project, then skip it
            if (!empty($data['project_filter']) && $data['project_filter'] !== $this->getProjectId()) {
                // $this->emDebug("Skipping slot $slot_id due to project_id filter");
                continue;
            }

            // If specified, only return unused/available slots
            if ($only_show_available) {
                if (!empty($data['reserved_ts'])) {
                    // $this->emDebug("Skipping slot $slot_id because it is already taken");
                    continue;
                }

                if (trim($data['slot_filter'] ?? '') !== $slot_filter_value) {
                    // $this->emDebug("Skipping slot $slot_id because if a slot-filter mismatch");
                    continue;
                }
            }
            $slots[$slot_id] = $data;
        }

        // Sort by date/time
        uasort($slots, function($a, $b) {
            $dt_a = new DateTime($a['date'] . ' ' . $a['time']);
            $dt_b = new DateTime($b['date'] . ' ' . $b['time']);
            return $dt_a <=> $dt_b;
        });

        return $slots;
    }


    /**
     * @param string $config_key The configuration key to use
     * @param int $slot_id The slot id (record id in slot project)
     * @return mixed The slot record as an associative array or null if not found
     *
     * Currently does not throw any exceptions
     */
    public function getSlot($config_key, $slot_id) {
        // $this->emDebug("getSlot called with config_key: $config_key, slot_id: $slot_id");
        $config = $this->get_tz_config($config_key);
        $slot_project_id = $config['slot-project-id'] ?? null;
        if (empty($slot_project_id)) {
            $this->emError("Invalid configuration - missing slot project id for config_key: $config_key", $config);
            throw new TimezoneException("Invalid configuration - missing slot project id for config_key: $config_key");
        }

        $redcap_query = REDCap::getData($slot_project_id, 'json', [$slot_id]);
        $redcap_data = json_decode($redcap_query, true);
        // $this->emDebug("redcap_data for slot_id $slot_id: ", $redcap_data);
        if (empty($redcap_data)) {
            $this->emError("Unable to locate slot data for config_key: $config_key with slot_id: $slot_id");
            throw new TimezoneException("Unable to locate the requested data for slot $slot_id.");
        }

        if (!is_array($redcap_data) || count($redcap_data) > 1) {
            $this->emError("No or Multiple records returned for slot_id $slot_id in slot project $slot_project_id", $redcap_data);
            throw new TimezoneException("No or Multiple records returned for slot_id $slot_id - see server logs for details.");
        }

        // Just take the current (only) record returned
        $result = current($redcap_data);
        return $result;
    }


    /**
     * Save a slot via json where slot_data contains record_id and all data -- return true/false depending on success
     * @param string $config_key The configuration key to use
     * @param array $slot_data An associative array of slot data including the record_id (slot id)
     * @return bool True if the save was successful
     * @throws TimezoneException if the configuration is invalid
     */
    public function saveSlot($config_key, $slot_data) {
        // $this->emDebug("saveSlot called with config_key: $config_key, slot: ", $slot_data);

        $config = $this->get_tz_config($config_key);
        $slot_project_id = $config['slot-project-id'] ?? null;
        if (!$slot_project_id) {
            $this->emError("Invalid configuration - missing slot project id for config_key: $config_key", $config);
            throw new TimezoneException("Invalid configuration - missing slot project id for config_key: $config_key");
        }

        // Save data to slot database
        $params = array(
            'project_id' => $slot_project_id,
            'dataFormat'=>'json',
            'data'=> json_encode([$slot_data]),
            'overwriteBehavior' => 'overwrite'
        );
        $result = REDCap::saveData($params);
        if(!isset($result['errors']) || !empty($result['errors'])) {
            $this->emError("Error saving slot data for config_key: $config_key, slot:", $slot_data, $result);
            throw new TimezoneException("Error saving slot db data for config_key: $config_key.  Check server logs for details.");
        }
        // $this->emDebug("REDCap saveData result: ", $result);
        return true;
    }


    /**
     * Reset a slot by clearing all reservation fields
     * If cancel is true, then it will mark the slot as reserved so it will no longer be available
     * @param string $config_key The configuration key to use
     * @param int $slot_id The slot id (record id in slot project)
     * @param string|null $message An optional message to log when resetting the slot
     * @param bool $cancel_slot If true, sets the reserved_ts to now, otherwise clears it
     * @return void
     * @throws TimezoneException if the configuration is invalid or the slot cannot be found
     */
    public function resetSlot($config_key, $slot_id, $message = null, $cancel_slot = false) {
        $config = $this->get_tz_config($config_key);
        $slot_project_id = $config['slot-project-id'] ?? null;
        if (empty($slot_project_id)) {
            $this->emError("Invalid configuration - missing slot project id for config_key: $config_key", $config);
            throw new TimezoneException("Invalid configuration - missing slot project id for config_key: $config_key");
        }
        $this->emDebug("resetSlot called on slot_project_id: $slot_project_id, slot_id: $slot_id");

        // First get the slot db record
        $slot = $this->getSlot($config_key, $slot_id);


        $ts = $cancel_slot ? date("Y-m-d H:i:s") : null;

        // Clear out the reservation fields
        $slot['reserved_ts'] = $ts;
        $slot['source_project_id'] = null;
        $slot['source_project_title'] = empty($message) ? null : $message;
        $slot['source_record_id'] = null;
        $slot['source_field'] = null;
        $slot['source_event_id'] = null;
        $slot['source_instance_id'] = null;
        $slot['source_record_url'] = null;
        $slot['participant_timezone'] = null;
        $slot['participant_description'] = null;
        $slot['slots_complete'] = 0;

        $this->saveSlot($config_key, $slot);
    }



    /**
     * Reset both the slot and the appointment by clearing the slot reservation fields
     * and the current record's appointment fields, returning an array of cleared fields for the client to update
     * @param string $config_key The configuration key to use
     * @param int $slot_id The slot id (record id in slot project)
     * @param string $message An optional message to log when resetting the slot
     * @param string $record The record id
     * @param int $repeat_instance The repeat instance (default 1)
     * @return array An associative array of cleared fields for the client to update
     * @throws TimezoneException if any errors occur
     */
    public function resetSlotAndAppointment($config_key, $slot_id, $message, $record, $repeat_instance = 1) {
        $this->emDebug("resetSlotAndAppointment called with config_key: $config_key, slot_id: $slot_id, record: $record, repeat_instance: $repeat_instance");

        // First reset the slot
        $this->resetSlot($config_key, $slot_id, $message, false);

        // Now reset the appointment
        $client_data = $this->resetAppointment($config_key, $record, $repeat_instance);

        return $client_data;
    }


    /**
     * Reset an appointment by clearing the current record's appointment fields
     * and returning an array of cleared fields for the client to update
     * @param string $config_key The configuration key to use
     * @param int $record The record id
     * @param int $repeat_instance The repeat instance (default 1)
     * @return array An associative array of cleared fields for the client to update
     * @throws TimezoneException if any errors occur
     */
    public function resetAppointment($config_key, $record, $repeat_instance = 1) {
        $this->emDebug("resetAppointment called with config_key: $config_key, record: $record, repeat_instance: $repeat_instance");

        $config = $this->get_tz_config($config_key);
        $appt_field = $config['appt-field'] ?? null;
        $event_id = $config['appt-event-id'] ?? null;
        if (empty($appt_field) || empty($event_id)) {
            $this->emError("Invalid configuration - missing appt field or event id for config_key: $config_key", $config);
            throw new TimezoneException("Invalid configuration - missing appt field or event id for config_key: $config_key");
        }

        if (empty($record)) {
            $this->emError("Invalid call to resetAppointment - missing record id for config_key: $config_key", $config);
            throw new TimezoneException("Invalid call to resetAppointment - missing record id.");
        }

        // Now lets clear the current record
        $data = $this->getRecord($config_key, $record, $repeat_instance);
        $keys = ['appt-field', 'appt-datetime-field', 'appt-description-field', 'appt-participant-text-date-field', 'appt-cancel-url-field', 'slot-record-url-field'];
        $client_data = [];
        foreach ($keys as $key) {
            if (!empty($config[$key])) {
                $data[$config[$key]] = null;
                $client_data[$config[$key]] = null;
            }
        }

        // Save Record
        $params = [
            'dataFormat' => 'json',
            'data' => json_encode([$data]),
            'overwriteBehavior' => 'overwrite'
        ];
        $q = REDCap::saveData($params);
        if (!isset($q['errors']) || !empty($q['errors'])) {
            $this->emError("Error resetting appointment data for record $record with config_key: $config_key:", $data, $q);
            throw new TimezoneException("Failed to reset appointment $config_key for record $record / $repeat_instance - please see server logs.");
        }
        return $client_data;
    }


    /**
     * Cancel an appointment by clearing the slot and the current record's appointment fields
     * and returning an array of cleared fields for the client to update
     * @param int $slot_id The slot id (record id in slot project)
     * @param string $config_key The configuration key to use
     * @param int $record The record id
     * @param int $event_id The event id
     * @param int $repeat_instance The repeat instance
     * @param bool $allow_cancel_past_appointments Whether to allow canceling past appointments (default false)
     * @return array An associative array of cleared fields for the client to update
     * @throws TimezoneException if any errors occur
     */
    public function cancelAppointment($slot_id, $config_key, $record, $event_id, $repeat_instance, $allow_cancel_past_appointments=false) {
        $this->emDebug("cancelAppointment called with config_key: $config_key, slot_id: $slot_id, record: $record, event_id: $event_id, repeat_instance: $repeat_instance");

        $config = $this->get_tz_config($config_key);
        $slot_project_id = $config['slot-project-id'] ?? null;
        if (!$slot_project_id) {
            $this->emError("Invalid configuration - missing slot project id for config_key: $config_key", $config);
            throw new TimezoneException("Invalid configuration - missing slot project id for config_key: $config_key");
        }

        // First get the slot db record
        $slot = $this->getSlot($config_key, $slot_id);
        $this->emDebug("Slot record for slot_id $slot_id: ", $slot);
        if (empty($slot)) {
            $this->emError("Unable to locate slot data for config_key: $config_key with slot_id: $slot_id prior to clearing slot");
            throw new TimezoneException("Unable to locate the requested slot - please try again.");
        }

        $now = new DateTime("now");
        $server_dt = new DateTime($slot['date'] . ' ' . $slot['time']);
        if($now > $server_dt && !$allow_cancel_past_appointments) {
            $this->emError("Unable to cancel slot_id $slot_id because it is in the past: " . $slot['date'] . ' ' . $slot['time']);
            throw new TimezoneException("Only users with design rights can cancel the requested slot because it occurs in the past.");
        }

        // Clear out the reservation fields
        $slot['reserved_ts'] = null;
        $slot['source_project_id'] = null;
        $slot['source_project_title'] = null;
        $slot['source_record_id'] = null;
        $slot['source_field'] = null;
        $slot['source_event_id'] = null;
        $slot['source_instance_id'] = null;
        $slot['source_record_url'] = null;
        $slot['participant_timezone'] = null;
        $slot['participant_description'] = null;
        $slot['slots_complete'] = 0;

        // Save the cleared slot
        $save = $this->saveSlot($config_key, $slot);
        if (!$save) {
            $this->emError("Error clearing slot data for config_key: $config_key, slot:", $slot);
            throw new TimezoneException("Error clearing slot db data for config_key: $config_key, slot_id: $slot_id");
        }
        $this->emDebug("Cancelled slot_id $slot_id successfully");

        // Now lets clear the current record
        $data = $this->getRecord($config_key, $record, $repeat_instance);
        $keys = ['appt-field', 'appt-datetime-field', 'appt-description-field', 'appt-participant-text-date-field', 'appt-cancel-url-field', 'slot-record-url-field'];
        $client_data = [];
        foreach ($keys as $key) {
            if (!empty($config[$key])) {
                $data[$config[$key]] = null;
                $client_data[$config[$key]] = null;
            }
        }
        // Save Record
        $params = [
            'dataFormat' => 'json',
            'data' => json_encode([$data]),
            'overwriteBehavior' => 'overwrite'
        ];
        $q = REDCap::saveData($params);
        if (!isset($q['errors']) || !empty($q['errors'])) {
            $this->emError("Error clearing appointment data to record $record for config_key: $config_key, slot:", $data, $q);
            throw new TimezoneException("Failed to clear appointment data to this record - please report this error and try again.  It is possible the requested slot: $slot_id is no longer available even though it is not part of this record.");
        }
        return $client_data;
    }


    /**
     * Reserve a slot by updating the slot record with the reservation details
     * and updating the current record with the slot id
     * @param string $config_key The configuration key to use
     * @param int $slot_id The slot id (record id in slot project)
     * @param string $timezone The participant's timezone
     * @param int $project_id The current project id
     * @param int $record The record id
     * @param string $instrument The current instrument name
     * @param int $event_id The event id
     * @param int $repeat_instance The repeat instance
     * @return void
     * @throws TimezoneException if any errors occur
     */
    public function reserveSlot($config_key, $slot_id, $timezone, $project_id, $record, $instrument, $event_id, $repeat_instance) {
        $config = $this->get_tz_config($config_key);
        $slot_project_id = $config['slot-project-id'] ?? null;
        if (empty($slot_project_id) || empty($slot_id)) {
            $this->emError("Invalid configuration in Timezone Scheduler module for config: $config_key with slot id: $slot_id");
            throw new TimezoneException("Invalid configuration in Timezone Scheduler module for config: $config_key with slot id: $slot_id");
        }

        // Lock Slot
        $lock_name = "tzs_slot_" . $slot_id . "_proj_" . $slot_project_id;
        if (!$this->getLock($lock_name)) {
            $this->emError("Unable to obtain lock for $lock_name");
            throw new TimezoneException("Unable to obtain a lock for the requested slot - please try again.");
        }

        // Record lock name as object property so we can release it in any exception handler cases...
        $this->lock_name = $lock_name;

        // First get the slot record
        $slot = $this->getSlot($config_key, $slot_id);
        // $this->emDebug("Slot record for slot_id $slot_id: ", $slot);
        if (empty($slot)) {
            $this->emError("Unable to locate slot data for config_key: $config_key with slot_id: $slot_id prior to reservation");
            throw new TimezoneException("Unable to locate the requested slot - please try again.");
        }

        $result = [];
        if (!empty($slot['reserved_ts'])) {
            $this->emDebug("Slot $slot_id is already reserved");
            throw new TimezoneException("The requested slot is no longer available.  Please try again.");
        }
        $this->emDebug("Slot $slot_id is available, proceeding with reservation");

        // Find description for slot and timezone
        $options = $this->getAppointmentOptions($config_key, [$slot_id => $slot], $timezone);
        $appointment = $options[0] ?? null;

        if (empty($appointment)) {
            $this->emError("Unable to locate appointment data for slot_id $slot_id in timezone $timezone");
            throw new TimezoneException("Unable to locate appointment data for the requested slot - please try again.");
        }

        // Reserve slot
        $slot['reserved_ts'] = date('Y-m-d H:i:s');
        $slot['source_project_id'] = $project_id;
        $slot['source_project_title'] = $this->getProject()->getTitle();
        $slot['source_record_id'] = $record;
        $slot['source_field'] = $config['appt-field'] ?? '';
        $slot['source_event_id'] = $event_id;
        $slot['source_instance_id'] = $repeat_instance;
        // https://redcap.local/redcap_v15.3.3/DataEntry/index.php?pid=38&id=1&page=test_form&event_id=88&instance=1
        // TODO replace with a EM-mediated redirect to get rid of the version number...
        $slot['source_record_url'] = APP_PATH_WEBROOT_FULL . 'redcap_v' . REDCAP_VERSION .
            "/DataEntry/index.php?pid=$project_id&id=$record&page=$instrument&event_id=$event_id&instance=$repeat_instance";
        $slot['participant_timezone'] = $timezone;
        $slot['participant_description'] = $appointment['text'] ?? 'Unable to parse appointment';
        $slot['slots_complete'] = 2;

        if (! $this->saveSlot($config_key, $slot)) {
            $this->emError("Error reserving slot data for config_key: $config_key, slot:", $slot);
            throw new TimezoneException("Failed to reserve the requested slot - please try again.");
        }

        // Lets also update the current record so that the slot_id is saved here as well
        $data = [];
        $data[$this->getRecordIdField()] = $record;
        $data[$config['appt-field']] = $slot_id;

        // Check for the datetime field and convert the appointment time to the proper format
        $client_date = null;
        $adfield = $config['appt-datetime-field'] ?? null;
        if ($adfield) {
            $date_validation_type = $this->getProject()->getREDCapProjectObject()->metadata[$adfield]['element_validation_type'];

            $default_format = 'Y-m-d H:i'; // Default to ymd

            if (isset(self::VALIDATION_SERVER_CONVERSION_INDEX[$date_validation_type])) {
                $server_format = self::VALIDATION_SERVER_CONVERSION_INDEX[$date_validation_type];
                $client_format = self::VALIDATION_CLIENT_CONVERSION_INDEX[$date_validation_type];
            }

            // $this->emDebug("Converting appointment server_dt " . $appointment['server_dt'] . " to format $format based on validation type $date_validation_type");

            $server_dt = DateTime::createFromFormat('Y-m-d H:i', $appointment['server_dt']);

            if( !$server_dt ) {
                $this->emError("Unable to convert appointment server_dt " . $appointment['server_dt'] . " to DateTime object");
                $this->releaseLock($lock_name);
                throw new TimezoneException("Failed to convert the appointment date/time to the proper format - please try again.");
            }

            $server_date = $server_dt->format($server_format ?? $default_format);
            $client_date = $server_dt->format($client_format ?? $default_format);
            $data[$adfield] = $server_date;
        }

        // Check for participant text date field
        if ($config['appt-participant-text-date-field']) {
            $data[$config['appt-participant-text-date-field']] = $appointment['participant_text_date'] ?? 'Unable to parse text date';
        }

        // Check for description field
        if ($config['appt-description-field']) {
            $data[$config['appt-description-field']] = $appointment['text'] ?? 'Unable to parse appointment';
        }

        // Build a cancel URL if configured
        if ($config['appt-cancel-url-field']) {
            // Build cancel URL: The key should be specific to the slot_id, record, event, and instance, AND reservation ts so it is unique and not guessable
            $key_raw = $config_key . "|" . $slot_id . "|" . $record . "|" . $event_id . "|" . $repeat_instance . "|" . $slot['reserved_ts'];
            $key = encrypt($key_raw);
            $cancel_url = $this->getUrl('pages/cancel.php', true) . "&" . http_build_query(["key" => $key]);
            $data[$config['appt-cancel-url-field']] = $cancel_url;
        }

        // TODO: Consider removing this url as well and just add a plugin hook to show the slot details
        if ($config['slot-record-url-field']) {
            // https://redcap.local/redcap_v15.3.3/DataEntry/index.php?pid=40&id=14&page=slots
            $data[$config['slot-record-url-field']] = APP_PATH_WEBROOT_FULL . 'redcap_v' .
                REDCAP_VERSION . '/DataEntry/index.php?pid=' . $slot_project_id .
                '&id=' . $slot_id . '&page=slots';
        }

        // Check if we need to add repeating form fields
        $slot_id_field = $config['appt-field'] ?? null;
        $slot_id_field_form = $this->getFormForField($slot_id_field);
        $is_repeating = in_array($slot_id_field_form, $this->getRepeatingForms($event_id));
        if ($is_repeating) {
            $data['redcap_repeat_instrument'] = $slot_id_field_form;
            $data['redcap_repeat_instance'] = $repeat_instance;
        }

        // Check for redcap_event_name if longitudinal
        if (REDCap::isLongitudinal()) {
            $event_name = REDCap::getEventNames(true, true, $event_id) ?? null;
            if ($event_name) {
                $data['redcap_event_name'] = $event_name;
            }
        }

        // Save the record in json format
        $q = REDCap::saveData('json', json_encode([$data]));
        // $this->emDebug("REDCap saveData result: ", $q);

        // Check for the array 'errors' of a proper response
        // Sometimes REDCap seems to throw a string error instead of an array with errors, such as "The data is not in the specified format."
        if (!isset($q['errors']) || !empty($q['errors'])) {
            $this->emError("Error saving appointment data to record $record for config_key: $config_key, slot:", $data, $q);
            throw new TimezoneException("Failed to save appointment data to this record - please report this error and try again.  It is possible the requested slot: $slot_id is no longer available even though it is not part of this record.");
        }

        // Release Lock
        $this->releaseLock($lock_name);

        // Prior to releasing data to front end, we need to update the date field to the client format:
        if ($client_date && $adfield) {
            $data[$adfield] = $client_date;
        }
        // Return the current record's data so it can be updated on the frontend
        return $data;
    }

    /**
     * Get a list of timezones for select2
     * Selection is based on the 'timezone-database' project setting
     * @return array An array of timezones formatted for select2
     */
    public function getTimezoneList() {
        $timezone_db = $this->getProjectSetting('timezone-database') ?? 'all';
        switch ($timezone_db) {
            case 'usa':
                $options = [
                    [ "id" => "America/New_York", "text" => "Eastern (ET/EST) [America/New_York]" ],
                    [ "id" => "America/Chicago", "text" => "Central (CT/CST) [America/Chicago]" ],
                    [ "id" => "America/Denver", "text" => "Mountain (MT/MST) [America/Denver]" ],
                    [ "id" => "America/Los_Angeles", "text" => "Pacific (PT/PST) [America/Los_Angeles]" ],
                    [ "id" => "America/Anchorage", "text" => "Alaska (AKT/AKST) [America/Anchorage]" ],
                    [ "id" => "Pacific/Honolulu", "text" => "Hawaii (HST) [Pacific/Honolulu]" ],
                ];
                break;
            case 'europe':
                $options = [
                    [ "id" => "Europe/London", "text" => "Europe/London (GMT/BST)" ],
                    [ "id" => "Europe/Berlin", "text" => "Europe/Berlin (CET/CEST)" ],
                    [ "id" => "Europe/Paris", "text" => "Europe/Paris (CET/CEST)" ],
                    [ "id" => "Europe/Rome", "text" => "Europe/Rome (CET/CEST)" ],
                    [ "id" => "Europe/Helsinki", "text" => "Europe/Helsinki (EET/EEST)" ],
                    [ "id" => "Europe/Moscow", "text" => "Europe/Moscow (MSK/MSD)" ],
                    [ "id" => "Europe/Istanbul", "text" => "Europe/Istanbul (TRT)" ],
                    [ "id" => "Atlantic/Azores", "text" => "Atlantic/Azores (AZOT/AZOST)" ],
                ];
                break;
            case 'north_america':
                $options = [
                    [ "id" => "America/New_York", "text" => "Eastern (ET/EST) [America/New_York]" ],
                    [ "id" => "America/Chicago", "text" => "Central (CT/CST) [America/Chicago]" ],
                    [ "id" => "America/Denver", "text" => "Mountain (MT/MST) [America/Denver]" ],
                    [ "id" => "America/Los_Angeles", "text" => "Pacific (PT/PST) [America/Los_Angeles]" ],
                    [ "id" => "America/Anchorage", "text" => "Alaska (AKT/AKST) [America/Anchorage]" ],
                    [ "id" => "Pacific/Honolulu", "text" => "Hawaii (HST) [Pacific/Honolulu]" ],
                    [ "id" => "America/Halifax", "text" => "Atlantic (AT/AST) [America/Halifax]" ],
                    [ "id" => "America/St_Johns", "text" => "Newfoundland (NT) [America/St_Johns]" ]
                ];
                break;
            default:
                $timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
                $now = new DateTime("now");
                $grouped = [];
                foreach ($timezones as $tz) {
                    $dtz = new DateTimeZone($tz);
                    $tz_dt = $now->setTimezone($dtz);
                    $offset = $dtz->getOffset($tz_dt); // Offset in seconds

                    // Calculate the time offset
                    $sign = ($offset >= 0) ? '+' : '-';
                    $absSeconds = abs($offset);
                    $h = floor($absSeconds / 3600);
                    $m = floor(($absSeconds % 3600) / 60);
                    $time_offset = sprintf("%s%02d:%02d", $sign, $h, $m);   // e.g., +02:00
                    $abbreviation = $tz_dt->format('T'); // Timezone abbreviation, e.g., PST or CET
                    $nice_tz = $tz . " (" . (preg_match('/[A-Z]/', $abbreviation) ? "$abbreviation, " : '') . "UTC$time_offset)";

                    // Handle grouping by continent
                    $parts = explode('/', $tz, 2);
                    $region = $parts[0];
                    if (!isset($grouped[$region])) {
                        $grouped[$region] = [];
                    }
                    $grouped[$region][] = ["id" => $tz, "text" => "$nice_tz"];
                }

                // Convert grouped to desired format for select2
                $options = [];
                foreach ($grouped as $region => $zones) {
                    $options[] = [
                        "text" => $region,
                        "children" => $zones
                    ];
                }
                break;
        }
        return $options;
    }


    // public function saveAppointment($payload) {
    //     $this->emDebug("saveAppointment called with payload: ", $payload);
    //     // Here you would typically make an AJAX call to save the appointment
    // }

    /**
     * Handle AJAX requests from the front end
     * @param string $action The action to perform
     * @param array $payload The payload data from the AJAX request
     * @param int $project_id The current project id
     * @param int $record The current record id
     * @param string $instrument The current instrument name
     * @param int $event_id The current event id
     * @param int $repeat_instance The current repeat instance
     * @param string $survey_hash The current survey hash
     * @param int $response_id The current response id
     * @param string $survey_queue_hash The current survey queue hash
     * @param string $page The current page name
     * @param string $page_full The full current page name
     * @param int $user_id The current user id
     * @param int $group_id The current group id
     * @return array An associative array with 'success' (bool) and either 'data' (mixed) or 'message' (string)
     * @throws Exception if action is not defined
     */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
        $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        // Return success and then data if true or message if false
        $this->emDebug("$action called with payload: " . json_encode($payload));

        try {
            switch($action) {
                case "getTimezones":
                    $result = [
                        "success" => true,
                        "data" => $this->getTimezoneList()
                    ];
                    break;
                case "getSlotsVerificationData":
                    $data = $this->verifySlots();
                    $result = [
                        "success" => true,
                        "data" => $data
                    ];
                    break;
                case "getAppointmentVerificationData":
                    $this->load_tz_configs();
                    $q = [];
                    foreach($this->config as $config_key => $config) {
                        // $this->emDebug("Checking config $k: ", $config);
                        $q = array_merge($q, $this->verifyAppointments($config_key));
                    }
                    $result = [
                        "success" => true,
                        "data" => $q
                    ];
                    break;
                case "getAppointmentOptions":
                    $config_key = $payload['config_key'] ?? null;
                    $timezone = $payload['timezone'] ?? null;
                    if (empty($timezone)) {
                        $timezone = date_default_timezone_get();
                        $this->emDebug("No client timezone provided, using server default: ", $timezone);
                    }

                    // We have implemented a slot-filtering option to allow for multiple types of slots in the same slot db - in order to filter, we need to know the slot-filter for the current record
                    $slot_filter_value = $this->getSlotFilterValue($config_key, $record, $repeat_instance);
                    $this->emDebug("Using slot_filter_value: $slot_filter_value");

                    // Query all available slots
                    $slots = $this->getSlots($config_key, true, $slot_filter_value);

                    $this->emDebug("Available: " . count($slots) . " slots");
                    $appointment_options = $this->getAppointmentOptions($config_key, $slots, $timezone);

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
                case "addToCalendar":
                    $config_key = $payload['config_key'];
                    $slot_id = $this->getCurrentAppointmentId($config_key, $record, $repeat_instance);

                    // Make sure we have a slot_id
                    if (!$slot_id) {
                        $this->emDebug("No slot_id found for record $record with $config_key");
                        throw new TimezoneException("No appointment id found for record $record with config $config_key");
                    }

                    $slot = $this->getSlot($config_key, $slot_id);
                    if (empty($slot)) {
                        $this->emError("Record $record has appointment slot_id saved ($slot_id), but unable to locate record slot database: $config_key");
                        throw new TimezoneException("Record $record has appointment slot_id saved ($slot_id), but unable to locate record slot database: $config_key");
                    }

                    $add_to_calendar_config = $this->slotToCalendarConfig($slot);
                    $result = [
                        "success" => true,
                        "data" => [
                            "config" => $add_to_calendar_config
                        ]
                    ];
                    break;
                case "getAppointmentData":
                    // Called when an appointment field contains a slot_id and we need to return the appointment data for that slot
                    $config_key = $payload['config_key'];
                    $slot_id = $this->getCurrentAppointmentId($config_key, $record, $repeat_instance);

                    // Make sure we have a slot_id
                    if (!$slot_id) {
                        $this->emDebug("No appointment slot_id found for record $record with $config_key");
                        throw new TimezoneException("No appointment id found for record $record with config $config_key");
                    }
                    $this->emDebug("Found existing slot_id $slot_id for record $record with $config_key");
                    $timezone = $payload['timezone'] ?? date_default_timezone_get();
                    $result = [];
                    // Now get the slot record from the slot db
                    $slot = $this->getSlot($config_key, $slot_id);
                    if (empty($slot)) {
                        $this->emError("Record $record has appointment slot_id saved ($slot_id), but unable to locate record slot database: $config_key");
                        throw new TimezoneException("This record ($record) is missing their appointment in the slot database.  Please notify a study administrator to perform an integrity check.");
                    }

                    // Sanity check - make sure the slot's reservation details match that of this appointment record...
                    // TODO: Not sure what to do here...  Delete source data?  Probably need to make a better error message and have user interface for clearing reservation data.
                    if ($slot['source_record_id'] != $record || $slot['source_event_id'] != $event_id || $slot['source_instance_id'] != $repeat_instance) {
                        $this->emError("Record $record has appointment slot_id saved ($slot_id), but that slot's source data does not match the entry in the slot database");
                        // For now, I'm going to keep going, but perhaps we should throw a TimezoneException here...
                        throw new TimezoneException("Record $record, field $config_key has appointment slot_id $slot_id saved, but the corresponding reservation in the slot database has changed.  Please contact the study team.");
                        return false;
                    }

                    $add_to_calendar_config = $this->slotToCalendarConfig($slot);

                    $result = [
                        "success" => true,
                        "data" => [
                            "id" => $slot_id,
                            "text" => $slot['participant_description'] ?? "Missing description for slot $slot_id",
                            "timezone" => $slot['participant_timezone'],
                            "add_to_calendar_config" => $add_to_calendar_config
                        ]
                    ];
                    break;

                case "cancelAppointment":
                    // This is called from the form UI
                    $config_key = $payload['config_key'] ?? null;
                    $slot_id = $payload['slot_id'] ?? null;
                    $this->emDebug("cancelAppointment called with payload: ", $payload, $this->isSurveyPage(), $this->isAuthenticated(), $survey_hash);
                    $has_design_rights = ($this->isAuthenticated() && $this->getUser()->hasDesignRights());
                    if (!$this->isSurveyPage() && !$has_design_rights) {
                        throw new TimezoneException("Only users with design rights can cancel appointments from the data entry form user interface.");
                    }
                    $data = $this->cancelAppointment($slot_id, $config_key, $record, $event_id, $repeat_instance, $has_design_rights);
                    $result = [
                        "success" => true,
                        "data" => $data
                    ];
                    break;

                case "cancelAppointmentFromUrl":
                    // Excepts only success and message in return.
                    $key = $payload['key'] ?? null;
                    $token = $payload['token'] ?? null;
                    if (empty($key) && empty($token)) {
                        throw new TimezoneException("Missing required parameters to cancel appointment");
                    }
                    list($config_key, $slot_id, $record, $event_id, $repeat_instance) = explode('|', decrypt($key));
                    list($token_config_key, $token_ts) = explode('|', decrypt($token));
                    if (strtotime('now') - $token_ts > 600) { // 10 minutes
                        $this->emDebug("Cancel token has expired: " . (strtotime('now') - $token_ts) . " seconds old");
                        throw new TimezoneException("The cancel appointment link has expired.  Please try again from the original URL.");
                    }
                    if ($config_key !== $token_config_key) {
                        $this->emDebug("Config key from key does not match that of token: $config_key != $token_config_key");
                        throw new TimezoneException("The cancel appointment link is invalid.  Please try again from the original URL or contact the study team.");
                    }
                    $allow_cancel_past_appointments = ($this->isAuthenticated() && $this->getUser()->hasDesignRights());
                    $data = $this->cancelAppointment($slot_id, $config_key, $record, $event_id, $repeat_instance, $allow_cancel_past_appointments);
                    $result = [
                        "success" => true,
                        "message" => "Your appointment has been successfully canceled.  You may now close this window."
                    ];
                    break;
                case "resetAppointment":
                    // This is called from the admin interface
                    $config_key = $payload['config_key'] ?? null;
                    $record = $payload['record'] ?? null;
                    $instance = $payload['instance'] ?? 1;
                    $data = $this->resetAppointment($config_key, $record, $instance);
                    $result = [
                        "success" => true,
                        "data" => $data
                    ];
                    break;
                case "resetSlotAndAppointment":
                    // This is called from the admin interface
                    $config_key = $payload['config_key'] ?? null;
                    $record = $payload['record'] ?? null;
                    $instance = $payload['instance'] ?? 1;
                    $slot_id = $payload['slot_id'] ?? null;
                    $message = $payload['message'] ?? $user_id . " reset slot at " . date('Y-m-d H:i:s');
                    $data = $this->resetSlotAndAppointment($config_key, $slot_id, $message, $record, $instance);
                    $result = [
                        "success" => true,
                        "data" => $data
                    ];
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

                case "reserveSlot":
                    // Reserve a specific appointment slot
                    $config_key = $payload['config_key'];
                    $slot_id = $payload['slot_id'];
                    $timezone = $payload['timezone'] ?? null;
                    $data = $this->reserveSlot($config_key, $slot_id, $timezone, $project_id, $record, $instrument, $event_id, $repeat_instance);
                    $result = [
                        "success" => true,
                        "data" => $data
                    ];
                    break;
                case "resetSlot":
                    // Clear or Clear and Cancel a specific appointment slot
                    $config_key = $payload['config_key'];
                    $slot_id = $payload['slot_id'];
                    $message = $payload['message'] ?? "Slot $slot_id reset by $user_id";
                    $cancel_slot = $payload['cancel_slot'] ?? false;
                    // For testing purposes only - reset a slot to unreserved state
                    $data = $this->resetSlot($config_key, $slot_id, $message, $cancel_slot);
                    $result = [
                        "success" => true,
                        "message" => "Slot $slot_id " . ($cancel_slot ? "canceled" : "reset") . " successfully"
                    ];
                    break;
                case "cancelSlot":
                    // Cancel a specific appointment slot
                    $config_key = $payload['config_key'];
                    $slot_id = $payload['slot_id'];
                    $message = $payload['message'] ?? "Slot $slot_id canceled by $user_id";
                    $data = $this->resetSlot($config_key, $slot_id, $message, true);
                    $result = [
                        "success" => true,
                        "message" => "Slot $slot_id canceled successfully"
                    ];
                    break;
                default:
                    // Action not defined
                    throw new Exception ("Action $action is not defined");
            }
         } catch (TimezoneException $e) {
            $this->emError("TimezoneException caught in redcap_module_ajax for action $action: " . $e->getMessage(), $payload);
            $result = [
                "success" => false,
                "message" => $e->getMessage()
            ];
        } catch (Exception $e) {
            $this->emError("Unknown Exception caught in redcap_module_ajax for action $action: " . $e->getMessage(), $payload);
            if (!empty($this->lock_name)) {
                $this->emError("Releasing lock for " . $this->lock_name . " due to exception");
                $this->releaseLock($this->lock_name);
            }
            $result = [
                "success" => false,
                "message" => "An exception occurred -- please check the server logs"
            ];
        }

        // Return is left as php object, is converted to json automatically
        //$this->emDebug("redcap_module_ajax returning result: ", $result);
        return $result;
    }

    public function slotToCalendarConfig($slot) {
        // $this->emDebug("slotToCalendarConfig called with slot: ", $slot);
        if (empty($slot)) return null;

        $server_dt = new DateTime($slot['date'] . ' ' . $slot['time']);
        $server_dt_end = clone $server_dt;
        $server_dt_end->add(new DateInterval('PT1H')); // Default to 1 hour duration
        $add_to_calendar_config = [
            // "name" => $slot['title'] ?? "Appointment",
            "name" => "Test",
            "description" => "description placeholder",
            "startDate" => $server_dt->format('Y-m-d'),
            "startTime" => $server_dt->format('H:i'),
            "endTime" => $server_dt_end->format('H:i'),
            "timeZone" => $server_dt->getTimezone()->getName(),
            "debug" => true
        ];

        // name: "[Reminder] Test the Add to Calendar Button",
        // description: "Check out the maybe easiest way to include Add to Calendar Buttons to your web projects:[br]→ [url]https://add-to-calendar-button.com/|Click here![/url]",
        // startDate: "2025-09-23",
        // startTime: "10:15",
        // endTime: "23:30",
        // options: ["Google", "iCal"],
        // timeZone: "America/Los_Angeles"
        return $add_to_calendar_config;
    }


    // Inject HTML for timezone selector functionality
    public function injectHTML() {
        ?>
            <!-- Button to trigger appointment modal -->
            <!-- <button type="button" id="tz_selector_button" class="btn-primaryrc btn btn-xs float-right" data-toggle="modal" data-target="#tz_select_modal">
                Edit Timezone
            </button> -->
            <!-- <button type="button" id="tz_selector_cancel" class="btn-danger btn btn-xs float-right">
                Cancel
            </button> -->

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
                        <div class='add-container'>
                        </div>
                        <button type="button" data-action="cancel-appt" class="btn-secondary btn btn-xs float-right">
                            <i class="fas fa-times"></i> Cancel Appt
                        </button>
                        <div style="clear:both;"></div>
                    </div>
                </div>
                <div class="display-error" style="width:90%; display:none;">
                    <div class='error-msg alert alert-warning'>Error</div>
                </div>
            </div>

            <!-- Modal for selecting appointment -->
            <div id="tz_select_appt_modal" aria-modal="true" class="modal fade" tabindex="-1" role="dialog">
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
                            <button id='tz_select_edit_timezone_button' type="button" class="btn-success btn btn-sm me-auto" data-toggle="modal" data-target="#tz_select_timezone_modal" data-dismiss="modal">
                                <i class="fas fa-edit"></i> Change Timezone
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
                            <button id="tz_select_save_button" type="button" class="btn btn-primaryrc btn-sm">Save</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal for selecting timezone -->
            <div id="tz_select_timezone_modal" aria-modal="true" class="modal fade" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Select Timezone</h5>
                        </div>
                        <div class="modal-body" style="width:100%;">
                            <p>Select the timezone to use when viewing available appointments:</p>
                            <select id="tz_select_timezone" class="form-control"></select>
                        </div>
                        <div class="modal-footer">
                            <button data-target="#tz_select_appt_modal" data-toggle="modal" data-dismiss="modal" id="tz_select_clear_timezone_button" type="button" class="btn btn-success btn-sm me-auto"><i class="fas fa-globe"></i> Auto Detect Timezone</button>
                            <button data-target="#tz_select_appt_modal" data-toggle="modal" data-dismiss="modal" id="tz_select_save_timezone_button" type="button" class="btn btn-primary btn-sm">Set Timezone</button>
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
                        <!-- <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button> -->
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

            <style>
                .select2-container--default .select2-results__group {
                    cursor: default;
                    display: block;
                    background-color: #337ab7;
                    color: white;
                }

                /** format the selected appointment */
                #tz_select_appt_modal .tz-selection {
                    margin-top:2px;
                    font-weight:600;
                }

                /** hide the input for the appointment field */
                .tz_data_wrapper [data-kind="field-value"] {
                    display:none;
                }

                /** make the clear x red */
                #tz_select_appt_modal .select2-container--default .select2-selection--multiple .select2-selection__clear {
                    color: #e74c3c;
                }

                /** add a line border between dates in the select2 dropdown */
                li.select2-results__option {
                    border-top: 1px solid #eee;
                }

                /* Style for top modal */
                .top-modal {
                    z-index: 1061 !important;
                }

                .top-modal .modal-content {
                    border-color: black;
                    border-width: 2px;
                }

                .top-modal .modal-header {
                    background-color: #ffc1074c; /* Yellow with alpha for emphasis */
                    /* border-width: 0.3rem; */
                    /* color: white; */
                    /* border-bottom: 1px solid #DC3545; */
                }

                .selected-appointment {
                    background-color: #fff2b0;
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


    /**
     * Filters the timezone configurations based on the given instrument and event ID.
     * Only enabled configurations that match the instrument and event ID are returned.
     * @param string $instrument The instrument name to filter by.
     * @param int $event_id The event ID to filter by.
     * @return array An associative array where keys are appointment fields and values contain configuration details.
     */
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


    /**
     * Loads timezone configurations from project settings if not already loaded.
     * Each configuration is indexed by a unique key combining the appointment field and event ID.
     * Invalid or duplicate configurations are logged and skipped.
     * The loaded configurations are stored in the $this->config property.
     */
    private function load_tz_configs() {
        if (empty($this->config)) {
            $this->config = [];
            $instances = $this->getSubSettings('instances');
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
        if ($this->emLoggerDebugMode()) $cmds[] = "module.debugger=true";
        if (!empty($init_method)) $cmds[] = "module.afterRender(module." . $init_method . ")";

        // $this->emDebug($cmds);
        $spacer=";\n".str_repeat(" ",16);
        ?>
        <!--
         Load Add to Calendar Button library
         Copied to EM on 9/20/25 from https://cdn.jsdelivr.net/npm/add-to-calendar-button
         Submit PR to request update
        -->
        <!-- <script src="<?=$this->getUrl("assets/add-to-calendar-button.js",true)?>"></script> -->
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
    //    $this->emDebug(__FUNCTION__ . " called for project $project_id");
    }

}
