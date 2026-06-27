# agent.md — Timezone Scheduler

> Agent orientation for this REDCap External Module. This folder is its **own git repo** (namespace `Stanford\TimezoneScheduler`), developed inside the redcap-docker-compose `www/modules/` tree. Commit changes here, not in the parent repo. The EM framework it extends lives in `../../redcap_v<x.y.z>/ExternalModules/` — see `docs/hooks.md`, `docs/methods/`, and `docs/config.md` there when in doubt.

## What it does

Lets REDCap participants book an appointment from a pool of available time slots, **viewing every slot in their own (browser-detected or manually selected) timezone**. The goal is multi-timezone studies where staff and participants are in different zones.

The core trick: appointments are not stored in the study project. They live in a **separate "Slot Database" REDCap project** (one record per slot). The study project's appointment field stores only the chosen `slot_id`. Reserving a slot writes back-references into the slot record, so the two projects stay linked. One Slot DB can be shared across many study projects.

## The two-project model (read this first)

| | Slot Database project | Appointment (study) project |
|---|---|---|
| Role | Inventory of bookable slots, 1 record = 1 slot | Where the EM is enabled & configured; participants book here |
| EM enabled? | **No** — it's just plain data | **Yes** |
| Created from | `docs/TimezoneSchedulerSlotDbTemplate.REDCap.xml` (do not rename the `slots` form/fields) | any project; configure via EM config page |
| Key fields | `slot_id`, `title`, `date`, `time`, `project_filter`, `slot_filter`, + `reserved_ts` and `source_*` back-references filled on reservation | a text `appt-field` holding the `slot_id`, plus optional datetime/description/cancel-url/etc. fields |

A **reservation** is a two-sided write: the slot record gets `reserved_ts` + `source_project_id/record/event/instance/field/url` + `participant_timezone/description`; the study record gets `slot_id` + optional derived fields. Both sides must agree — most of the admin tooling exists to detect and repair cases where they don't ("orphans").

## Code map

- `TimezoneScheduler.php` — the entire module class (~2200 lines). All hooks, all business logic, all AJAX actions.
- `config.json` — the EM manifest: settings schema, AJAX action allow-lists, page links, namespace, `framework-version: 16`.
- `classes/TimezoneException.php` — custom exception; thrown for user-facing errors, caught in the AJAX dispatcher and returned as `{success:false, message}`.
- `emLoggerTrait.php` — `emDebug()/emInfo()/emError()` logging (no-ops unless the **emLogger** EM is installed and debug logging enabled in config).
- `pages/admin.php` — project sidebar link "Timezone Scheduler Admin"; three tabs. **Overview** is an admin dashboard: module overview, a configuration summary (`getConfigSummary()` — fields/events/slot DBs, enabled vs disabled), per-slot-DB usage stats (`getSlotDbStats()` → pure `summarizeSlots()` — total / available-upcoming / booked / cancelled / past + utilization %), and the copyable iCal subscription URL (`getIcalFeedUrl()`). **Appointments** & **Slots Database** show verification tables with repair action buttons; the Slots tab also renders a bootstrap-datepicker **calendar view** with a per-day `booked/total` badge (`renderSlotsCalendar()` in `admin_jsmo.js`, fed from the same `getSlotsVerificationData` AJAX). Visible to users with Design rights. NB: badges use a `div` (not `span`) to avoid bootstrap-datepicker's built-in `.datepicker td span` styling.
- `pages/cancel.php` — NOAUTH page reached via the encrypted cancel URL piped into a notification; lets a participant cancel their own appointment.
- `pages/calendar.php` — NOAUTH iCal feed endpoint (token-gated via `ical_feed_hash` project setting); also renders the subscribe-URL instructions page. Subscribing returns a `text/calendar` feed of booked appointments, each VEVENT linking back to its REDCap record. Implemented via `serveICalFeed()` → `getIcalFeed()` (collects booked slots) → `buildVCalendar()` (pure Sabre\VObject builder; times emitted in UTC).
- `assets/jsmo.js` — front-end JSMO (extends `ExternalModules.Stanford.TimezoneScheduler`); drives the form UI (select-appt modal, timezone modal, datepicker filter).
- `assets/admin_jsmo.js` — front-end for the admin verification tables.
- `assets/bootstrap-datepicker.*` — vendored calendar widget. `assets/add-to-calendar-button.js` — vendored "add to calendar" lib (currently disabled; `slotToCalendarConfig()` is stubbed).

## How it wires together

**Hooks** (`redcap_*` methods in `TimezoneScheduler.php`):
- `redcap_data_entry_form` / `redcap_survey_page` → call `filter_tz_config()` to find configs matching this instrument+event, then `injectJSMO()` (loads `jsmo.js`, datepicker, and bootstraps `initializeInstrument`) and `injectHTML()` (modals + CSS templates).
- `redcap_module_ajax($action, $payload, …)` → the single dispatcher for all front-end calls (big `switch`). Wraps everything in try/catch; `TimezoneException` → friendly message, other `Exception` → generic message + lock release.

**AJAX actions** (must be listed in `config.json` under `auth-ajax-actions` / `no-auth-ajax-actions` to be callable; survey/no-auth context uses the no-auth list): `getTimezones`, `getAppointmentOptions`, `getAppointmentData`, `reserveSlot`, `cancelAppointment`, `cancelAppointmentFromUrl`, `getSlot`, `addToCalendar` (participant-facing); `getAppointmentVerificationData`, `getSlotsVerificationData`, `resetAppointment`, `resetSlot`, `cancelSlot`, `resetSlotAndAppointment` (admin-facing).

**Config keys**: `load_tz_configs()` reads the repeatable `instances` sub-setting and indexes each by a composite key `"<appt-field>-<event_id>"`. This `config_key` is the universal handle passed through nearly every method and AJAX payload. The same appointment field used on multiple events needs one config instance per event. `get_tz_config($key)` / `filter_tz_config($instrument,$event_id)` are the accessors.

**Concurrency**: `reserveSlot()` takes a MySQL named lock (`GET_LOCK`/`RELEASE_LOCK` via `getLock()`/`releaseLock()`, keyed `tzs_slot_<slot>_proj_<pid>`) so two participants can't grab the same slot. `$this->lock_name` is stashed so the AJAX catch block can release it on error. If you add code paths that reserve, preserve this lock discipline.

**Timezone rendering**: `getAppointmentOptions()` converts each slot's server `date`+`time` into the client timezone and builds the human description by token-substitution. Tokens (`{client-time}`, `{server-tza}`, `{diff}`, …) and the `<== … ==>` "hide if timezones match" markers are documented in `README.md` ("Customizing the Appointment Description") and mirrored in the `config.json` help text — keep those three in sync if you change tokens. Datetime written back to the study record is reformatted to match that field's REDCap validation type via `VALIDATION_SERVER_CONVERSION_INDEX` / `VALIDATION_CLIENT_CONVERSION_INDEX`.

**Slot filtering**: `slot-filter-field` on the record is matched against the slot's `slot_filter` value (`getSlotFilterValue()` → `getSlots($key, true, $value)`) so one Slot DB can serve multiple appointment types (e.g. randomization arms). The filter value must already be saved on the record before the selector renders.

## Conventions & gotchas

- All REDCap reads/writes go through `REDCap::getData` / `REDCap::saveData` with `overwriteBehavior: overwrite`; saves are checked for `$result['errors']` (REDCap sometimes returns a string error instead of an array — existing code guards `!isset($q['errors']) || !empty($q['errors'])`).
- Always `$this->escape()` data crossing into the page/JSMO (XSS); existing hooks do this.
- Repeating-form and longitudinal handling is explicit — `getRecord()`/`getRecords()` resolve `redcap_repeat_instrument`/`redcap_repeat_instance` and `redcap_event_name` manually; follow the existing pattern rather than assuming flat records.
- Source-record URLs are built with a hard-coded `redcap_v<REDCAP_VERSION>/DataEntry/...` path — flagged with `// TODO` to replace with an EM redirect; expect breakage across REDCap upgrades.
- The iCal subscription feed (`serveICalFeed`/`getIcalFeed`/`buildVCalendar`) is implemented. The separate per-appointment **"add to calendar" button** (`slotToCalendarConfig()` + `assets/add-to-calendar-button.js`) is still **stubbed/disabled**.
- emLogger is optional; debug calls are safe no-ops without it. Enable per-project or system-wide via the config checkboxes to get server logs (and browser-console JS logs).

## Testing

### Automated (PHPUnit)
The module uses the External Modules framework's PHPUnit harness. Tests live in `tests/` and extend `\ExternalModules\ModuleBaseTest` (provided by the framework; it bootstraps REDCap, marks the module enabled, and gives in-memory project/system settings). Each test file must `require_once __DIR__ . '/../../../redcap_connect.php'` and use the `Stanford\TimezoneScheduler` namespace; `ModuleBaseTest` derives the module from the test file's path, so files must stay under `<module>/tests/`.

Run them (from the repo root `rdc/` dir):
```
docker compose exec web sh /var/www/html/modules/timezone_scheduler_v0.0.0/run-tests.sh
```
`run-tests.sh` locates the bundled framework's phpunit and runs everything in `tests/`.

**Key constraint:** a bare `ModuleBaseTest` has **no project context (pid)**, so any method that calls `getProjectSetting()`/`getSubSettings()` or `REDCap::getData()` will throw "you must supply ... pid". Two ways to deal with it:
- **Unit-test pure logic** — what `tests/SlotVerificationTest.php` does. The verification logic was split into `buildSlotVerificationResults()` (REDCap fetch layer) and the pure `computeSlotVerification($configs, $appt_refs, $slots_by_config_key, $now)`. The test feeds plain fixture arrays into the pure method — no projects needed — and asserts the cross-slot-DB composite-key behavior (`<slot_project_id>:<slot_id>`). Prefer this: fast and deterministic.
- **Integration-test with real projects** — the heavier path used by e.g. [`Research-IT-Swiss-TPH/redcap-unique-actiontag`](https://github.com/Research-IT-Swiss-TPH/redcap-unique-actiontag): `setUpBeforeClass()` calls `ExternalModules::getTestPIDs()` and builds projects/data with Vanderbilt's `ProjectDesigner`, then asserts against `REDCap::getData`. Needs test PIDs configured in the install. Reach for this only when the behavior genuinely depends on REDCap data access. Framework reference: `redcap_v*/ExternalModules/docs/unit-testing.md`.

### Manual
Test the full UI flow in the docker stack (see repo root `CLAUDE.md` and the module `README.md` "Getting Started"): create a Slot DB from the XML template, import slots from `docs/TimezoneSchedulerImportTemplate.csv` (**always use Force Auto-Numbering** so `slot_id`s stay unique), import the example study project `docs/TimezoneSchedulerExample.REDCap.xml`, enable + configure the EM, then book/cancel/reset appointments and watch the admin verification tabs for errors.

## Future ideas (from README)
Min-hours-before-booking, max-days-ahead, min-hours-before-cancel windows; participant notifications on book/cancel; working add-to-calendar + calendar view; i18n; an easier config UI.
