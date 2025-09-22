# Timezone Scheduler

This module is intended to facilitate scheduling with support for multiple client timezones



## The Slot Project(s)

A REDCap project will create one record per slot.  Each slot will:
- have a date field (server timezone)
- have a time field (start_time)
- title (optional)
- description (optional)
- duration (optional)
- filter - used to limit which slots are available from a given project

### future:
- Minimum hours before event to book:  e.g. 4 means you can't book at 4pm slot at 12:30pm.  Leave to 0/blank to ignore
- Maximum days before event to book:  e.g. only let people book 2 weeks into the future
- Minimum hours before event to cancel: if it is less than 4 hours, you cannot cancel your appt and must call to have it removed (e.g. you will be a no-show).
- email address you can use to notify when appointments are booked or cancelled...



Each slot will also have a number of fields to record who has 'taken' the slot - e.g. reserved it.
- source project id (so many projects can reference a single slot database)
- source record
- source event
- source instance?
- participant timezone
- participant date/time
- notes?  could we add participant context into the slot? maybe a phone number or name, for example?

## Reservation Project
In a project where you want to use this EM, you will have to enable the EM and then configure a number of things:

On the Project Level:
- which slot database are you using?  Should we make it so you can have more than one slot database configured in a single project?  Seems pretty unlikely to be that useful...

- project_id of slot database (assumed to be using a standardized dictionary for field names).

- field(s) - repeating - in project where reservation is stored.  This field could be text and just store json -- or we could have a bunch of fields to store bits of info.   What are the data elements required:
    - summary_field (required) - json format
    - slot_id (record in slot database) - optional
    - server_timestamp for meeting - optional
    - client_timestamp for meeting - optional
    - client_timezone (as specified on reservation) - optional

We will do all config at the em level as action-tags won't be reliable given you have to map a survey project to a slot database...

## UI Elements:

- via survey
  - book appointment
  - view appointment
  - cancel appointment
  - change timezone
- via form
  - view appointment
  - book appointment
  - cancel appointment
  - goto slot record (if you have access)
- admin
  - make sure all appointments configured are 'valid' and current
  - potentially add ability to cancel many appointments
  - potentially add ability to sync to a google caneldar...



## How to display the selected appointment to the end-user?

Perhpas the format should be specified in the EM config:

Slot Description (e.g. New Patient Visit) (#SLOT_ID#)
Scheduled: 07/15/2025 at 4:00PM PST (2:00PM CST)

```
{title} ({slot_id})
Scheduled: {date} at {time} {server-tza} ({client-time} {client-tza})
```
`title` is slot title
`date` is slot date field (yyyy-mm-dd)
`time` is slot time field (hh:mm) in 24 hour time

`server-time` means 'HH:MM am/pm' in server timezone
`server-nicedate` means 'Sat, Jan 1st, 2025'
`server-date` means mm/dd/yyyy
`server-ts` means 'yyyy-mm-dd hh:mm' (timestamp)
`server-tza` means server timezone abbreviation (e.g. PST, EDT)

`client-time` means 'HH:MM am/pm' in client timezone
`client-nicedate` means 'Sat, Jan 1st, 2025'
`client-tza` means client timezone abbreviation (e.g. PST, CST, EDT)
`client-date` means mm/dd/yyyy
`client-ts` means 'yyyy-mm-dd hh:mm' (timestamp)





## Development Notes ##

The javascript container for each appointment gets
- data-field with the fieldname for the slot input


Similarly, given a field_name, you can get the input and the select
module.data.config.["field"].$input
module.data.config.["field"].$container
