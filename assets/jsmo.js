// This file extends the default JSMO object with methods for this EM
;{
    // Define the jsmo in IIFE so we can reference object in our new function methods
    const module = ExternalModules.Stanford.TimezoneScheduler;
    // console.log(module);

    // A global place to store links to the timezone select2 instances
    var timezoneSelect2s = [];

    const cookie_name = 'tz_scheduler_client_timezone';

    // Extend the official JSMO with new methods for this EM
    Object.assign(module, {

        debug: function(...args) {
            if (module.debugger) {
                const caller = this.debug.caller.name ? this.debug.caller.name : 'unknown';
                console.log("[TimezoneScheduler => " + caller + "]\n", ...args);
            }
        },

        // This function is called on survey and data entry form render to initialize the form
        initializeInstrument: function() {

            if (!module.data.config) {
                module.debug("No configuration found for this instrument");
                return;
            }

            // loop through module.data.config to set up all appointment selectors
            for (const [field, config] of Object.entries(module.data.config)) {

                // Get the name of the field in this form that should be an appt select widget
                $input = $(`input[name="${field}"]`);
                config_key = config['config_key'];

                // Format the rendering
                $td = $input.closest('td.data');

                // Hide the current contents of the slot-id field
                $td.children().wrapAll('<div class="tz_hidden hide bg-danger"></div>');

                // Render an error message if the survey/form is on a not-saved record_id
                if(module.data.record_id === null) {
                    // This form contains a config entry for a slot_id field on a record that doesn't yet exist.
                    if (module.data.context == "redcap_survey_page") {
                        var msg = "The survey you are viewing has a configuration error with a module called the 'Timezone Scheduler'.\n\nThis module cannot be linked to a field on a record that has not yet been saved.\n\nPlease notify the survey administrator of this message.  The rest of this form should still work, but the appointment selector field will not operate as intended.";
                    } else {
                        // Data Entry Form message
                        var msg = "The field " + field + " can not be used by the Timezone Scheduler module if it is not on an already existing record.  Please redesign your project such that the record already exists or save and continue before trying to enable a field with this module.";
                    }
                    module.debug(msg);
                    module.notify("Configuration Error", msg);
                    return;
                }

                // Clone the container template and insert into the dom
                var $ct = $('#tz_select_container_template').clone()
                .css({'display':'inline'})
                .attr('id', 'tz_select_container_' + field)
                // .data('config_key', config_key)
                .data('field', field)
                .prependTo($td);

                // get the select element inside the template
                var $sel = $('select', $ct)
                .attr('id', 'tz_select_' + field);

                module.data.config[field]['$input'] = $input;
                module.data.config[field]['jq_container'] = $ct;
                module.data.config[field]['jq_select'] = $sel;

                // customize the select button if specified
                if (config['appt-button-label']) {
                    $('button[data-action="select-appt"] .button-text', $ct).html(config['appt-button-label']);
                }


                // Make sure the container is showing the right content
                module.updateContainer(field);
            }

            // Add handler for cancel/reschedule clicks
            $('#questiontable').on('click', 'button[data-action="cancel-appt"]', function() {
                var field = $(this).closest('.tz_select_container').data('field');
                module.debug('Cancel Field:', field);
                module.confirm('Cancel Appointment', 'Are you sure you want to cancel your appointment?  If this is allowed, you will be able to select a new appointment time.', function(confirmed) {
                    if (confirmed) {
                        module.cancelAppointment(field);
                    }
                });
                // if(confirm('You are about to cancel your appointment.  If this is allowed, you will be able to select a new appointment time.\n\nClick OK to continue.')) {
                    // module.cancelAppointment(field);
                // }
            });

            // Add handler for schedule click next to slot button
            $('#questiontable').on('click', 'button[data-action="select-appt"]', function() {
                module.debug('Select Button Click', $(this));
                var field = $(this).closest('.tz_select_container').data('field');
                module.debug('Field:', field);
                module.setAppointmentField(field);
                module.refreshAppointmentSelector();
            });

            // Save Appointment Button
            $('#tz_select_save_button').on('click', function() {
                module.saveAppointmentButton();
            });


            // When timezone edit button is pressed from appointment scheduler, load the timezones
            $('#tz_select_edit_timezone_button').on('click', function() {
                module.debug('Edit Timezone Button Clicked');
                module.refreshTimezoneSelector();
            });


            // When the timezone is updated, we save and refresh the appointment selector
            $('#tz_select_save_timezone_button').on('click', function() {
                tz = $('#tz_select_timezone');
                module.debug('Timezone Save Button Clicked', tz.val());
                module.setTimezone(tz.val());
                module.refreshAppointmentSelector();
            });

        },



        // Cancel Appointment
        cancelAppointment: function(field) {
            module.debug('Cancel Appointment called for field:', field);
            // Logic to cancel the appointment goes here

            $input = module.data.config[field]['$input'];
            module.debug('Field:', field, '$input:', $input);
            const slot_id = $input.val();
            const config_key = module.data.config[field]['config_key'];
            const payload = {
                'slot_id': slot_id,
                'config_key': config_key
            }
            module.ajax('cancelAppointment', payload).then(function(response) {
                module.debug('cancelAppointment response:', response);
                if (!response.success) {
                    module.debug("Failed to cancel appointment:", response);
                    module.notify('Unable to cancel appointment:\n\n' + response.message);
                    return;
                }

                // loop through response.data and set values in form
                for (const [key, value] of Object.entries(response.data)) {
                    const input = $('input[name="' + key + '"]');
                    if (input) {
                        input.val(value).trigger('change');
                    }
                }
                $input.data('slot_text', null);
                $input.data('slot_timezone', null);


                // Close the modal
                $('#tz_select_appt_modal').modal('hide');

                // Refresh the slot input
                module.updateContainer(field);
            }).catch(function (err) {
                console.error('Error fetching appointment data:', err);
            });
        },

        // Handle when new appointment is saved from the modal ui
        saveAppointmentButton: function() {
            tz_select_appt = $('#tz_select_appt');
            module.debug("Appointment SAVE clicked ", tz_select_appt.val(), tz_select_appt.data('field'), tz_select_appt.select2('data'));

            slot_id = tz_select_appt.val();
            if (!slot_id) {
                alert('Please select an appointment before saving.  To cancel an appointment (if allowed) use the button on the entry form');
                return;
            }

            field = tz_select_appt.data('field');
            config_key = module.data.config[field]['config_key'];

            // Get all the option label data to send to the server
            let option_selected = tz_select_appt.select2('data')[0];
            // module.debug("Option Data:", option_selected);

            payload = {
                'slot_id': slot_id,
                'config_key': config_key,
                'timezone': module.getTimezone(),
                'text': option_selected['text'],
                'server_dt': option_selected['server_dt']
            };
            module.ajax('reserveSlot', payload).then(function (response) {
                if (response.success) {
                    module.debug("Slot reserved successfully", response.data);
                    // TODO: Set data
                    // loop through response.data and set values in form
                    for (const [key, value] of Object.entries(response.data)) {
                        const input = $('input[name="' + key + '"]');
                        if (input) {
                            input.val(value).trigger('change');
                        }
                    }

                    // Close the modal
                    module.debug("About to hide modal", $('#tz_select_appt_modal'));
                    $('#tz_select_appt_modal').modal('hide');
                    module.debug("Modal closed");

                    // Refresh the slot input
                    module.updateContainer(field);
                } else {
                    module.debug("Failed to reserve slot:", response);
                    alert('failed - unable to reserve selected appointment:\n\n' + option_selected['text'] + '\n\nPlease try again.');
                    module.refreshAppointmentSelector();  // or do we click the select button again?
                }
            }).catch(function (err) {
                module.debug("Error reserving slot:", err);
            });
        },


        getTimezone: function() {
            // First, see if the timezone cookie exists
            var tz = null;
            var arr = document.cookie.split('; ');
            for (var i = 0; i < arr.length; i++) {
                var cookie = arr[i];
                if (cookie.substring(0, cookie_name.length) == cookie_name) {
                    tz = cookie.substring(cookie_name.length + 1);
                    // module.debug('Found timezone cookie:' + cookie_name, tz);
                    break;
                }
            }

            if (!tz) {
                tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                module.debug('No timezone cookie (' + cookie_name + ') found, using default:', tz);
            }

            return tz;
        },

        setTimezone: function(tz) {
            document.cookie = cookie_name + '=' + tz + '; path=/;SameSite=Strict';
        },

        // Called upon change of a select2 to verify the appointment is reserved
        saveAppointment: function(field_name, slot_id, timezone) {
            module.debug("Saving appointment for field:", field_name, "and slot:", slot_id);
            // Here you would typically make an AJAX call to save the appointment
            module.ajax('saveAppointment', {
                field_name: field_name,
                slot_id: slot_id,
                timezone: timezone
            }).then(function(response) {
                module.debug("Appointment saved successfully:", response);
            }).catch(function(err) {
                console.error("Error saving appointment:", err);
            });
        },

        //TODO - current in code for updateContainer
        getAppointmentData: function(field) {
            var config_key = module.data.config[field]['config_key'];
            var timezone = module.getTimezone();
            var payload = {
                "config_key": config_key,
                "timezone": timezone
            }

            // slot_id = module.data.config[field]['config_key']

            // ['$input'].val();
            // var config_key = module.data.config[field]['config_key'];

            // if (slot_id) {
            //     var payload = {
            //         "slot_id": slot_id,
            //         "config_key": config_key,
            //         "timezone": timezone
            //     };

            module.ajax('getAppointmentData', payload).then(function(response) {
                module.debug('getAppointmentData response:', response);
            }).catch(function (err) {
                console.error('Error fetching appointment data:', err);
            });
        },

        setAppointmentField: function(field) {
            jq_select = $('#tz_select_appt');
            jq_select.data('field', field);
        },

        // Simple modal notification
        notify: function(title, message) {
            var $modalCopy = $('#tz_select_confirm_modal').clone().attr('id', 'tz_select_notify_modal_' + Math.floor(Math.random() * 1000000));
            $('.modal-title', $modalCopy).html(title);
            $('.modal-body', $modalCopy).html(message);
            var modal = new bootstrap.Modal($modalCopy.get(0));
            $('button[data-action="cancel"]', $modalCopy).remove();
            $('button[data-action="delete"]', $modalCopy).remove();
            modal.show();
        },

        confirm: function(title, message, callback) {
            var $modalCopy = $('#tz_select_confirm_modal').clone().attr('id', 'tz_select_confirm_modal_' + Math.floor(Math.random() * 1000000));
            $('.modal-title', $modalCopy).html(title);
            $('.modal-body', $modalCopy).html(message);
            var modal = new bootstrap.Modal($modalCopy.get(0));

            $('button[data-action="delete"]', $modalCopy)
            .show()
            .on('click', function() {
                if (callback) callback(true);
                modal.hide().dispose();
                $modalCopy.remove();
            });
            $('button[data-action="cancel"]', $modalCopy)
            .show()
            .on('click', function() {
                modal.hide().dispose();
                $modalCopy.remove();
            });
            $('button[data-action="ok"]', $modalCopy).hide();
            modal.show();
        },

        // Pull all appointment slots and update the select2 input
        refreshAppointmentSelector: function(){
            // Assumes that the modal is already visible
            jq_select = $('#tz_select_appt');
            field = jq_select.data('field');
            if (!field) {
                module.debug('No field data found for timezone selector');
                return;
            }

            // For reasons I can't figure out, the default options don't seem to work unless I create and destroy it first,
            // so I'll create it here the first time so it behaves the same when used many times in one session
            if (!jq_select.hasClass('select2-hidden-accessible')) {
                jq_select.select2();
            }

            // Clear existing values
            // module.debug("Destroying existing select2");
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
            $('#tz_display').html('Displaying appointments in the <b>' + timezone + '</b>    timezone');

            module.ajax('getAppointmentOptions', $payload).then(function (response) {
                module.debug('getAppointmentOptions response: ', response);
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
                        // module.debug(data);
                        // if (!data.id) {
                        //     return '<span>Select an appointment!<br><i>(you can change timezones if the current times are not correct)</i></span>';
                        //     return data.text;
                        // }
                        if (!data.id) {
                            return data.text;
                        }

                        var title = data.title ? '<span><b>' + data.title + '</b></span><br/>' : '';
                        var $result = $('<div>' + title + '<b>' + data.text + '</b> <span class="small" style="color:#888;"><i>(' + data.diff + ')</i></span></div>');
                        var description = data.description.replace(/\n/g, '<br/>');
                        module.debug(description);
                        $result = $('<div>' + description + '</div>');
                        return $result;
                    },
                    placeholder: "Select an appointment...",
                    allowClear: true
                }).on('change', function() {
                    // We are going to ignore change event, as all that matters is a 'SAVE' event
                    module.debug('change event triggered');
                    // var selectedAppointment = $(this).val();
                    // module.debug("Selected Appointment:", selectedAppointment);
                });
            }).catch(function (err) {
                module.debug("Error fetching appointments: ", err);
                alert(err);
            });
        },

        // Pull (or reuse) timezone values for select2 input
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
                        module.debug('change event triggered for timezone!!');
                    }).val(module.getTimezone()).trigger('change');

                }).catch(function (err) {
                    // Handle error
                    module.debug("Error fetching timezones: ", err);
                    alert(err);
                });
            } else {
                jq_select = $('#tz_select_timezone').val(module.getTimezone()).trigger('change');
            }
        },

        // Update rendering of the appointment container based on slot value
        updateContainer: function(field) {
            let $input = module.data.config[field].$input;
            let slot_id = $input.val();
            let jq_container = module.data.config[field]['jq_container'];

            module.debug('Update Container called for field:', field, 'slot_id:', slot_id, 'container:', jq_container);
            let mode = 'display';

            if (slot_id) {
                // If there's a slot value - let's see if we have the nice text for the current timezone
                let slot_text = $input.data('slot_text');
                let slot_timezone = $input.data('slot_timezone');
                let timezone = module.getTimezone();
                module.debug('slot_text:', slot_text, 'slot_timezone:', slot_timezone, 'current timezone:', timezone);

                if (!slot_text) {
                    module.debug('No slot_text data found for field:', field);
                    var payload = {
                        "config_key": module.data.config[field]['config_key'],
                        "timezone": timezone,
                    }
                    module.ajax('getAppointmentData', payload).then(function(response) {
                        module.debug('getAppointmentData response:', response, field, slot_id);
                        if (response.success) {
                            if (response.data.id !== slot_id) {
                                // This should NEVER happen
                                alert ("This record's current " + field + " value of " + slot_id + "\ndoes not match the saved value of " + response.data.id + ".  This shouldn't happen!  Please notify an administrator.");
                                return false;
                            }
                            // $input.data('slot_text', response.data.text);
                            $input.data('slot_text', response.data.description);
                            $input.data('slot_timezone', response.data.timezone);
                            module.updateContainer(field);  // Recurse once
                        } else {
                            module.notify('Exception', 'Unable to load appointment data for ' + field + ':\n\n' + response.message ?? 'Unknown error');
                        }
                    }).catch(function (err) {
                        console.error('Error fetching appointment data:', err);
                        module.notify('Exception', 'AJAX error fetching: ' + field);
                    });
                    return;
                } else {
                    module.debug('Using existing slot_text data for field:', field);
                    $('.appt-text', jq_container).html(slot_text.replace(/\n/g, '<br/>'));
                }
                $('.display-value', jq_container).show();
                $('.select-value', jq_container).hide();
            } else {
                mode = 'select';
                $('.display-value', jq_container).hide();
                $('.select-value', jq_container).show();
            }

            //     if (!slot_text || slot_timezone !== timezone) {
            //         // We need to query to refresh this field
            //         // result = module.getAppointmentData(field);
            //         var payload = {
            //             "config_key": module.data.config[field]['config_key'],
            //             "timezone": timezone,
            //         }
            //         module.ajax('getAppointmentData', payload).then(function(response) {
            //             module.debug('getAppointmentData response:', response, field, slot_id);
            //             if (response.success) {
            //                 if (response.data.id !== slot_id) {
            //                     // This means the current value doesn't match the saved value.  This shouldn't happen
            //                     alert ("This record's current " + field + " value of " + slot_id + "\ndoes not match the saved value of " + response.data.id + ".  This shouldn't happen.");
            //                     return false;
            //                 }
            //                 // $input.data('slot_text', response.data.text);
            //                 $input.data('slot_text', response.data.description);
            //                 $input.data('slot_timezone', timezone);
            //                 module.updateContainer(field);  // Recurse once
            //             }
            //         }).catch(function (err) {
            //             console.error('Error fetching appointment data:', err);
            //         });
            //         return;
            //     } else {
            //         // We have the text already
            //         $('.appt-text', jq_container).html(slot_text.replace(/\n/g, '<br/>'));
            //         $('.slot-id', jq_container).text('(Slot #' + slot_id + ')');
            //     }
            //     $('.display-value', jq_container).show();
            //     $('.select-value', jq_container).hide();

            // } else {
            //     // It is empty
            //     $('.display-value', jq_container).hide();
            //     $('.select-value', jq_container).show();
            // }
        },










    });
}
