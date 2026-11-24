<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_duedates\local;

/**
 * Set based resolution of due dates for one target.
 *
 * Matches are staged in SQL, merged with the frozen candidates, and the first rule in order wins. Users are
 * never loaded into PHP. Suspended users are left untouched.
 *
 * @package    local_duedates
 * @copyright  2026 local_duedates contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolver {
    /** @var int staged rule matches */
    private const MATCH = 1;
    /** @var int staged winners */
    private const WINNER = 2;
    /** @var int staged completions */
    private const DONE = 3;
    /** @var int staged removals */
    private const LOST = 4;

    /** @var \stdClass target record */
    private \stdClass $target;
    /** @var int id of this run */
    private int $run = 0;
    /** @var int time of this run */
    private int $now = 0;
    /** @var int[]|null users in scope, null for all */
    private ?array $userids = null;

    /**
     * Constructor.
     *
     * @param int $targetid target id
     */
    public function __construct(int $targetid) {
        global $DB;
        $this->target = $DB->get_record('local_duedates_target', ['id' => $targetid], '*', MUST_EXIST);
    }

    /**
     * Recalculates.
     *
     * @param int[]|null $userids users in scope, null for all
     * @param string $cause recorded in the history
     * @return void
     */
    public function recalculate(?array $userids = null, string $cause = 'recalculate'): void {
        global $DB;
        if (!$this->target->enabled || $userids === []) {
            return;
        }
        $this->userids = $userids === null ? null : array_map('intval', array_values($userids));
        $lock = \core\lock\lock_config::get_lock_factory('local_duedates')->get_lock('resolve', 60);
        if (!$lock) {
            throw new \moodle_exception('error:lock', 'local_duedates');
        }
        // One lock for all targets, because runs also share the day table.
        try {
            $this->now = time();
            $this->run = random_int(1, 999999999999999999);
            $transaction = $DB->start_delegated_transaction();
            foreach ($DB->get_records('local_duedates_rule', ['targetid' => $this->target->id, 'status' => 'active']) as $rule) {
                $this->stage_rule($rule);
            }
            $this->create_assignments();
            $this->merge_candidates();
            $this->resolve($cause);
            $this->sync_completion();
            $DB->delete_records('local_duedates_work', ['runid' => $this->run]);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /**
     * Restricts a user id column to the scope.
     *
     * @param string $column column
     * @return string
     */
    private function scope(string $column): string {
        return $this->userids === null ? '' : " AND $column IN (" . implode(',', $this->userids) . ')';
    }

    /**
     * Stages every user who matches a rule, using the version that governs each of them.
     *
     * A user with an assignment is governed by the newest version that existed when the assignment began, or by the
     * newest version applied to existing assignments if that is later. Everybody else is governed by the current one.
     *
     * @param \stdClass $rule rule record
     * @return void
     */
    private function stage_rule(\stdClass $rule): void {
        global $DB;
        $versions = array_values($DB->get_records('local_duedates_rulever', ['ruleid' => $rule->id], 'version ASC'));
        $floor = null;
        foreach ($versions as $index => $version) {
            if ($version->applyscope === manager::SCOPE_EXISTING) {
                $floor = $index;
            }
        }
        $open = (!$rule->activefrom || $rule->activefrom <= $this->now) && (!$rule->activeto || $rule->activeto >= $this->now);
        for ($i = $floor ?? 0; $i < count($versions); $i++) {
            $version = $versions[$i];
            $next = $versions[$i + 1] ?? null;
            $governs = [];
            if ($i !== $floor) {
                $governs[] = "a.rulevermark >= {$version->id}";
            }
            if ($next) {
                $governs[] = "a.rulevermark < {$next->id}";
            }
            $isnew = "(a.id IS NULL OR a.lifecycle IN ('new', 'removed'))";
            $filter = "(a.lifecycle = 'active'" . ($governs ? ' AND ' . implode(' AND ', $governs) : '') . ')';
            if (!$next) {
                $filter = "($isnew OR $filter)";
            }
            if (!$open) {
                // Nobody new joins, but those who already have this rule keep it while they match.
                $filter .= ' AND c.active = 1';
            }
            $this->stage_version($rule, $version, $filter);
        }
    }

    /**
     * Stages matches of one version and works out their dates.
     *
     * @param \stdClass $rule rule record
     * @param \stdClass $version rule version record
     * @param string $filter which users this version governs
     * @return void
     */
    private function stage_version(\stdClass $rule, \stdClass $version, string $filter): void {
        global $DB;
        [$where, $params] = \local_duedates\condition\tree::get_sql(json_decode($version->conditions, true), 'u', $this->target);
        $int = sql::bigint();
        $anchor = 'NULL';
        $fixed = $version->datetype === 'fixed';
        if (!$fixed) {
            switch ($version->anchor) {
                case 'enrolment':
                    $anchor = sql::enrolment_start((int) $this->target->courseid, 'u');
                    break;
                case 'usercreated':
                    $anchor = 'NULLIF(u.timecreated, 0)';
                    break;
                case 'profilefield':
                    [$anchor, $anchorparams] = sql::profile_timestamp((int) $version->anchorfieldid, 'u');
                    $params += $anchorparams;
                    break;
                case 'firstaccess':
                    $anchor = sql::first_access($this->target, 'u');
                    break;
                default:
                    $anchor = "COALESCE(c.timefirstmatched, {$this->now})";
            }
        }
        $anchor = "CAST($anchor AS $int)";
        $enrolled = $this->target->requireenrolment
            ? 'AND ' . sql::enrolment_start((int) $this->target->courseid, 'u') . ' IS NOT NULL' : '';
        $ymd = $fixed ? (string) $this->bound((int) $version->fixeddate, $version) : "CAST(NULL AS $int)";
        $DB->execute("INSERT INTO {local_duedates_work}
                (runid, kind, userid, ruleid, version, sortorder, anchorvalue, ymd, recompute, timecreated)
                SELECT {$this->run}, " . self::MATCH . ", u.id, {$rule->id}, {$version->version}, {$version->sortorder},
                       $anchor, $ymd, 0, {$this->now}
                  FROM {user} u
             LEFT JOIN {local_duedates_assign} a ON a.userid = u.id AND a.targetid = {$this->target->id} AND a.cycle = 0
             LEFT JOIN {local_duedates_cand} c ON c.assignid = a.id AND c.ruleid = {$rule->id}
                 WHERE u.deleted = 0 AND u.suspended = 0 AND $filter $enrolled AND ($where)" . $this->scope('u.id'), $params);
        if (!$fixed) {
            $this->date_relative($rule, $version);
        }
    }

    /**
     * Applies the bounds of a version to a date.
     *
     * @param int $ymd date
     * @param \stdClass $version rule version record
     * @return int
     */
    private function bound(int $ymd, \stdClass $version): int {
        if ($version->notbefore) {
            $ymd = max($ymd, (int) $version->notbefore);
        }
        return $version->notafter ? min($ymd, (int) $version->notafter) : $ymd;
    }

    /**
     * Works out relative dates. Cost depends on the number of distinct days, not of users.
     *
     * @param \stdClass $rule rule record
     * @param \stdClass $version rule version record
     * @return void
     */
    private function date_relative(\stdClass $rule, \stdClass $version): void {
        global $DB;
        $mine = "runid = {$this->run} AND kind = " . self::MATCH . " AND ruleid = {$rule->id} AND version = {$version->version}";
        $range = $DB->get_record_sql("SELECT MIN(anchorvalue) AS lo, MAX(anchorvalue) AS hi FROM {local_duedates_work}
                                       WHERE $mine");
        if ($range->lo === null) {
            return;
        }
        daymap::ensure(daymap::from_time((int) $range->lo), daymap::from_time((int) $range->hi));
        $DB->execute("UPDATE {local_duedates_work} SET anchorymd = (SELECT MAX(d.ymd) FROM {local_duedates_daymap} d
                       WHERE {local_duedates_work}.anchorvalue >= d.daystart
                         AND {local_duedates_work}.anchorvalue <= d.dayend) WHERE $mine");
        $days = $DB->get_fieldset_sql("SELECT DISTINCT anchorymd FROM {local_duedates_work}
                                        WHERE $mine AND anchorymd IS NOT NULL");
        foreach (array_chunk($days, 500) as $chunk) {
            $case = '';
            foreach ($chunk as $day) {
                $due = $this->bound(daymap::add((int) $day, (int) $version->offsetn, $version->offsetunit), $version);
                $case .= " WHEN $day THEN $due";
            }
            $DB->execute("UPDATE {local_duedates_work} SET ymd = CASE anchorymd $case END
                           WHERE $mine AND anchorymd IN (" . implode(',', $chunk) . ')');
        }
    }

    /**
     * Creates assignments for users who match a dated rule for the first time.
     *
     * @return void
     */
    private function create_assignments(): void {
        global $DB;
        $target = (int) $this->target->id;
        $mark = (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_duedates_rulever}');
        $match = self::MATCH;
        $DB->execute("INSERT INTO {local_duedates_assign}
                (targetid, userid, cycle, rulevermark, lifecycle, leavedays, islate, timecreated, timemodified)
                SELECT DISTINCT $target, w.userid, 0, $mark, 'new', 0, 0, {$this->now}, {$this->now}
                  FROM {local_duedates_work} w
                 WHERE w.runid = {$this->run} AND w.kind = $match AND w.ymd IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM {local_duedates_assign} a
                                    WHERE a.targetid = $target AND a.userid = w.userid AND a.cycle = 0)");
        $DB->execute("UPDATE {local_duedates_assign} SET rulevermark = $mark
                       WHERE targetid = $target AND cycle = 0 AND lifecycle = 'removed' AND EXISTS (
                             SELECT 1 FROM {local_duedates_work} w WHERE w.runid = {$this->run} AND w.kind = $match
                                AND w.ymd IS NOT NULL AND w.userid = {local_duedates_assign}.userid)");
        // Completed assignments are locked, so they get no match rows.
        $DB->execute("UPDATE {local_duedates_work} SET assignid = (
                             SELECT a.id FROM {local_duedates_assign} a
                              WHERE a.targetid = $target AND a.cycle = 0 AND a.userid = {local_duedates_work}.userid
                                AND a.lifecycle IN ('new', 'active', 'removed'))
                       WHERE runid = {$this->run} AND kind = $match");
    }

    /**
     * Assignments that this run may change.
     *
     * @param string $alias alias of the assignment table
     * @return string
     */
    private function changeable(string $alias): string {
        return "$alias.targetid = {$this->target->id} AND $alias.cycle = 0 AND $alias.lifecycle IN ('new', 'active', 'removed')
            AND EXISTS (SELECT 1 FROM {user} chu WHERE chu.id = $alias.userid AND (chu.suspended = 0 OR chu.deleted = 1))"
            . $this->scope("$alias.userid");
    }

    /**
     * Merges staged matches into the frozen candidates.
     *
     * @return void
     */
    private function merge_candidates(): void {
        global $DB;
        $mine = "w.runid = {$this->run} AND w.kind = " . self::MATCH;
        $join = "$mine AND w.assignid = {local_duedates_cand}.assignid AND w.ruleid = {local_duedates_cand}.ruleid";
        $DB->execute("UPDATE {local_duedates_work} SET recompute = 1
                       WHERE runid = {$this->run} AND kind = " . self::MATCH . " AND EXISTS (
                             SELECT 1 FROM {local_duedates_cand} c
                               JOIN {local_duedates_rulever} v ON v.ruleid = c.ruleid
                              WHERE c.assignid = {local_duedates_work}.assignid AND c.ruleid = {local_duedates_work}.ruleid
                                AND v.version = {local_duedates_work}.version
                                AND (c.active = 0 OR c.duedate IS NULL OR c.version <> {local_duedates_work}.version
                                     OR (v.followanchor = 1
                                         AND COALESCE(c.anchorvalue, 0) <> COALESCE({local_duedates_work}.anchorvalue, 0))))");
        foreach (['anchorvalue' => 'anchorvalue', 'duedate' => 'ymd', 'version' => 'version'] as $field => $from) {
            $DB->execute("UPDATE {local_duedates_cand}
                             SET $field = (SELECT MAX(w.$from) FROM {local_duedates_work} w WHERE $join)
                           WHERE EXISTS (SELECT 1 FROM {local_duedates_work} w WHERE $join AND w.recompute = 1)");
        }
        $DB->execute("UPDATE {local_duedates_cand} SET active = 1, timeunmatched = NULL,
                             sortorder = (SELECT MAX(w.sortorder) FROM {local_duedates_work} w WHERE $join)
                       WHERE EXISTS (SELECT 1 FROM {local_duedates_work} w WHERE $join)");
        $DB->execute("INSERT INTO {local_duedates_cand}
                (assignid, ruleid, version, sortorder, anchorvalue, duedate, active, timefirstmatched)
                SELECT w.assignid, w.ruleid, w.version, w.sortorder, w.anchorvalue, w.ymd, 1, {$this->now}
                  FROM {local_duedates_work} w
                 WHERE $mine AND w.assignid IS NOT NULL AND NOT EXISTS (
                       SELECT 1 FROM {local_duedates_cand} c WHERE c.assignid = w.assignid AND c.ruleid = w.ruleid)");
        $DB->execute("UPDATE {local_duedates_cand} SET active = 0, timeunmatched = {$this->now}
                       WHERE active = 1 AND EXISTS (SELECT 1 FROM {local_duedates_assign} a
                             WHERE a.id = {local_duedates_cand}.assignid AND " . $this->changeable('a') . ")
                         AND NOT EXISTS (SELECT 1 FROM {local_duedates_work} w WHERE $join)");
    }

    /**
     * Chooses the winner of each assignment and stores the differences.
     *
     * @param string $cause recorded in the history
     * @return void
     */
    private function resolve(string $cause): void {
        global $DB, $USER;
        $cause = clean_param($cause, PARAM_ALPHA);
        $winner = self::WINNER;
        $changeable = $this->changeable('a');
        $enrolled = $this->target->requireenrolment
            ? 'AND ' . sql::enrolment_start((int) $this->target->courseid, 'u') . ' IS NOT NULL' : '';
        // A live exception outranks every rule; the best candidate is still recorded as the winning rule.
        $DB->execute("INSERT INTO {local_duedates_work}
                (runid, kind, userid, assignid, ruleid, version, anchorymd, ymd, exceptionid, recompute, timecreated)
                SELECT {$this->run}, $winner, a.userid, a.id, b.ruleid, b.version, b.duedate,
                       COALESCE(x.duedate, b.duedate), x.id, 0, {$this->now}
                  FROM {local_duedates_assign} a
                  JOIN {user} u ON u.id = a.userid AND u.deleted = 0
             LEFT JOIN (SELECT c.assignid, c.ruleid, c.version, c.duedate, ROW_NUMBER() OVER (
                               PARTITION BY c.assignid ORDER BY c.sortorder, c.ruleid) AS rn
                          FROM {local_duedates_cand} c
                          JOIN {local_duedates_assign} ca ON ca.id = c.assignid
                         WHERE c.active = 1 AND c.duedate IS NOT NULL AND ca.targetid = {$this->target->id}
                       ) b ON b.assignid = a.id AND b.rn = 1
             LEFT JOIN {local_duedates_except} x ON x.assignid = a.id AND x.timerevoked IS NULL $enrolled
                 WHERE $changeable AND (b.duedate IS NOT NULL OR x.duedate IS NOT NULL)");
        $days = $DB->get_fieldset_sql("SELECT DISTINCT ymd FROM {local_duedates_work}
                                        WHERE runid = {$this->run} AND kind = $winner");
        foreach ($days as $day) {
            daymap::ensure((int) $day, (int) $day);
        }
        $DB->execute("UPDATE {local_duedates_work} SET timedue = (SELECT d.dayend FROM {local_duedates_daymap} d
                             WHERE d.ymd = {local_duedates_work}.ymd) WHERE runid = {$this->run} AND kind = $winner");

        // Keep only winners that differ from what is stored.
        $DB->execute("DELETE FROM {local_duedates_work} WHERE runid = {$this->run} AND kind = $winner AND EXISTS (
                SELECT 1 FROM {local_duedates_assign} a WHERE a.id = {local_duedates_work}.assignid AND a.lifecycle = 'active'
                   AND a.duedate = {local_duedates_work}.ymd AND a.timedue = {local_duedates_work}.timedue
                   AND COALESCE(a.basedue, 0) = COALESCE({local_duedates_work}.anchorymd, {local_duedates_work}.ymd)
                   AND COALESCE(a.winruleid, 0) = COALESCE({local_duedates_work}.ruleid, 0)
                   AND COALESCE(a.winversion, 0) = COALESCE({local_duedates_work}.version, 0)
                   AND COALESCE(a.exceptionid, 0) = COALESCE({local_duedates_work}.exceptionid, 0))");

        // Assignments that no longer have a date, worked out before anything is updated.
        $losing = $this->changeable('a') . " AND a.lifecycle IN ('new', 'active') AND (
                (NOT EXISTS (SELECT 1 FROM {local_duedates_cand} c WHERE c.assignid = a.id AND c.active = 1
                                AND c.duedate IS NOT NULL)
                 AND NOT EXISTS (SELECT 1 FROM {local_duedates_except} x JOIN {user} u ON u.id = a.userid
                                  WHERE x.assignid = a.id AND x.timerevoked IS NULL $enrolled))
                OR EXISTS (SELECT 1 FROM {user} du WHERE du.id = a.userid AND du.deleted = 1))";
        $actor = (int) $USER->id;
        $lost = self::LOST;
        $int = sql::bigint();
        $DB->execute("INSERT INTO {local_duedates_work} (runid, kind, userid, assignid, recompute, timecreated)
                SELECT {$this->run}, $lost, a.userid, a.id, 0, {$this->now} FROM {local_duedates_assign} a WHERE $losing");
        $DB->execute("INSERT INTO {local_duedates_history}
                (assignid, oldduedate, newduedate, oldruleid, newruleid, oldlifecycle, newlifecycle, cause, actorid, timecreated)
                SELECT a.id, a.duedate, CAST(NULL AS $int), a.winruleid, CAST(NULL AS $int), a.lifecycle, 'removed', '$cause',
                       $actor, {$this->now}
                  FROM {local_duedates_assign} a
                  JOIN {local_duedates_work} w ON w.assignid = a.id AND w.runid = {$this->run} AND w.kind = $lost");
        $DB->execute("UPDATE {local_duedates_assign} SET lifecycle = 'removed', timeremoved = {$this->now},
                             removedreason = 'norule', timemodified = {$this->now}
                       WHERE EXISTS (SELECT 1 FROM {local_duedates_work} w WHERE w.runid = {$this->run} AND w.kind = $lost
                                        AND w.assignid = {local_duedates_assign}.id)");

        $mine = "w.runid = {$this->run} AND w.kind = $winner";
        $DB->execute("INSERT INTO {local_duedates_history}
                (assignid, oldduedate, newduedate, oldruleid, newruleid, oldlifecycle, newlifecycle, cause, actorid, timecreated)
                SELECT a.id, a.duedate, w.ymd, a.winruleid, w.ruleid, a.lifecycle, 'active', '$cause', $actor,
                       {$this->now}
                  FROM {local_duedates_assign} a JOIN {local_duedates_work} w ON w.assignid = a.id WHERE $mine");
        $sets = [];
        $map = ['duedate' => 'w.ymd', 'timedue' => 'w.timedue', 'winruleid' => 'w.ruleid', 'winversion' => 'w.version',
            'exceptionid' => 'w.exceptionid', 'basedue' => 'COALESCE(w.anchorymd, w.ymd)'];
        foreach ($map as $field => $from) {
            $sets[] = "$field = (SELECT MAX($from) FROM {local_duedates_work} w
                                  WHERE $mine AND w.assignid = {local_duedates_assign}.id)";
        }
        $DB->execute('UPDATE {local_duedates_assign} SET ' . implode(', ', $sets) . ", lifecycle = 'active', timeremoved = NULL,
                             removedreason = NULL, timemodified = {$this->now}
                       WHERE EXISTS (SELECT 1 FROM {local_duedates_work} w
                                      WHERE $mine AND w.assignid = {local_duedates_assign}.id)");
    }

    /**
     * Marks assignments complete. Once complete they are locked.
     *
     * @return void
     */
    private function sync_completion(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/completionlib.php');
        $target = $this->target;
        if ($target->cmid) {
            $states = [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS];
            if ($target->countfail) {
                $states[] = COMPLETION_COMPLETE_FAIL;
                $states[] = COMPLETION_COMPLETE_FAIL_HIDDEN;
            }
            $from = '{course_modules_completion} cc';
            $on = 'cc.coursemoduleid = ' . (int) $target->cmid . ' AND cc.completionstate IN (' . implode(',', $states) . ')';
            $time = 'cc.timemodified';
        } else {
            $from = '{course_completions} cc';
            $on = 'cc.course = ' . (int) $target->courseid . ' AND cc.timecompleted > 0';
            $time = 'cc.timecompleted';
        }
        $done = self::DONE;
        $DB->execute("INSERT INTO {local_duedates_work} (runid, kind, userid, assignid, timedue, recompute, timecreated)
                SELECT {$this->run}, $done, a.userid, a.id, MIN($time), 0, {$this->now}
                  FROM {local_duedates_assign} a
                  JOIN $from ON cc.userid = a.userid AND $on
             LEFT JOIN {local_duedates_rulever} v ON v.ruleid = a.winruleid AND v.version = a.winversion
                 WHERE a.targetid = {$target->id} AND a.cycle = 0 AND a.lifecycle = 'active'
                   AND (v.completionfrom IS NULL OR $time >= v.completionfrom)" . $this->scope('a.userid') . '
              GROUP BY a.userid, a.id');
        $mine = "w.runid = {$this->run} AND w.kind = $done";
        $actor = (int) $USER->id;
        $DB->execute("INSERT INTO {local_duedates_history}
                (assignid, oldduedate, newduedate, oldruleid, newruleid, oldlifecycle, newlifecycle, cause, actorid, timecreated)
                SELECT a.id, a.duedate, a.duedate, a.winruleid, a.winruleid, 'active', 'completed', 'completion', $actor,
                       {$this->now}
                  FROM {local_duedates_assign} a JOIN {local_duedates_work} w ON w.assignid = a.id WHERE $mine");
        $sub = "(SELECT MAX(w.timedue) FROM {local_duedates_work} w WHERE $mine AND w.assignid = {local_duedates_assign}.id)";
        $DB->execute("UPDATE {local_duedates_assign} SET timecompleted = $sub,
                             islate = CASE WHEN $sub > timedue THEN 1 ELSE 0 END, lifecycle = 'completed',
                             timemodified = {$this->now}
                       WHERE EXISTS (SELECT 1 FROM {local_duedates_work} w
                                      WHERE $mine AND w.assignid = {local_duedates_assign}.id)");
    }
}
