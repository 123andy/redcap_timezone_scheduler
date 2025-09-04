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


        // lookupAppointments: function(query, config, callback) {
        //     console.log("Lookup Appointments for config key:", config, " with query:", query);
        //     results = {
        //         "items": [
        //             {
        //                 "id": 1,
        //                 "text": "one"
        //             },
        //             {
        //                 "id": 2,
        //                 "text": "two"
        //             }
        //     ]}

        //     setTimeout(function() { callback(null, results); }, 100); // Simulate async
        //     // return results;
        // },


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
            // /* {
            //         slot-id-field: 'appt_slot',
            //         slot-id-field-event-id: '88',
            //         slot-datetime-field: 'appt_dt',
            //         slot-timezone-field: 'appt_tz',
            //         slot-filter-expression: null
            //     }
            // */

            for (const [config_id, config] of Object.entries(module.data.config)) {

                // Get the name of the field in this form that should be a calendar select widget
                // This field is to be a text field as it will hold the slot id from the reference database
                field = config['slot-id-field'];
                jq_field = $(`input[name="${field}"]`);

                // Get the current value if any
                slot_id_value = jq_field.val();

                // Grab the nearest TD to set up the visualization
                td = jq_field.closest('td.data');

                // Hide the current contents of the slot-id field td
                td.children().wrapAll('<div class="tz_hidden xxhide bg-danger"></div>');

                // Create the new select2 element and wrapper
                var div = $('<div/>')
                .attr('id', 'tz_display_' + field)
                .data('field-name', field)
                .css({'width': '90%'})
                .addClass('tz_display');

                // Create a timezone button that was injected from php (or, I could move it here)
                var tz_btn = $("<button/>").attr('id','tz_selector_button_'+field)
                .addClass('btn-primaryrc btn btn-xs float-right')
                .attr('data-toggle','modal')
                .attr('data-target','#tz_select_modal')
                .text('Edit Timezone');

                // var tz_btn = $('#tz_selector_button').clone().removeClass('hide').attr('id', 'tz_selector_button_' + field);

                // Add select 2
                var sel2 = $('<select/>').attr('id', 'tz_select_' + field);

                div.append(tz_btn);
                div.append(sel2);
                td.prepend(div);

                // td.prepend(
                //     '<div id="tz_display_' + field + '" ' +
                //     'data-field-name="' + field + '" ' +
                //     'class="tz_display">' +
                //         // '<div class="btn-primaryrc btn btn-xs tz_timezone">' +
                //         //     'Edit Timezone <i class="fa-solid fa-pencil"></i>' +
                //         // '</div>' +
                //         '<select id="tz_select_' + field + '"></select>' +
                //     '</div>'
                // );

                // Initialize it as a select2
                sel2
                .data({
                    config_id: config_id,
                    field: config['slot-id-field'],
                    event_id: config['slot-id-field-event-id'],
                    datetime_field: config['slot-datetime-field'],
                    timezone_field: config['slot-timezone-field'],
                    filter: config['slot-filter-expression']
                })
                .select2({
                    width: '100%',
                    // ajax: {
                    //     config: config,
                    //     transport: function(params, success, failure) {
                    //         console.log("Transport: params:", params);
                    //         // Custom internal method call
                    //         // module.lookupAppointments(params.config, function(err, results) {
                    //         module.lookupAppointments(params.data.q, params.config, function(err, results) {
                    //             if (err) {
                    //                 failure(err);
                    //                 return;
                    //             }
                    //             console.log("transport results: ", results, config);
                    //             success(results);
                    //         });
                    //     },
                    //     processResults: function(data) {
                    //         // Format as Select2 expects [{id, text}, ...]
                    //         console.log("Process Results: data:", data);
                    //         return {
                    //             results: data.items  // Array of {id, text}
                    //         };
                    //     },
                    //     cache: false
                    // },
                    templateResult: function (data) {
                        if (!data.id) {
                            return data.text;
                        }
                        var $result = $('<span>' + data.text + '</span>');
                        return $result;
                    },
                    placeholder: "Select a time slot",
                    // minimumInputLength: 1   //optional
                });

                // console.log("jqF:",jq_field, slot_id_);

                // field_event_id = config['slot-id-field-event-id'];
                // field_datetime = config['slot-datetime-field'];
                // field_timezone = config['slot-timezone-field'];
                // field_filter = config['slot-filter-expression'];


                // console.log("data_container:",data_container);
            }


            // INITIALIZE TIMEZONE SELECTOR
            $('#tz_select_modal').on('show.bs.modal', function () {
                // if ($('#tz_select').data('select2')) {
                //     // already initialized
                // } else {
                    // module.ajax('getTimezones', []).then(function (response) {
                    //     data = Object.entries(response.timezones).map(([k,v]) => ({id: v, text: v}));

                    //     data = [ { id: '', text: 'Select your timezone' } ];

                    //     $('#tz_select').select2({
                    //         width: '100%',
                    //         dropdownParent: $('#tz_select_modal'),
                    //         data: data,
                    //         templateResult: function (data) {
                    //             if (!data.id) {
                    //                 return data.text;
                    //             }
                    //             var $result = $('<span>' + data.text + '</span>');
                    //             return $result;
                    //         },
                    //         placeholder: "Select your timezone"
                    //     }).val(module.getTimezone()).trigger('change');

                    // }).catch(function (err) {
                    //     // Handle error
                    //     console.log("Error fetching timezones: ", err);
                    //     alert(err);
                    // });
                    // }

                // // Make sure reflects current timezone
                // var t = module.getTimezone();
                // console.log("Setting timezone to: ", t);
                // $('#tz_select').val(module.getTimezone()).trigger('change');
            });

            // BIND TIMEZONE SELECTOR SAVE EVENT
            // $('#tz_select_save_button').on('click', function() {
            //     var selectedTimezone = $('#tz_select').val();
            //     module.setTimezone(selectedTimezone);
            //     $('#tz_select_modal').modal('hide');

            //     // TODO: REFRESH EXISTING SELECT 2S WITH TIMEZONE
            // });

        }

    });
}
