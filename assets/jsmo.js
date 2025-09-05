// This file extends the default JSMO object with methods for this EM
;{
    // Define the jsmo in IIFE so we can reference object in our new function methods
    const module = ExternalModules.Stanford.TimezoneScheduler;
    console.log(module);

    // A global place to store links to the timezone select2 instances
    var timezoneSelect2s = [];

    // Extend the official JSMO with new methods for this EM
    Object.assign(module, {

        ExampleFunction: function() {
            console.log("Example Function showing module's data:", module.data);
            module.InitializeInstrument();
        },

        // Ajax function calling 'TestAction'
        InitFunction: function () {
            console.log("Example Init Function");

            // Note use of jsmo to call methods
            module.ajax('TestAction', module.data).then(function (response) {
                // Process response
                console.log("Ajax Result: ", response);
            }).catch(function (err) {
                // Handle error
                console.log(err);
            });
        },

        InitializeField: function() {
        },


        lookupAppointments: function(query, config, callback) {
            console.log("Lookup Appointments for config key:", config, " with query:", query);
            results = {
                "items": [
                    {
                        "id": 1,
                        "text": "one"
                    },
                    {
                        "id": 2,
                        "text": "two"
                    }
            ]}

            setTimeout(function() { callback(null, results); }, 100); // Simulate async
            // return results;
        },


        getTimezone: function() {
            // First, see if the timezone cookie exists
            var cookieName = 'tz_scheduler_timezone';
            var tz = null;
            var arr = document.cookie.split('; ');
            for (var i = 0; i < arr.length; i++) {
                var cookie = arr[i];
                if (cookie.substring(0, cookieName.length) == cookieName) {
                    tz = cookie.substring(cookieName.length + 1);
                    console.log('Found timezone cookie:' + cookieName, cookie,tz);
                    break;
                }
            }

            if (!tz) {
                tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                console.log('No timezone cookie (' + cookieName + 'found, using default:', tz);
            }

            return tz;
        },

        setTimezone: function(tz) {
            var cookieName = 'tz_scheduler_timezone';
            document.cookie = cookieName + '=' + tz + '; path=/;SameSite=Strict';
            // TODO - refresh select2 so entries match selected timezone
        },

        // Call Ajax to pull list of all valid timezones
        getTimezones: function() {

        },



        InitializeInstrument: function() {
            // loop through module.data.config
            //  {slot-id-field: 'appt_slot', slot-id-field-event-id: '88', slot-datetime-field: 'appt_dt', slot-timezone-field: 'appt_tz', slot-filter-expression: null
            for (const [config_id, config] of Object.entries(module.data.config)) {

                // Get the name of the field in this form that should be a calendar select widget
                field = config['slot-id-field'];
                jq_field = $(`input[name="${field}"]`);

                // Get the current value
                slot_id = jq_field.val();

                // Format the rendering
                td = jq_field.closest('td.data');

                // Hide the current contents of the slot-id field
                td.children().wrapAll('<div class="tz_hidden xxhide bg-danger"></div>');

                // Add a wrapper along with a new select element
                var div = $('<div/>')
                .attr('id', 'tz_display_' + field)
                .data('field-name', field)
                .css({'width': '90%'})
                .addClass('tz_display');

                // Clone timezone button
                var tz_btn = $('#tz_selector_button').clone().removeClass('hide').attr('id', 'tz_selector_button_' + field);

                // Add Select2
                var sel2 = $('<select/>').attr('id', 'tz_select_' + field);

                // Add an empty option so that the placeholder is default when empty
                $('<option/>').appendTo(sel2);

                // Assemble dom
                div.append(tz_btn);
                div.append(sel2);
                td.prepend(div);

                // Get appointment data:
                $payload = {
                    "config_id": config_id,
                    "current_value": slot_id,
                    "timezone": module.getTimezone()
                };
                console.log("Payload for getAppointments:", $payload);
                module.ajax('getAppointments', $payload).then(function (response) {
                    console.log('getAppointments response:', response);
                    // If the response is an object, not an array, you can convert it to
                    // an array using the object map like below:
                    // data = Object.entries(response.data).map(
                    //     ([k,v]) => ({id: v.id, text: v.text })
                    // );
                    data = response.data;
                    // console.log(data);
                    sel2.data({
                        target_field: jq_field,
                        config_id: config_id,
                        field: config['slot-id-field'],
                        event_id: config['slot-id-field-event-id'],
                        datetime_field: config['slot-datetime-field'],
                        timezone_field: config['slot-timezone-field'],
                        filter: config['slot-filter-expression']
                    }).select2({
                        width: '100%',
                        data: data,
                        templateResult: function (data) {
                            // This allows you to add HTML to the select data 'text' attribute without affecting its value in the actual input box
                            // console.log(data);
                            if (!data.id) {
                                return data.text;
                            }
                            var $result = $('<span><b>' + data.text + '</b></span>');
                            return $result;
                        },
                        placeholder: "Select an appointment",
                        allowClear: true
                    }).on('change', function() {
                        // Handle change event
                        var selectedAppointment = $(this).val();
                        console.log("Selected appointment:", selectedAppointment);
                        console.log("Target field:", sel2.data('target_field'));
                        console.log("Field is:", sel2.data('field'));
                        console.log("Target field:", $(this).data('target_field'));
                        console.log("Field is:", $(this).data('field'));
                        $(this).data('target_field').val(selectedAppointment).trigger('change');

                    });
                    //.val(module.getTimezone()).trigger('change');
                }).catch(function (err) {
                    // Handle error
                    console.log("Error fetching appointments: ", err);
                    alert(err);
                });




                console.log("jqF:",jq_field, slot_id);

                field_event_id = config['slot-id-field-event-id'];
                field_datetime = config['slot-datetime-field'];
                field_timezone = config['slot-timezone-field'];
                field_filter = config['slot-filter-expression'];


                // console.log("data_container:",data_container);
            }


            // INITIALIZE TIMEZONE SELECTOR
            $('#tz_select_modal').on('show.bs.modal', function () {
                if ($('#tz_select').data('select2')) {
                    // already initialized - make sure it is on the correct value
                    $('#tz_select').val(module.getTimezone()).trigger('change');
                } else {
                    module.ajax('getTimezones', []).then(function (response) {
                        data = Object.entries(response.timezones).map(([k,v]) => ({id: v, text: v}));

                        $('#tz_select').select2({
                            width: '100%',
                            dropdownParent: $('#tz_select_modal'),
                            data: data,
                            // templateResult: function (data) {
                            //     if (!data.id) {
                            //         return data.text;
                            //     }
                            //     var $result = $('<span>' + data.text + '</span>');
                            //     return $result;
                            // },
                            placeholder: "Select your timezone"
                        }).val(module.getTimezone()).trigger('change');

                    }).catch(function (err) {
                        // Handle error
                        console.log("Error fetching timezones: ", err);
                        alert(err);
                    });
                }
            });

            // BIND TIMEZONE SELECTOR SAVE EVENT
            $('#tz_select_save_button').on('click', function() {
                var selectedTimezone = $('#tz_select').val();
                module.setTimezone(selectedTimezone);
                $('#tz_select_modal').modal('hide');

                // TODO: REFRESH EXISTING SELECT 2S WITH TIMEZONE
            });

        }










    });
}
