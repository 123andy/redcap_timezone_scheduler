// This file extends the default JSMO object with methods for this EM
;{
    // Define the jsmo in IIFE so we can reference object in our new function methods
    const module = ExternalModules.Stanford.TimezoneScheduler;
    console.log(module);

    // A global place to store links to the timezone select2 instances
    var timezoneSelect2s = [];

    const cookie_name = 'tz_scheduler_client_timezone';

    // Extend the official JSMO with new methods for this EM
    Object.assign(module, {


        initializeInstrument: function() {

            if (!module.data.config) {
                console.log("No configuration found for this instrument");
                return;
            }

            // loop through module.data.config
            for (const [field, config] of Object.entries(module.data.config)) {

                // Get the name of the field in this form that should be an appt select widget
                jq_input = $(`input[name="${field}"]`);
                config_key = config['config_key'];

                // Format the rendering
                td = jq_input.closest('td.data');

                // Hide the current contents of the slot-id field
                td.children().wrapAll('<div class="tz_hidden xxhide bg-danger"></div>');

                // Render an error message if the survey/form is on a not-saved record_id
                if(module.data.record_id === null) {
                    // This form contains a config entry for a slot_id field on a record that doesn't yet exist.
                    if (module.data.context == "redcap_survey_page") {
                        var msg = "The survey you are viewing has a configuration error with the Timezone Scheduler.\n\nField cannot be present on unsaved record.\n\nPlease notify the survey administrator of this message.";
                    } else {
                        var msg = "The field " + field + " can not be used by the Timezone Scheduler module if it is not on an already existing record.  Please redesign your project such that the record already exists or save and continue before trying to enable a field with this module.";
                    }
                    console.log(msg);
                    alert(msg);
                    return;
                }

                // Clone the container template and insert into the dom
                var ct = $('#tz_select_container_template').clone()
                .css({'display':'inline'})
                .attr('id', 'tz_select_container_' + field)
                // .data('config_key', config_key)
                .data('field', field)
                .prependTo(td);

                // get the select element inside the template
                var sel = $('select', ct)
                .attr('id', 'tz_select_' + field);

                module.data.config[field]['jq_input'] = jq_input;
                module.data.config[field]['jq_container'] = ct;
                module.data.config[field]['jq_select'] = sel;

                // Make sure the container is showing the right content
                module.updateContainer(field);
            }

            // Add handler for cancel/reschedule clicks
            $('#questiontable').on('click', 'button[data-action="cancel-appt"]', function() {
                console.log('Cancel Button Click', $(this));
            });

            // Add handler for schedule click next to slot button
            $('#questiontable').on('click', 'button[data-action="select-appt"]', function() {
                console.log('Select Button Click', $(this));
                var field = $(this).closest('.tz_select_container').data('field');
                console.log('Field:', field);
                module.setAppointmentField(field);
                module.refreshAppointmentSelector();
            });

            // Save Appointment Button
            $('#tz_select_save_button').on('click', function() {
                module.saveAppointmentButton();
            });


            // When timezone edit button is pressed from appointment scheduler, load the timezones
            $('#tz_select_edit_timezone_button').on('click', function() {
                console.log('Edit Timezone Button Clicked');
                module.refreshTimezoneSelector();
            });


            // When the timezone is updated, we save and refresh the appointment selector
            $('#tz_select_save_timezone_button').on('click', function() {
                tz = $('#tz_select_timezone');
                console.log('Timezone Save Button Clicked', tz.val());
                module.setTimezone(tz.val());
                module.refreshAppointmentSelector();
            });

        },

        // Handle when new appointment is saved from the modal ui
        saveAppointmentButton: function() {
            tz_select_appt = $('#tz_select_appt');
            console.log("Appointment SAVE clicked ", tz_select_appt.val(), tz_select_appt.data('field'), tz_select_appt.select2('data'));

            slot_id = tz_select_appt.val();
            option_data = tz_select_appt.select2('data');
            option_text = option_data[0].text;
            field = tz_select_appt.data('field');

            // slot_field = $('#tz_select_container_' + field);
            config_key = module.data.config[field]['config_key'];

            if (!slot_id) {
                alert('Please select an appointment before saving.  To cancel an appointment (if allowed) use the button on the entry form');
                return;
            }

            // TODO: See if that value is still available and reserve it!
            payload = {
                "slot_id": slot_id,
                "config_key": config_key
            };
            module.ajax('reserveSlot', payload).then(function (response) {
                if (response.success) {
                    console.log("Slot reserved successfully:", response.data);
                    console.log(tz_select_appt.select2('data'));

                } else {
                    console.log("Failed to reserve slot:", response);
                    alert('failed - unable to reserve selected appointment:\n\n' + option_text + '\n\nPlease try again.');
                    module.refreshAppointmentSelector();  // or do we click the select button again?
                }
            }).catch(function (err) {
                console.log("Error reserving slot:", err);
            });
        },

        //TODO
        InitializeField: function() {
        },



        getTimezone: function() {
            // First, see if the timezone cookie exists
            var tz = null;
            var arr = document.cookie.split('; ');
            for (var i = 0; i < arr.length; i++) {
                var cookie = arr[i];
                if (cookie.substring(0, cookie_name.length) == cookie_name) {
                    tz = cookie.substring(cookie_name.length + 1);
                    // console.log('Found timezone cookie:' + cookie_name, tz);
                    break;
                }
            }

            if (!tz) {
                tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                console.log('No timezone cookie (' + cookie_name + ') found, using default:', tz);
            }

            return tz;
        },

        setTimezone: function(tz) {
            document.cookie = cookie_name + '=' + tz + '; path=/;SameSite=Strict';
        },

        // Called upon change of a select2 to verify the appointment is reserved
        saveAppointment: function(field_name, slot_id, timezone) {
            console.log("Saving appointment for field:", field_name, "and slot:", slot_id);
            // Here you would typically make an AJAX call to save the appointment
            module.ajax('saveAppointment', {
                field_name: field_name,
                slot_id: slot_id,
                timezone: timezone
            }).then(function(response) {
                console.log("Appointment saved successfully:", response);
            }).catch(function(err) {
                console.error("Error saving appointment:", err);
            });
        },

        //TODO
        getAppointmentData: function(field) {
            var slot_id = module.data[field]['jq_input'].val();
            var timezone = module.getTimezone();
            var config_key = module.data.config[field]['config_key'];

            if (slot_id) {
                var payload = {
                    "slot_id": slot_id,
                    "config_key": config_key,
                    "timezone": timezone
                };
                module.ajax('getAppointment', payload).then(function(response) {
                    console.log('getAppointment response:', response);
                    // If the response is an object, not an array, you can convert it to
                });
            }
        },

        setAppointmentField: function(field) {
            jq_select = $('#tz_select_appt');
            jq_select.data('field', field);
        },

        refreshAppointmentSelector: function(){
            // Assumes that the modal is already visible
            jq_select = $('#tz_select_appt');
            field = jq_select.data('field');
            if (!field) {
                console.log('No field data found for timezone selector');
                return;
            }

            // For reasons I can't figure out, the default options don't seem to work unless I create and destroy it first,
            // so I'll create it here the first time so it behaves the same when used many times in one session
            if (!jq_select.hasClass('select2-hidden-accessible')) {
                jq_select.select2();
            }

            // Clear existing values
            // console.log("Destroying existing select2");
            jq_select.select2('destroy').empty();

            // Initalize with loading state...
            jq_select.data('field', field).select2({
                data: [{id:'',text:'Loading...'}]
            });

            timezone = module.getTimezone();

            $payload = {
                "config_key": module.data.config[field]['config_key'],
                "timezone": timezone
            };

            // Update timezone display
            $('#tz_display').text('Displaying appointments in the ' + timezone + ' timezone');

            module.ajax('getAppointmentOptions', $payload).then(function (response) {
                console.log('getAppointmentOptions response: ', response);
                // If the response is an object, not an array, you can convert it to an array using the object map like below:
                // data = Object.entries(response.data).map(
                //     ([k,v]) => ({id: v.id, text: v.text })
                // );
                data = response.data;
                jq_select.empty().select2({
                    width: '100%',
                    dropdownParent: $('#tz_select_appt_modal'), // Required for bootstrap modals
                    data: data,
                    templateResult: function (data) {
                        // This allows you to add HTML to the select data 'text' attribute without affecting its value in the actual input box
                        // console.log(data);
                        // if (!data.id) {
                        //     return '<span>Select an appointment!<br><i>(you can change timezones if the current times are not correct)</i></span>';
                        //     return data.text;
                        // }
                        var $result = $('<span><b>' + data.text + '</b> <span class="small" style="color:#888;"><i>(' + data.diff + ')</i></span></span>');
                        return $result;
                    },
                    placeholder: "Select an appointment...",
                    allowClear: true
                }).on('change', function() {
                    // We are going to ignore change event, as all that matters is a 'SAVE' event
                    console.log('change event triggered');
                    // var selectedAppointment = $(this).val();
                    // console.log("Selected Appointment:", selectedAppointment);
                });
            }).catch(function (err) {
                console.log("Error fetching appointments: ", err);
                alert(err);
            });
        },

        refreshTimezoneSelector: function() {
            // Assumes that the modal is already visible
            jq_select = $('#tz_select_timezone');

            if (!jq_select.hasClass('select2-hidden-accessible')) {
                // Initialize the select2
                module.ajax('getTimezones', []).then(function (response) {
                    // data = Object.entries(response.timezones).map(([k,v]) => ({id: v, text: v}));
                    data = response.data;
                    jq_select.select2({
                        width: '100%',
                        dropdownParent: $('#tz_select_timezone_modal'),
                        data: data,
                        placeholder: "Select your timezone",
                        allowClear: true
                    }).on('change', function() {
                        console.log('change event triggered for timezone!!');
                    }).val(module.getTimezone()).trigger('change');

                }).catch(function (err) {
                    // Handle error
                    console.log("Error fetching timezones: ", err);
                    alert(err);
                });
            } else {
                jq_select = $('#tz_select_timezone').val(module.getTimezone()).trigger('change');
            }
        },

        // not sure if this is being used yet...
        updateContainer: function(field) {
            slot_id = module.data.config[field]['jq_input'].val();
            jq_container = module.data.config[field]['jq_container'];

            if (slot_id) {
                // If there's a slot value, we should fetch/verify appointment data
                // and render it in the timezone of the viewer
                console.log('Fetching appointment data for slot ID:', slot_id);

                var result = module.getAppointmentData(field);


                $('.display-value', jq_container).show();
                $('.select-value', jq_container).hide();

            } else {
                // It is empty
                $('.display-value', jq_container).hide();
                $('.select-value', jq_container).show();
            }

        },










    });
}
