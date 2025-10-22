// This file extends the default JSMO object with methods for this EM
;{
    // Define the jsmo in IIFE so we can reference object in our new function methods
    const module = ExternalModules.Stanford.TimezoneScheduler;

    var table = null;  // DataTable object

    // Extend the official JSMO with new methods for this EM
    Object.assign(module, {

        // Debug wrapper for easy toggle
        debug: function(...args) {
            if (module.debugger) {
                const caller = this.debug.caller.name ? this.debug.caller.name : 'unknown';
                console.log("[TimezoneScheduler => " + caller + "]\n", ...args);
            }
        },


        loadTables: function() {
            module.getAppointmentsSummary();
            module.getSlotsSummary();
        },

        // This method draws the DataTable showing appointment summary
        getAppointmentsSummary: function() {
            module.debug("Getting appointments summary");
            module.ajax('getAppointmentVerificationData', []).then(function (response) {
                module.debug('getAppointmentVerificationData response: ', response);

                if (response.success) {
                    /* Example response:
                    {
                        "config_key": "appt_slot_1-88",
                        "appt_record": "3",
                        "appt_event_name": "event_1_arm_1",
                        "appt_instance": 1,
                        "appt_instrument": "appiontment_form_1",
                        "appt_url": "https://redcap.local/redcap_v15.3.3/DataEntry/index.php?pid=38&id=3&page=appiontment_form_1&event_id=88&instance=1",
                        "appt_slot_id": "25",
                        "slot_db_url": "https://redcap.local/redcap_v15.3.3/DataEntry/index.php?pid=40&id=25&page=slots",
                        "slot_dt": "2024-10-15 14:00",
                        "status": "ERROR",
                        "errors": "Appointment record 3 does not match Slot DB record 4",
                        "actions": "Reset Appt and Slot"
                    }
                    */
                    // module.debug(response.data);

                    // 1. Get the DataTable instance
                    var table = $('#Appointments').DataTable({
                        data: response.data,
                        scrollX: true,
                        responsive: true,
                        columns: [
                            { data: 'status', title: '' },
                            { data: 'config_key', title: 'Config' },
                            { data: 'appt_record', title: 'Record' },
                            { data: 'appt_instance', title: 'Instance' },
                            { data: 'appt_slot_id', title: 'Slot#' },
                            { data: 'slot_dt', title: 'Slot Date/Time' },
                            { data: 'errors', title: 'Errors' },
                            { data: 'actions', title: 'Actions' }
                        ],
                        // destroy: true,  // Allow re-initialization
                        columnDefs: [
                            { targets: 0, width: "30px", render: function(data, type, row) {
                                if (type === 'display') {
                                    return data === "OK" ?
                                        '<span class="text-success">✔️</span>' :
                                        '<span class="text-danger">❌</span>';
                                } else {
                                    return data;
                                }
                            }},   // Status
                            { targets: 1, width: "90px" },  // Config
                            { targets: 2, width: "30px", render: function(data, type, row) {
                                if (type === 'display') {
                                    return '<div class="btn-group" role="group">' +
                                        '<button title="Open Record ' + data + '" type="button" data-url="' + row.appt_url + '" class="btn btn-xs btn-outline-primary">' + data + '<i class="fas ml-1 fa-external-link-alt"></i></button>' +
                                        '</div>';
                                } else {
                                    return data;
                                }
                            }},   // Record
                            { targets: 3, width: "30px" },  // Instance
                            { targets: 4, width: "30px" , render: function(data, type, row) {
                                if (type === 'display') {
                                    return '<div class="btn-group" role="group">' +
                                        '<button title="Open Slot DB ' + row.appt_slot_id + '" type="button" data-url="' + row.slot_db_url + '" class="btn btn-xs btn-outline-success">' + data + '<i class="fas ml-1 fa-external-link-alt"></i></button>' +
                                        '</div>';
                                } else {
                                    return data;
                                }
                            }},   // Slot#
                            { targets: 5, width: "120px"},   // Slot Date Time
                            { targets: 6, width: "180px", render: function(data, type, row) {
                                if (type === 'display') {
                                    data = data ? '<ul class="pl-0 mb-0"><li>' + data.replace(/\|/g, '</li><li>') + '</li></ul>' : data;
                                    return data ? data : '<span class="text-muted">No Errors</span>';
                                } else {
                                    return data;
                                }
                            }},  // Errors
                            { targets: 7, width: "220px", render: function(data, type, row) {
                                if (!Array.isArray(data)) return '';
                                result = Object.entries(data).map(([key, btn]) =>
                                    '<button title="' + btn.label.trim() + '" type="button" ' +
                                    'data-action-index="' + (key) + '" ' +
                                    'class="btn btn-xs btn-outline-primary">' + btn.label.trim() + '</button>').join(' ');
                                return result;
                            }}   // Actions
                        ]
                    });

                    module.table = table;  // Save reference to table


                    // // Build unique options dynamically from State column
                    // var states = [...new Set(table.column(1).data().toArray())];
                    // $.each(states, function(i, state) {
                    //     $('#configFilter').append($('<option>', { value: state, text: state }));
                    // });

                    // // Initialize multiselect dropdown


                    // // Initialize Select2
                    // $('#configFilter').select2({
                    //     placeholder: 'Filter Configurations',
                    //     allowClear: true,
                    //     width: 'resolve',
                    //     closeOnSelect: false
                    // });

                    // // Filter logic on change
                    // $('#configFilter').on('change', function() {
                    //     var val = $(this).val(); // array of selected values
                    //     if (val && val.length > 0) {
                    //     // Build regex string like "^(TX|OH)$"
                    //     var regex = '^(' + val.join('|') + ')$';
                    //     table.column(1).search(regex, true, false).draw();
                    //     } else {
                    //     table.column(1).search('', true, false).draw();
                    //     }
                    // });

                    table.draw();
                } else {
                    module.debug("Failed to obtain Appointment Verification Data:", response);
                    // module.notify("Error", "Failed to obtain Appointment Verification Data:\n\n" + response.message);
                    return;
                }
            }).catch(function (err) {
                // Handle error
                module.debug("Error getting Appointment Verification Data: ", err);
                // module.notify("Error Fetching Appointment Verification Data", err);
            });

        },

        // This method draws the DataTable showing slots summary
        getSlotsSummary: function(config_key) {
            module.debug("Getting slots summary", config_key);
            var payload = {
                'config_key': config_key
            };
            module.ajax('getSlotsVerificationData', payload).then(function (response) {
                module.debug('getSlotsVerificationData response: ', response);

                if (response.success) {
                    // The result is an object, when I want an array of objects
                    // So, I'll convert it here
                    var slotsArray = Object.values(response.data);

                    module.debug('Slots Array:', slotsArray);

                    // 1. Get the DataTable instance
                    var table = $('#Slots').DataTable({
                        data: slotsArray,
                        scrollX: true,
                        responsive: true,
                        columns: [
                            { data: 'slot_id', title: 'Slot ID' },
                            { data: 'title', title: 'Title' },
                            { data: 'date', title: 'Date' },
                            { data: 'time', title: 'Time' },
                            { data: 'status', title: 'Status' },
                            { data: 'source_record_id', title: 'Appt' },
                            { data: 'errors', title: 'Errors' },
                            { data: 'actions', title: 'Actions' }
                        ],
                        destroy: true,  // Allow re-initialization
                        columnDefs: [
                            {
                                // Slot ID
                                // TODO - make this a button that goes to the slot record
                                targets: 0, width: "60px", render: function(data, type, row) {
                                if (type === 'display') {
                                    title = 'Slot ID ' + data + ' from Slot DB pid ' + row.slot_project_id;
                                    info = title + '\n\n' + 'Availble in:\n - ' + row.config_keys.join('\n - ');

                                    return '<div class="btn-group" role="group">' +
                                        '<button title="' + title + '" type="button" data-url="' + row.slot_url + '" class="btn btn-xs btn-outline-success">' + data + '<i class="fas ml-1 fa-external-link-alt"></i></button>' +
                                        '</div> <i class="fa-solid text-warning fa-circle-info" title="' + info + '"></i>';
                                } else {
                                    return data;
                                }
                            }},   // slot_id
                            {
                                // Title
                                targets: 1, width: "150px"
                            },
                            {
                                // Date
                                targets: 2, width: "90px"
                            },
                            {
                                // Time
                                targets: 3, width: "50px"
                            },
                            {
                                // Status
                                targets: 4, width: "90px", render: function(data, type, row) {
                                    if (type === 'display') {
                                        var filter = (row.project_filter ? 'Project Filter: ' + row.project_filter + '\n' : '') +
                                            (row.slot_filter ? 'Slot Filter: ' + row.slot_filter : '');
                                        if (filter) {
                                            return data + ' <i title="' + filter + '" class="fas fa-filter text-secondary"></i>';
                                        } else {
                                            return data;
                                        }
                                    } else {
                                        return data;
                                    }
                                }
                            },  // status
                            {
                                // Appt Link
                                targets: 5, width: "30px", render: function(data, type, row) {
                                if (type === 'display' && row.source_record_url) {
                                    return '<div class="btn-group" role="group">' +
                                        '<button title="Open Record ' + data + '" type="button" data-url="' + row.source_record_url + '" class="btn btn-xs btn-outline-primary">' + data + '<i class="fas ml-1 fa-external-link-alt"></i></button>' +
                                        '</div>';
                                } else {
                                    return data;
                                }
                            }},   // appt_link
                            { targets: 6, width: "180px", render: function(data, type, row) {
                                if (type === 'display') {
                                    data = data ? '<ul class="pl-0 mb-0"><li>' + data.replace(/\|/g, '</li><li>') + '</li></ul>' : data;
                                    return data ? data : '<span class="text-muted">No Errors</span>';
                                } else {
                                    return data;
                                }
                            }},  // Errors
                            { targets: 7, width: "220px", render: function(data, type, row) {
                                if (!Array.isArray(data)) return '';
                                result = Object.entries(data).map(([key, btn]) =>
                                    '<button title="' + btn.label.trim() + '" type="button" ' +
                                    'data-action-index="' + (key) + '" ' +
                                    'class="btn btn-xs btn-outline-primary">' + btn.label.trim() + '</button>').join(' ');
                                return result;
                            }}   // Actions
                        ]
                    });

                    module.slots_table = table;  // Save reference to table
                    table.draw();
                } else {
                    module.debug("Failed to obtain Slots Verification Data:", response);
                    module.notify("Error", "Failed to obtain Slots Verification Data:\n\n" + response.message);
                    return;
                }
            }).catch(function (err) {
                // Handle error
                module.debug("Error getting Slots Verification Data: ", err);
                module.notify("Error Fetching Slots Verification Data", err);
            });
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




    // Handler to adjust DataTable columns when tab is shown
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (event) {
        const newTab = event.target;         // newly shown tab
        const prevTab = event.relatedTarget; // previous tab
        // module.debug('New tab shown: ', newTab);
        // module.debug('Previous tab: ', prevTab);

        // Save active tab to localStorage
        localStorage.setItem('tz_schedule_admin_active_tab', newTab.id);

        // module.table.columns.adjust();
        $($.fn.dataTable.tables(true)).DataTable()
            .columns.adjust();
    });

    // On document ready, restore last active tab from localStorage
    $(document).ready(function() {
        var activeTab = localStorage.getItem('tz_schedule_admin_active_tab');
        if (activeTab) {
            module.debug("Restoring active tab to: " + activeTab);
            var tabEl = $('#' + activeTab); // document.querySelector(`a[href="${activeTab}"]`);
            if (tabEl) new bootstrap.Tab(tabEl).show();
        }
    });



    // Add handler for appointment table buttons
    $('table.table').on('click', 'button', function() {
        module.debug('Table Button Click', $(this), $(this).data());

        // Get the DataTable instance
        var table = $(this).closest('table').DataTable();

        // Find the row that contains the clicked button
        var row = table.row($(this).closest('tr'));
        var rowData = row.data();

        // Handle Actions
        var actionIndex = $(this).data('action-index') ?? undefined;
        if (actionIndex !== undefined) {
            var action = rowData.actions[actionIndex];
            var method = action.action ?? null;
            var params = action.params ?? [];
            module.debug('Action:', action, 'Method:', method, 'Params:', params);

            module.ajax(method, params).then(function (response) {
                module.debug(action + ' response: ', response);
                if (response.success) {
                    module.debug("Success in " + method, response.data);
                    // Refresh the appointments summary
                    // module.getAppointmentsSummary();
                    rowData.errors = "Row Modified -- Please Refresh Page";
                    rowData.actions = [];
                    row.data(rowData).draw();
                } else {
                    module.debug("Failed in " + action, response);
                    return;
                }
            }).catch(function (err) {
                // Handle error
                module.debug("Error getting Appointment Verification Data: ", err);
                // module.notify("Error Fetching Appointment Verification Data", err);
            });
        }

        // Handle opening URLs in new tab from buttons
        var url = $(this).data('url') ?? undefined;
        if (url) {
            window.open(url, '_blank');
        }
    });

}
