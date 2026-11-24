# Task: Build `local_duedates`, a rule-based due date plugin for Moodle
 
## Context
- I'm the LMS admin/developer for a vendor-hosted **Moodle 4.2** site on **PHP 8.2**. We'll upgrade to 4.5/5.x within the next year, so code against 4.1–4.2 APIs and avoid anything deprecated in 4.5.
- Most of our content is **SCORM**. Moodle only supports due dates for some activity types (assign/quiz/lesson), and only per activity. I need due dates that **don't care what the activity type is**: if it's due, it's due.
- We already have a dynamic cohorts plugin that fills cohorts from profile fields. Users and their custom profile fields (division, hire date, etc.) are synced from an HRIS.
- An external Python/FastAPI app (the "dashboard app") reads LMS data through web services and powers a manager dashboard. It will read due dates from this plugin.
- Take inspiration from the "Programs for Moodle" plugin (`enrol_programs` by Open LMS / Petr Skoda, GPL v3), especially its allocation/scheduling model, its due vs. end date separation, and how its notifications work. **This plugin must not depend on `enrol_programs` or `local_openlms`.** If you reuse code from it, keep its copyright headers (GPL v3 is compatible).
## Core concepts
- **Target**: what is due. Either a **course** (done = course completion) or a **course module** of any type (done = activity completion: COMPLETION_COMPLETE or COMPLETION_COMPLETE_PASS; whether COMPLETE_FAIL counts is a per-target option, off by default). Admins choose per course whether rules target the course or specific activities. Both are allowed in the same course.
- **Rule**: who a due date applies to, and how it's calculated.
  - **Audience conditions**, combined with AND/OR: standard and **custom profile fields** (all field types, with type-appropriate operators: equals, not equals, contains, in list, date before/after/between, empty/not empty), cohort membership, enrolment in the course (with an optional "enrolled before/after X" condition), and user created date. Design the condition types to be pluggable so more can be added later.
  - **Due date calculation**:
    - a **fixed** date; or
    - **relative**: N days/weeks/months after an anchor. Anchors: course enrolment start, user created, a date-type custom profile field (e.g. hire date), the date the user first matched the rule, or the target's first access.
    - optional: "but no earlier than X" and "but no later than X" bounds.
  - **Completion window**: which completions count. Choose any completion ever, or completions on/after a date. This supports annual recertification later, so the data model must not rule out recurrence.
  - **Priority** (integer) and **conflict behaviour** (see below).
  - **Active from/to dates**, so a rule can be scheduled or retired.
- **Assignment**: the resolved result for one user and one target. It stores the due date, the winning rule, every candidate rule and its calculated date (for auditing), and its status: not due yet / due soon / overdue / completed / completed late. Store `timecompleted` and whether it was late.
- **Never restrict access.** A due date must never suspend enrolment, hide content, or block completion. After the due date the learner can still complete, and the assignment then becomes "completed late".
## Conflict resolution (the hard part)
A user can match several rules for the same target. Each rule has a **mode**:
- **Standard**: a candidate date.
- **Cap ("no later than")**: the date can only move earlier, never later.
- **Override**: replaces every lower-priority candidate, including dates already passed, e.g. "everyone in this group now has a new deadline of X".
Resolution: take the highest-priority Override rule if there is one. Otherwise take the earliest candidate from the Standard and Cap rules. Make the default strategy configurable per target (earliest / latest / highest priority) and document it clearly.
 
Required tooling:
- **Preview / simulation** before saving or activating a rule: how many users match, how many get a new or changed due date, and a sample list showing each user's old date → new date and the reason.
- **Conflicts report** per target: users who match more than one rule, with every candidate date, the resolved date, and which rule won. Include filters and a CSV export.
- **Rule overlap warning** at design time: when two rules on the same target could match the same users, show the overlap count.
- **Manual per-user exception** (an extension date, plus a reason and who set it) that outranks all rules. Show it in the audit trail.
### Acceptance scenario (build it as a PHPUnit test)
Target: the "Compliance" course.
- Rule A: users enrolled before 2026-06-01 → fixed due date **2026-06-30**.
- Rule B: users whose `hiredate` custom profile field is on or after 2026-06-01 → **30 days after hiredate**.
- Rule C: custom profile field `division` = `DIV1` → **2026-12-18**. The DIV1 division is being acquired and must be complete by then.
Expected results:
| User | Matches | Expected due date |
|---|---|---|
| Non-DIV1 existing employee | A | 2026-06-30 |
| Non-DIV1, hired 2026-08-10 | B | 2026-09-09 |
| DIV1, hired 2026-11-30 | B (2026-12-30), C | **2026-12-18** (C as Cap, or earliest wins) |
| DIV1, hired 2026-11-01 | B (2026-12-01), C | **2026-12-01** (earliest wins; C as Cap doesn't extend it) |
| DIV1 existing employee, not complete, overdue since June 30 | A, C | **Depends on C's mode.** Standard/Cap → still overdue since 2026-06-30. Override → 2026-12-18. The test must cover both, and the preview must make this difference obvious to the admin. |
| Anyone who completed before their due date | — | completed; no reminders |
 
## Reminders
- Per target (with a site default template), a configurable **reminder schedule**: any number of steps, each defined as an offset from the due date. Examples: 7 days before, 2 days before, 1 day before, on the due day, 1 day overdue. Plus an optional **repeat while overdue** every N days, with a maximum count or a stop date.
- Each step has its own subject and body template with placeholders: user name, target name, link, due date, days remaining or overdue, and the winning rule's name.
- Optionally copy the user's manager. Read the manager from a configurable custom profile field holding the manager's username, email, or idnumber.
- Use the Moodle message API with our own message providers, so users and admins can choose email/notification. Never send to suspended or deleted users, or for completed assignments. Each step is sent at most once per assignment (idempotent) and recorded in a log. If the due date changes, steps that haven't been sent are rescheduled. Be safe across time zones by using the user's timezone for "on the due day".
## Keeping data current
- Recalculate assignments when these events fire: user created/updated (profile fields), cohort member added/removed, user enrolment created/updated/deleted, course and course module completion updated, course module deleted, course deleted. Also run an hourly cron as a backstop.
- **Freezing**: once assigned, a due date doesn't move just because time passes. It only changes when rule membership changes, a rule is edited (admin chooses "apply to existing assignments" or "new assignments only" on save, shown in the preview), or an exception is set.
- If a user stops matching every rule, mark the assignment as removed (keep the history, don't delete it).
## UI
- A course-level page, "Due dates", with a list of targets, rules, preview, conflicts report, and exceptions. Use capabilities so a course-level manager can be allowed or not.
- A site admin page listing all rules across courses.
- For learners: a due/overdue notice on the course page and next to the targeted activity, a dashboard block ("My due dates") sorted by urgency, and user calendar events for due dates. The Timeline block can't easily show third-party events, so don't attempt that.
- A **Report builder** datasource (Moodle 4.x custom reports) for assignments: user, profile fields, target, due date, status, winning rule, completed date, late flag.
## Integration
- Web services (with a service definition) to list and get assignments by user/course/target, and to create, update, or preview rules. These are for the dashboard app.
- Privacy provider (GDPR) covering assignments, exceptions, and reminder logs.
- Backup/restore of course-level rules and targets, remapping cmids. Don't include user assignments by default.
## Constraints
- Component: `local_duedates`, repo `moodle-due-dates`. Keep the plugin generic: no company, site, or vendor names in code, the component name, DB tables, capabilities, lang strings, or default templates. Examples like "DIV1" and "division" belong in tests and docs only. GPL v3. Moodle coding style (moodle-cs), XMLDB schema in `db/install.xml`, `db/upgrade.php` for every change, lang strings for everything.
- Performance: resolution must work set-wise in SQL for sites with tens of thousands of users. Don't loop over every user in PHP. Recalculation triggered by events must be scoped to the one user.
## How to work
1. Read this spec and write `docs/design.md`: the data model (tables and fields), the resolution algorithm in pseudocode, the event and cron flow, the reminder scheduling, the UI screens, and the open questions. **Stop and show me the design before writing code.**
2. Then implement in small commits in this order:
   1. schema + rules + resolution engine + acceptance test
   2. event/cron recalculation
   3. reminders
   4. admin UI + preview + conflicts report
   5. learner UI
   6. report builder datasource + web services
   7. privacy + backup/restore
3. Write PHPUnit tests for resolution (including the acceptance scenario), freezing, exceptions, completion with SCORM and at least one other module type, late completion, and reminder idempotency and rescheduling. Write Behat tests for creating a rule and seeing the learner's notice.
4. Set up GitHub Actions with `moodle-plugin-ci` (Moodle 4.2 and 4.5, PHP 8.1/8.2) and a moodle-docker setup for manual testing.
5. Finish with a summary, install steps, and a manual test checklist based on the acceptance scenario.
 