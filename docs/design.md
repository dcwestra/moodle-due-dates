# local_duedates: design

Status: revision 3, approved 2026-11-24. Incorporates decisions D1-D11. Phase 1 is implemented. Q-numbers refer to [projectWork.md](../projectWork.md).

## 1. Principles
- Due dates are information only. Nothing here restricts access.
- A due date is stored as a calendar day (`duedate`, integer YYYYMMDD) plus a derived instant (`timedue`, 23:59:59 that day in the site timezone, D4). The day is the truth; the instant supports comparison and indexing.
- Candidates are frozen when created. Resolution works on stored candidates, so passing time changes nothing.
- Time-based status is derived, not stored: it can never go stale between cron runs.

Taken from enrol_programs: an allocation row per user, due dates separated from access end dates, calendar events keyed by component and instance, and a log of sent notifications. No code or dependency is taken.

## 2. Data model
All tables prefixed `local_duedates_`. Every table has `id`; mutable ones have `timecreated`, `timemodified`, `usermodified`.

**target**: what is due.
`courseid`, `cmid` (0 = course), `countfail`, `requireenrolment` (D10), `duesoondays`, `enabled`, `managercopy`. Unique (`courseid`,`cmid`).

**rule**: stable identity.
`targetid`, `name`, `sortorder` (evaluation order, 1 = first), `status` (draft/active/disabled/deleted), `activefrom`, `activeto`, `currentversion`.

**rulever**: immutable definition per edit.
`ruleid`, `version`, `datetype` (fixed/relative), `fixeddate`, `offsetn`, `offsetunit`, `anchor` (includes reset date), `anchorfieldid`, `followanchor`, `notbefore`, `notafter`, `extendleave`, `extendpastbound`, `completionfrom` (null = any), `conditions` (JSON tree), `appliesto` (first assignment and/or reset types), `applyscope` (existing/new). Unique (`ruleid`,`version`).

**cycle**: one round of completion for a user and course.
`userid`, `courseid`, `number` (0 = first), `resettypeid`, `reason`, `actorid`, `batchid`, `source` (manual/bulk/scheduled/external), `state` (pending/done/failed), `timereset`. Unique (`userid`,`courseid`,`number`). Cycle 0 is implicit and needs no row.

**resettype**: admin-defined list, e.g. "New compliance year", "Remediation". `name`, `enabled`, `sortorder`.

**leave**: observed or entered suspension period.
`userid`, `timestart` (null = unknown), `timeend` (null = still out), `source` (observed/manual), `excluded`, `note`, `setby`. Index (`userid`,`timeend`).

**assign**: one user, target and cycle.
`targetid`, `userid`, `cycle`, `basedue`, `leavedays`, `duedate`, `timedue`, `winruleid`, `winversion`, `exceptionid`, `lifecycle` (active/completed/removed/superseded), `timecompleted`, `islate`, `timeremoved`, `removedreason`, `calendareventid`. Unique (`targetid`,`userid`,`cycle`); index (`lifecycle`,`timedue`), (`userid`,`lifecycle`).

**cand**: every rule matched, for audit and the conflicts report.
`assignid`, `ruleid`, `version`, `sortorder` (at evaluation), `anchorvalue`, `duedate`, `active`, `timefirstmatched`, `timeunmatched`. Unique (`assignid`,`ruleid`).

**except**: manual exception.
`assignid`, `duedate`, `reason`, `setby`, `timeset`, `timerevoked`, `revokedby`. Rows are never overwritten.

**history**: append-only audit of due date, winner and lifecycle changes.
`assignid`, `oldduedate`, `newduedate`, `oldruleid`, `newruleid`, `cause`, `actorid`, `timecreated`.

**step**: reminder step.
`targetid` (0 = site default), `offsetdays` (negative = before), `repeatdays`, `repeatmax`, `repeatuntil`, `subject`, `body`, `bodyformat`, `sortorder`, `enabled`.

**sendlog**: `assignid`, `stepid`, `iteration`, `duedate`, `recipientid`, `iscopy`, `state` (claimed/sent/failed/skipped), `messageid`, `timesent`. Unique (`assignid`,`stepid`,`iteration`,`recipientid`).

**queue**: `userid`, `targetid` (0 = all), `cause`, `timecreated`. Unique (`userid`,`targetid`), so bulk syncs collapse.

**work**: set-wise staging rows keyed by `runid`. A permanent table, because MySQL cannot reference a temporary table twice in one query.

**daymap**: `ymd`, `daystart`, `dayend` in the site timezone. Lets SQL turn timestamps into days and back, DST included. Rebuilt if the site timezone changes.

## 3. Conditions
A JSON tree of AND/OR groups and leaves, e.g. `{"op":"and","items":[{"type":"profilefield",...}]}`.

Each leaf type is a class implementing `condition_type`: `get_sql(array $config, string $useralias): [sql, params]`, `validate()`, `form_elements()`, `describe()`, `affected_by(): event names`. Discovery uses `core_component::get_component_classes_in_namespace()`, so another plugin can add types.

v1 types: standard profile field, custom profile field, cohort, enrolment (optional before/after), user created.

- The enrolment date is `timestart`, or `timecreated` when that is 0; the earliest active enrolment wins.
- Core casts are unsafe here on PostgreSQL (`sql_cast_char2int` is 32-bit; 4.2's `sql_cast_char2real` is single precision and shifted dates by a day). A guarded 64-bit integer cast is used.
- A date-only `datetime` value is midnight in the timezone of whoever saved it. It is read in the site timezone (D4), which is correct when the syncing account's timezone is "Server timezone" or the site forces a timezone. A health check warns when stored values are not at site midnight.
- A missing anchor gives no candidate, and the preview lists the count.
- Multi-select "in list" is limited to menu and text fields. Core has no multi-select profile type.

## 4. Resolution

### 4.1 Candidate build (set-wise, for one rule version and a user scope)
```
run = new runid
INSERT work(run, userid, anchor)
  SELECT u.id, <anchor expr> FROM user u <anchor joins>
  WHERE <conditions> AND u.deleted = 0 AND u.suspended = 0
    AND <appliesto fits the user's current cycle> AND <scope: all | one user | queued users>
    AND <enrolment check when target.requireenrolment>
fixed:    UPDATE work SET ymd = fixeddate
relative: work JOIN daymap ON anchor BETWEEN daystart AND dayend -> anchor day
          SELECT DISTINCT anchor day        -- bounded by days, not users
          PHP computes day + N units for each distinct day (month ends clamped)
          apply as a join back to work
apply notbefore, notafter, in that order (notafter wins a contradiction; form rejects it)
```
First matched = existing `cand.timefirstmatched`, otherwise now. First access = `course_modules_viewed` for a module when available, otherwise the plugin's record of the earliest observed `course_module_viewed`/`course_viewed`. `user_lastaccess` is only the latest access and cannot be used.

### 4.2 Merge with frozen candidates
```
matched and no cand            -> insert cand, record date
matched, cand inactive         -> reactivate, recompute date, keep timefirstmatched
matched, cand active, same ver -> keep date (unless followanchor and anchorvalue changed)
matched, cand active, old ver  -> applyscope=existing: recompute; new: leave on old version
not matched, cand active       -> active = 0
```
Under `new`, the old version remains live for users already on it, so later membership changes are judged against it. Users with an existing assignment on the target are excluded from the new version (D11). Rules outside their active window add nobody; existing candidates stay after `activeto` (D10). Disabling or deleting a rule deactivates its candidates.

### 4.3 Choose the winner: ordered rules, first match applies (D1)
```
resolve(assignment):
  if live exception:        return exception
  if user on leave:         return unchanged (paused, section 4.6)
  for rule in target's rules by sortorder:
    if rule has an active candidate with a date:  winner; stop
  none: lifecycle = removed (keep history)
  basedue = winner's date; then apply leave extension
```
- Rules are ordered within a target and can be dragged to reorder. There are no modes, priorities or strategies.
- "New assignments only" is decided by `assign.rulevermark`, the newest rule version when the assignment began. Reordering for existing assignments updates positions on all versions; otherwise it creates new versions, so existing assignments keep the whole previous order.
- A matching rule without a usable date (e.g. empty hire date) is skipped, and the preview reports the count.
- Every matching rule is still recorded, so reports show which lower rules were not used.
- In SQL: `ROW_NUMBER() OVER (PARTITION BY assignid ORDER BY sortorder, ruleid)`, row 1 wins.
- Reordering, like editing, opens the preview and asks "existing assignments" or "new only". Under "new only", existing candidates retain their recorded `sortorder`.
- The editor warns when a rule can never apply because earlier rules cover everyone it currently matches.
- A user who stops matching the winner falls through to the next matching rule.

### 4.4 Acceptance scenario (D8)
| Order | Rule | Due |
|---|---|---|
| 1 | Division is DIV1 | 2026-12-18 |
| 2 | Hired on/after 2026-06-01 | hire + 30 days |
| 3 | Enrolled before 2026-06-01 (Q23) | 2026-06-30 |

| User | Order above | DIV1 rule moved last |
|---|---|---|
| Non-DIV1 existing | 2026-06-30 | 2026-06-30 |
| Non-DIV1, hired 2026-08-10 | 2026-09-09 | 2026-09-09 |
| DIV1, hired 2026-11-30 | 2026-12-18 | 2026-12-30 |
| DIV1, hired 2026-11-01 | 2026-12-18 | 2026-12-01 |
| DIV1 existing | 2026-12-18, not overdue | 2026-06-30, overdue |
| Completed on time / late | completed / completed late | same |

The preview flags every date moved later and every overdue user who becomes not overdue. Both orders are tested.

### 4.5 Status and completion
- Stored lifecycle `completed` gives **completed**, or **completed late** when `islate`.
- Otherwise, from `timedue`: **overdue** (passed), **due soon** (within `duesoondays`), else **not due yet**.
- Late means `timecompleted > timedue`, locked when completed (D10).
- Module: states COMPLETE and COMPLETE_PASS, plus COMPLETE_FAIL and COMPLETE_FAIL_HIDDEN if `countfail`. Time is the first qualifying time observed; the backstop falls back to `timemodified`, which is the latest state change.
- Course: `course_completions.timecompleted`.
- In cycle 1 and later, only completions after that cycle's reset count.
- Completions before the winning rule version's `completionfrom` do not count. With an exception, the winning candidate's window is used.
- If completion is undone other than by a reset, the assignment reopens.

### 4.6 Leave (D3)
Optional: off by default, enabled site-wide, then per rule (`extendleave`). Core stores no suspension history, so the plugin records periods itself: `user_updated` compares `user.suspended` with the open leave row, and the hourly task catches changes that fired no event (accurate to about an hour).

While suspended, a user's assignments are **paused**: shown as "on leave", not overdue, no reminders, no rule evaluation. Pausing matters because cohort or enrolment automation may drop suspended users, which would otherwise remove their assignments. Pausing applies to all suspended users, even when extensions are off.

On return, a configurable grace period (default 24 hours) delays any removal while cohorts and enrolments are re-synced. Then:
```
due = basedue
for each leave period, oldest first, not excluded, minlength <= length <= maxlength:
  s = max(leave start day, assignment start day)
  if s <= due and leave end day > s:  due = due + (leave end day - s)
leavedays = due - basedue
```
- Example: due June 30, out June 10 to July 10 (30 days), new due date July 30.
- Time off after a deadline was already missed gives no extension. The part of a leave before the assignment began does not count. Overlapping periods are merged.
- The assignment start is the anchor day for relative rules, and first matched for fixed ones.
- `notafter` bounds the base date. `extendpastbound` (default on) says whether leave may go past it. Turn it, or `extendleave`, off for hard deadlines.
- The extension is computed from `basedue` each time, so repeated runs never add it twice. A manual exception is never extended automatically.
- Suspension also means termination. A maximum length (default 365 days) ends the pause: the assignment is removed as "separated" and evaluated afresh on reactivation, without extension (Q20).
- Authorized staff can add, correct or exclude periods. Someone already suspended at install has an unknown start and gets no extension until a date is entered.
- Rehire: if the configured hire date field changes between suspension and the end of the grace period, the period is a separation, not leave (Q19).
- Each extension is written to `history` with the leave period.

### 4.7 Resets and cycles (D5)
Reset and due dates stay separate responsibilities:

- **Reset and archive:** the existing GPL plugin `local_recompletion`, as an optional integration and not a dependency. Its supported branches cover 4.1-4.2 and 4.5-5.2. It archives and clears course and activity completion and SCORM attempts (whose tables changed in 4.3), plus quiz and others. Rebuilding this would duplicate maintained code that can destroy data.
- **Cycles and due dates:** this plugin.

```
Reset requested (one user, bulk by rule audience, or scheduled yearly):
  check capability, type, reason; verify archiving is on for the course
  insert cycle (pending)
  adapter calls local_recompletion's reset for that user and course
  event completion_reset -> cycle done
      current assignment: completed stays completed; otherwise superseded
      new assignment in cycle n+1, resolved by rules whose appliesto includes the type
  error or no event -> cycle failed, assignment unchanged, reported to the admin
```
- Resets started in `local_recompletion` itself also open a cycle, with type "external".
- Rules choose where they apply, so remediation can be "14 days after reset date" while the yearly cycle uses a fixed date. Put reset-specific rules first.
- Previous cycles keep their due date, completion date and late flag here, independent of the archive. The preview for a bulk reset lists affected users and those not yet complete.
- Reset refuses to run when completion or SCORM archiving is off for the course. Users currently on leave are skipped and listed.
- Limits: resets are whole-course, not per activity. Grades are cleared into core's grade history if the course is set to. Activity types without reset support there need a check before use; SCORM is supported. The reset cannot be undone automatically.
- Without `local_recompletion` installed, all reset controls are hidden.
- Exact 4.2 and 4.5 adapter calls get integration tests before any production use.

### 4.8 Additional required content (Q22, phase 4)
A module target flagged as additional content for a course target. Users whose course assignment is completed get the new deadline; others inherit their course due date. Needs a "target completed" condition and a "same as target" date type; both are additive. The course assignment itself stays locked.

## 5. Keeping current
Some sync tools write users and profile fields without firing events, so the hourly task is a primary path, not only a backstop.

| Event | Action |
|---|---|
| user_created, user_updated; cohort_member_added/removed | Queue user, all targets |
| user_enrolment_created/updated/deleted | Queue user, that course's targets |
| course_module_completion_updated, course_completed | Update that user's assignment inline |
| course_module_viewed, course_viewed | Record first access; queue user if a rule uses it |
| user_updated with `suspended` changed | Open or close leave; queue user on return |
| local_recompletion completion_reset (if installed) | Complete or open the cycle; queue user |
| user_deleted | Remove assignments, calendar events and queue rows |
| course_module_deleted, course_deleted | Disable target, remove assignments |
| course_reset_ended, course_completion_updated | Re-sync completion for the course |

The spec's "course completion updated" maps to `course_completed` for users. `course_completion_updated` means the criteria changed.

Tasks:
- `process_queue` (every minute): claims up to N users and runs section 4 for that set. Work stays scoped to the queued users. A rule save never runs inline for large audiences; it queues an ad hoc task.
- `recalculate_all` (hourly): every active rule set-wise, plus completion sync. Needed because bulk completion updates fire no event.
- `send_reminders` (every 15 minutes), `sync_calendar` (with the queue), `scheduled_resets` and `cleanup` (daily).

One `core\lock` serialises recalculations, because runs share the day table. Every step is idempotent, so a crashed run is repeated safely.

## 6. Reminders
```
for each enabled step (site default if the target has none):
  due rows = active assignments, user not suspended/deleted/in grace,
             local send day = duedate + offset (user timezone), send hour reached
  repeat steps: iteration = 1..repeatmax every repeatdays until repeatuntil
  INSERT sendlog(state=claimed)      -- unique key; duplicate = skip
  recheck completion, then message_send(); state = sent | failed
```
- Two providers: `duedatereminder` (learner) and `duedatemanager` (copy).
- **At most once:** the claim precedes sending. A crash between them loses that message rather than sending it twice. Failed sends are logged and not retried.
- **Due date changes:** unsent steps follow the new date, since send days are computed live. Sent steps do not repeat.
- **Late start:** only the latest past step sends, so a new rule on overdue users does not send a burst. Others are logged as skipped. Preview shows how many would be messaged.
- Placeholders: `{userfullname}`, `{targetname}`, `{targeturl}`, `{duedate}`, `{daysremaining}`, `{daysoverdue}`, `{rulename}`. Rendered in the recipient's language; values escaped. Due days are site-timezone days; messages are sent at the send hour in the recipient's timezone.
- Manager: site setting names the profile field and the match type (username/email/idnumber). Ambiguous, missing, suspended or deleted matches are skipped and logged.

## 7. Screens and access
Capabilities: `viewreports`, `managerules`, `manageexceptions`, `managereminders`, `manageleave`, `resetusers` (course context); `managesite` (system); `viewown` (user).

| Screen | Content |
|---|---|
| Course "Due dates" (course navigation) | Tabs: targets, rules (drag to order), conflicts, exceptions, resets, reminders |
| Rule form | Audience builder, date, bounds, leave and reset options, window, active dates, overlap and never-applies warnings |
| Preview | Match, new, changed, earlier, later and unmatched counts; "overdue becomes not overdue" count; sample of old date, new date and reason; apply choice |
| Conflicts | Users with two or more candidates, all dates, the rule applied and why; filters; CSV |
| Leave | Periods per user: observed, corrected or excluded |
| Reset | Choose user(s), type, reason; preview; results |
| Exceptions | Date, reason, set by; audit trail |
| Site "All rules" | Every rule across courses, filterable |

- **Preview** runs section 4 into staging for an unsaved definition and compares with stored assignments. It never writes assignments.
- **Overlap** is the count of users matching both condition sets, from an intersected query.
- **Course notice and activity notice:** 4.2 has no hooks and local plugins cannot add text after another module's link. An AMD module places notices beside `[data-for="cmitem"][data-id]` elements. The course notice is server-rendered, with a `lib.php` callback for 4.2 and `db/hooks.php` for 4.4+. Custom themes or course formats may lack the selector; the course notice lists all activity due dates as fallback.
- **No block or learner page** (D6).
- **Calendar:** user events on the learner's normal calendar, with `component = local_duedates`, removed on completion or removal.
- **Report builder:** datasource with assignment, user (including profile fields), course and target entities. The entity implements both the 4.2 and 4.4+ table methods.

## 8. Integration
- **Web services**, with service `local_duedates_api`: `list_assignments` (user, course, target, status, modified since; paged), `get_assignment` (with candidates and history), `list_targets`, `list_rules`, `create_rule`, `update_rule`, `preview_rule`, `list_leave`, `reset_users`. Rule order is part of create/update. Uses `core_external\external_api` (present in 4.2 and later); the old global `external_api` class is being phased out after 4.5.
- **Privacy:** metadata, export and delete for assignments, candidates, exceptions, history, leave, cycles, send log and queue; `setby`/`usermodified` references too.
- **Backup/restore:** `backup_local_duedates_plugin` at course level for targets, rules, current versions and steps. Cmids, cohort ids and profile field ids are handled in `after_restore_course`: cmids are remapped; cohorts and fields match by id on the same site only, else by idnumber/shortname. Unresolvable rules restore as drafts. User data is excluded.

## 9. Open questions
Open items are in projectWork.md with recommendations. This draft assumes each recommendation.

## 10. Testing and delivery
- **PHPUnit:** acceptance scenario in both orders; reorder with both scopes; fall-through and missing anchors; freezing and anchor corrections; leave (none, before, across and after the deadline, repeated, maximum length, cohort dropped while out); resets (SCORM history kept, incomplete user, failed reset, reset-specific rules); exceptions; reopen; SCORM and assign completion; late completion; reminder idempotency, rescheduling and late start; DST and month ends; a 10,000-user check that query count does not grow with users.
- **Behat:** create a rule; learner sees the notice.
- **CI:** moodle-plugin-ci, Moodle 4.2 (PHP 8.1, 8.2) and 4.5 (PHP 8.1, 8.2), PostgreSQL and MariaDB.
- **Manual:** moodle-docker setup under `.docker/`. The development host has PHP 8.4, outside Moodle 4.2's range, so tests run in containers.
- Phases follow the spec's order, each independently reviewable. Leave joins phase 2; resets become phase 8, with the cycle columns present from phase 1.

## 11. Risks
- Sync volume: queue collapse and batching; measure on a copy of real data.
- The minute-level queue means a new enrolment can take about a minute to show a due date.
- Theme or course-format selectors for activity notices (section 7).
- Host restrictions on plugins or cron .
- Suspension is an imperfect signal for leave (section 4.6).
- Resets delete live attempt data after archiving: pilot on a copy first.
