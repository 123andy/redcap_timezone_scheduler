// This file extends the default JSMO object with methods for this EM
;{
    // Define the jsmo in IIFE so we can reference object in our new function methods
    const module = ExternalModules.Stanford.TimezoneScheduler;

    const cookie_name = 'tz_scheduler_client_timezone';

    var script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/add-to-calendar-button';
    script.setAttribute('async', true);
    script.setAttribute('defer', true);
    script.onload = function() {
        // The script is loaded, safe to call atcb_action or initialize button
        module.debug("add-to-calendar-button library loaded");
        module.init_atcb();
    };
    document.head.appendChild(script);

    // Extend the official JSMO with new methods for this EM
    Object.assign(module, {

        // Used on the cancel.php page to handle the cancel appointment process
        cancelPageRequest: function() {
            module.debug("Cancel Page Request called");
            module.debug(module.data);

            $btn = $('#show-cancel-modal');
            $msg = $('#cancel-msg');

            var errors = module.data.errors || [];
            if (errors.length > 0) {
                module.debug("Errors found in cancel page request:", errors);
                var msg = "The appointment cancellation page has encountered the following error(s):\n\n";
                msg += errors.join("\n");

                $btn.hide();
                $msg.html("<div class='alert alert-danger fs-3'>" + msg.replace(/\n/g, '<br/>') + "</div>");
                return;
            }

            // Show the modal when the cancel button is clicked
            $modal = $('#cancelModal');

            // Show modal button
            $('#show-cancel-modal').on('click', function() {
                module.debug("Show Cancel Appointment Modal button clicked");
                var modal = new bootstrap.Modal($modal.get(0));
                modal.show();
            });

            // Handle Modal Cancel Press
            $('#cancel-appointment-button').on('click', function() {
                module.debug("Cancel Appointment button clicked");
                var payload = {
                    'key': module.data.key,
                    'token': module.data.token
                };
                module.ajax('cancelAppointmentFromUrl', payload).then(function(response) {
                    module.debug('cancelAppointmentFromUrl response:', response);
                    if (response.success) {
                        $msg.html("<div class='alert alert-success fs-6'>" + response.message + "</div>");
                        $btn.hide();
                    } else {
                        $msg.html("<div class='alert alert-danger fs-6'>Failed to cancel appointment:\n\n" + response.message + "</div>");
                        $btn.hide();
                    }
                }).catch(function (err) {
                    module.debug("Error occurred while canceling appointment:", err);
                    $msg.html("<div class='alert alert-danger fs-6'>An error occurred while trying to cancel your appointment.  Please try again later.</div>");
                });
            });
        },

        init_atcb: function() {
            return false;
            // Initialize the add-to-calendar-button
            $atcb = $('.add-container:visible');
            console.log("Found " + $atcb.length + " visible add-container elements");

            $atcb.each(function() {
                $btn = $('<add-to-calendar-button name="[Reminder] Test the Add to Calendar Button" description="Check out the maybe easiest way to include Add to Calendar Buttons to your web projects:[br]→ [url]https://add-to-calendar-button.com/|Click here![/url]" startDate="2025-09-23" startTime="10:15" endTime="23:30" options=\'["Google", "iCal"]\' timeZone="America/Los_Angeles"/>');

                $(this).append($btn);
                // var $btn = $(this);
                // $btn.on('click', function() {
                //     module.debug("Add to calendar button clicked");
                //     // Call the atcb_action function
                //     atcb_action($btn);
                // });
                module.debug("Initializing add-to-calendar-button:", $btn);
            });

        // $btn = $('add-to-calendar-button');
        // if ($btn.length > 0) {
        //     module.debug("atcb_init called from script onload");
        //     atcb_init();
        // } else {
        //     module.debug("No add-to-calendar-button elements found on page");
        // }


        },

        // Debug wrapper for easy toggle
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
                $td.children().wrapAll('<div class="tz_data_wrapper"></div>');

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
                var $container = $('#tz_select_container_template').clone()
                .css({'display':'inline'})
                .attr('id', 'tz_select_container_' + field)
                // .data('config_key', config_key)
                .data('field', field)
                .prependTo($td);

                // get the select element inside the template
                var $select = $('select', $container)
                .attr('id', 'tz_select_' + field);

                module.data.config[field]['$input'] = $input;
                module.data.config[field]['$container'] = $container;
                // module.data.config[field]['$select'] = $select;

                // customize the select button if specified
                if (config['appt-button-label']) {
                    $('button[data-action="select-appt"] .button-text', $container).html(config['appt-button-label']);
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
            });

            // // Add handler for add to calendar clicks
            // $('#questiontable').on('click', 'button[data-action="add-to-calendar"]', function() {
            //     var field = $(this).closest('.tz_select_container').data('field');
            //     //$input = module.data.config[field]['$input'];
            //     module.addToCalendar(field);
            // });

            // Add handler for schedule click next to slot button
            $('#questiontable').on('click', 'button[data-action="select-appt"]', function() {
                // module.debug('Select Button Click', $(this));
                // Set the field context to the select container:
                var field = $(this).closest('.tz_select_container').data('field');
                $('#tz_select_appt').data('field', field);
                // module.debug('Field:', field);
                module.refreshAppointmentSelector();
            });

            // Save Appointment Button
            $('#tz_select_save_button').on('click', function() {
                // module.debug('Save Button Clicked');
                module.saveAppointment();
            });


            // When timezone edit button is pressed from appointment scheduler, load the timezones
            $('#tz_select_edit_timezone_button').on('click', function() {
                // module.debug('Edit Timezone Button Clicked');
                module.refreshTimezoneSelector();
            });


            // When the timezone is updated, we save and refresh the appointment selector
            $('#tz_select_save_timezone_button').on('click', function() {
                tz = $('#tz_select_timezone');
                module.debug('Timezone Save Button Clicked', tz.val());
                module.setTimezone(tz.val());
                module.refreshAppointmentSelector();
            });

            // When the timezone is updated, we save and refresh the appointment selector
            $('#tz_select_clear_timezone_button').on('click', function() {
                tz = $('#tz_select_timezone');
                module.debug('Timezone Clear Button Clicked', tz.val());
                module.clearTimezone();
                module.refreshAppointmentSelector();
            });

        },

        addToCalendar: function(field) {
            // $input = module.data.config[field]['$input'];
            // module.debug('Field:', field, '$input:', $input);
            // const slot_id = $input.val();
            const config_key = module.data.config[field]['config_key'];
            payload = {
                'config_key': config_key,
                // 'timezone': module.getTimezone()
            }
            module.ajax('addToCalendar', payload).then(function(response) {
                module.debug('addToCalendar response:', response);
                if (!response.success || !response.data || !response.data.config) {
                    module.debug("Failed to obtain addToCalendar config:", response);
                    module.notify("Error", "Failed to obtain calendar information:\n\n" + response.message);
                    return;
                }

                $button = $('button[data-action="add-to-calendar"]', module.data.config[field]['$container']);
                if ($button.length === 0) {
                    module.debug("No add-to-calendar button found for field:", field);
                    return;
                }

                const config = response.data.config;
                const button = $button[0];
                module.debug("addToCalendar Config:", config, button);

                if (button) {
                    button.addEventListener('click', () => atcb_action(config, button));
                }

                button.trigger('click');


            }).catch(function (err) {
                console.error('Error fetching appointment data:', err);
            });

                // slot_id = $input.val();
                // slot_text = $input.data('slot_text');
                // slot_timezone = $input.data('slot_timezone');
                // module.debug('Add to Calendar Field:', field, slot_id, slot_text, slot_timezone);

                // const config = {
                //     name: "[Reminder] Test the Add to Calendar Button",
                //     description: "Check out the maybe easiest way to include Add to Calendar Buttons to your web projects:[br]→ [url]https://add-to-calendar-button.com/|Click here![/url]",
                //     startDate: "2025-09-23",
                //     startTime: "10:15",
                //     endTime: "23:30",
                //     options: ["Google", "iCal"],
                //     timeZone: "America/Los_Angeles"
                // };
                // atcb_action(config, this);

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

                // Update the form fields with returned data
                module.applyDataToForm(response.data);

                // Clear out the data attributes for the select so it refreshes properly
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


        applyDataToForm: function(data) {
            module.debug("applyDataToForm called with data:", data);
            for (const [key, value] of Object.entries(data)) {
                const e = $('input[name="' + key + '"], textarea[name="' + key + '"]');
                if (e.length > 1) {
                    module.debug("Multiple inputs found for key:", key, e);
                } else if (e.length === 0) {
                    module.debug("No input found for key:", key);
                }
                e.val(value).trigger('change');
            };
        },


        // Handle when new appointment is saved from the modal ui
        saveAppointment: function() {
            $tz_select_appt = $('#tz_select_appt');
            module.debug("Appointment SAVE clicked ",
                $tz_select_appt.val(),
                $tz_select_appt.data('field'),
                $tz_select_appt.select2('data')[0]
            );

            slot_id = $tz_select_appt.val();
            if (!slot_id) {
                module.notify('Invalid Selection', 'Please select an available appointment before saving.');
                return;
            }

            field = $tz_select_appt.data('field');
            config_key = module.data.config[field]['config_key'];

            // Get all the option label data to send to the server
            // let option_selected = $tz_select_appt.select2('data')[0];
            // module.debug("Option Data:", option_selected);

            payload = {
                'slot_id': slot_id,
                'config_key': config_key,
                'timezone': module.getTimezone()
            };
            module.ajax('reserveSlot', payload).then(function (response) {
                module.debug('reserveSlot response:', response);
                if (response.success) {
                    module.debug("Slot reserved successfully", response.data);
                    module.applyDataToForm(response.data);

                    // Close the modal
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



        // Pull all appointment slots and update the select2 input
        refreshAppointmentSelector: function(){
            // Assumes that the modal is already visible
            $select = $('#tz_select_appt');
            field = $select.data('field');
            if (!field) {
                module.debug('No field data found for timezone selector');
                return;
            }

            // For reasons I can't figure out, the default options don't seem to work unless I create and destroy it first,
            // so I'll create it here the first time so it behaves the same when used many times in one session
            if (!$select.hasClass('select2-hidden-accessible')) {
                $select.select2();
            }

            // Clear existing values
            // module.debug("Destroying existing select2");
            $select.select2('destroy').empty();

            // Initalize with loading state...
            $select.data('field', field).select2({
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
                $select.empty().select2({
                    width: '100%',
                    dropdownParent: $('#tz_select_appt_modal'), // Required for bootstrap modals
                    data: data,
                    templateSelection: function (data) {
                        // This allows you to add HTML to the select data 'text' attribute without affecting its value in the actual input box
                        // module.debug(data);
                        if (!data.id) {
                            return data.text;
                        }
                        // return $("<div class='tz>" + data.text.replace(/\n/g, '<br/>') + "</div>");
                        return $('<div>').addClass('tz-selection').html(data.text.replace(/\n/g, '<br/>'));
                    },
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

                        // var title = data.title ? '<span><b>' + data.title + '</b></span><br/>' : '';
                        // var $result = $('<div>' + title + '<b>' + data.text + '</b> <span class="small" style="color:#888;"><i>(' + data.diff + ')</i></span></div>');

                        // Convert newlines to <br/>
                        var description = data.text.replace(/\n/g, '<br/>');
                        // module.debug(description);
                        $result = $('<div>' + description + '</div>');
                        return $result;
                    },
                    placeholder: "Select an appointment...",
                    allowClear: true
                }).on('change', function() {
                    // We are going to ignore change event, as all that matters is a 'SAVE' event
                    module.debug('change event triggered');
                    $('span.select2-selection--single', '#tz_select_appt_modal').addClass('select2-selection--multiple').removeClass('select2-selection--single');

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
            $select = $('#tz_select_timezone');

            if (!$select.hasClass('select2-hidden-accessible')) {
                // Initialize the select2
                module.ajax('getTimezones', []).then(function (response) {
                    // data = Object.entries(response.timezones).map(([k,v]) => ({id: v, text: v}));
                    data = response.data;
                    module.debug('getTimezones response: ', data);
                    $select.select2({
                        width: '100%',
                        dropdownParent: $('#tz_select_timezone_modal'),
                        data: data,
                        placeholder: "Select your timezone",
                        allowClear: false
                    }).on('change', function() {
                        // module.debug('change event triggered for timezone!!');
                    }).val(module.getTimezone()).trigger('change');
                }).catch(function (err) {
                    // Handle error
                    module.debug("Error fetching timezones: ", err);
                    module.notify("Error Fetching Timezone", err);
                });
            } else {
                // Show or hide the use default timezone button
                // if (module.cookieExists(cookie_name)) {
                //     $('#tz_select_clear_timezone_button').show();
                // } else {
                //     $('#tz_select_clear_timezone_button').hide();
                // }
                $select = $('#tz_select_timezone').val(module.getTimezone()).trigger('change');
            }
        },


        // Update rendering of the appointment container based on slot value
        updateContainer: function(field) {
            let $input = module.data.config[field].$input;
            let slot_id = $input.val();
            let $container = module.data.config[field].$container;

            module.debug('Update Container called for field:', field, 'slot_id:', slot_id, 'container:', $container);
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
                                module.notify("ERROR", "This record's current " + field + " value of " + slot_id + "\ndoes not match the saved value of " + response.data.id + ".\n\nThis shouldn't happen!\nPlease notify an administrator.");
                                return false;
                            }
                            // $input.data('slot_text', response.data.text);
                            $input.data('slot_text', response.data.text); // was description ABM
                            $input.data('slot_timezone', response.data.timezone);
                            // $input.data('add_to_calendar_config', response.data.add_to_calendar_config);
                            $input.data('add_to_calendar_config', response.data.add_to_calendar_config);

                            module.debug('Config:', response.data.add_to_calendar_config);

                            // var d = '<add-to-calendar-button';
                            // $.each(response.data.config, function(key, value) {
                            //     d += ' ' + key + '="' + value + '"';
                            // });
                            // d += '></add-to-calendar-button>';

                            // $container = module.data.config[field]['$container'];
                            // var $addContainer = $('div.add-container', $container);
                            // module.debug("Add Container Element: ", $addContainer);
                            // $addContainer.empty().html(d);
                            // Need to re-init the atcb button
                            // setTimeout(function() {
                            //     console.log("Timeout"); atcb_init();
                            // }, 1000);
                            // If you have a custom init function or action, use that instead:

                            // try {
                            //     module.init_atcb();
                            // } catch (e) {
                            //     module.debug("Error initializing add-to-calendar button:", e);
                            // }
                            // $('.add-to-calendar-span', $container).html(response.data.add_to_calendar_html);
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
                    $('.appt-text', $container).html(slot_text.replace(/\n/g, '<br/>'));
                }
                $('.display-value', $container).show();
                module.init_atcb();
                $('.select-value', $container).hide();
            } else {
                mode = 'select';
                $('.display-value', $container).hide();
                $('.select-value', $container).show();
            }
        },




        // Timezone Functions
        getTimezone: function() {
            var tz = module.getCookie(cookie_name);
            if (!tz) {
                tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            }
            return tz;
        },

        setTimezone: function(tz) {
            module.setCookie(cookie_name, tz, 30); // Save for 30 days
            module.debug("Timezone set to:", tz);
        },

        clearTimezone: function() {
            module.debug("Clearing timezone cookie");
            module.clearCookie(cookie_name);
            module.debug("Cookie exists?", module.cookieExists(cookie_name));
        },

        // Cookie Functions
        getCookie: function(name) {
            let nameEQ = name + "=";
            let ca = document.cookie.split(';');
            for(let i=0; i < ca.length; i++) {
                let c = ca[i];
                while(c.charAt(0) === ' ') c = c.substring(1, c.length);
                if(c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
            }
            return null;
        },

        cookieExists: function(name) {
            return document.cookie.split(';').some((c) => c.trim().startsWith(name + '='));
        },

        setCookie: function(name, value, days = 1) {
            let d = new Date();
            d.setTime(d.getTime() + (days*24*60*60*1000));
            let expires = "expires="+ d.toUTCString();
            document.cookie = `${name}=${value};${expires};path=/;SameSite=Strict;`
        },

        clearCookie: function(name) {
            document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;`;
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
                modal.hide(); //.dispose();
                $modalCopy.remove();
            });

            $('button[data-action="cancel"]', $modalCopy)
            .show()
            .on('click', function() {
                modal.hide(); //.dispose();
                $modalCopy.remove();
            });
            $('button[data-action="ok"]', $modalCopy).hide();
            modal.show();
        }
    });
}
