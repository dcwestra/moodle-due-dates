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
 * Creates and edits targets, rules and exceptions. Callers check capabilities.
 *
 * @package    local_duedates
 * @copyright  2026 local_duedates contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var string changes apply to existing assignments */
    public const SCOPE_EXISTING = 'existing';
    /** @var string changes apply to new assignments only */
    public const SCOPE_NEW = 'new';

    /**
     * Creates a target.
     *
     * @param int $courseid course id
     * @param int $cmid course module id, 0 for the course
     * @param array $options other target fields
     * @return \stdClass
     */
    public static function create_target(int $courseid, int $cmid = 0, array $options = []): \stdClass {
        global $DB, $USER;
        if ($cmid && !$DB->record_exists('course_modules', ['id' => $cmid, 'course' => $courseid])) {
            throw new \moodle_exception('error:badtarget', 'local_duedates');
        }
        $options = array_intersect_key($options, array_flip(['countfail', 'requireenrolment', 'duesoondays', 'enabled',
            'managercopy']));
        $id = $DB->insert_record('local_duedates_target', $options + ['courseid' => $courseid, 'cmid' => $cmid,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => $USER->id]);
        return $DB->get_record('local_duedates_target', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Validates and normalises a rule definition.
     *
     * @param array $def definition
     * @return array fields of the rulever table
     */
    private static function definition(array $def): array {
        $bad = static function (string $field): void {
            throw new \moodle_exception('error:badrule', 'local_duedates', '', $field);
        };
        $def += ['datetype' => '', 'fixeddate' => null, 'offsetn' => 0, 'offsetunit' => null, 'anchor' => null,
            'anchorfieldid' => null, 'followanchor' => 1, 'notbefore' => null, 'notafter' => null, 'extendleave' => 0,
            'extendpastbound' => 1, 'completionfrom' => null, 'conditions' => ['op' => 'and', 'items' => []],
            'appliesto' => null];
        if ($def['datetype'] === 'fixed') {
            daymap::validate((int) $def['fixeddate']);
        } else if ($def['datetype'] === 'relative') {
            if (!in_array($def['offsetunit'], ['day', 'week', 'month'], true)) {
                $bad('offsetunit');
            }
            if (!in_array($def['anchor'], ['enrolment', 'usercreated', 'profilefield', 'firstmatched', 'firstaccess'], true)) {
                $bad('anchor');
            }
            if ($def['anchor'] === 'profilefield' && !self::is_date_field((int) $def['anchorfieldid'])) {
                $bad('anchorfieldid');
            }
        } else {
            $bad('datetype');
        }
        foreach (['notbefore', 'notafter'] as $bound) {
            if ($def[$bound] !== null) {
                daymap::validate((int) $def[$bound]);
            }
        }
        if ($def['notbefore'] && $def['notafter'] && $def['notbefore'] > $def['notafter']) {
            $bad('notafter');
        }
        \local_duedates\condition\tree::validate($def['conditions']);
        $def['conditions'] = json_encode($def['conditions']);
        $def['appliesto'] = $def['appliesto'] === null ? null : json_encode($def['appliesto']);
        return array_intersect_key($def, array_flip(['datetype', 'fixeddate', 'offsetn', 'offsetunit', 'anchor',
            'anchorfieldid', 'followanchor', 'notbefore', 'notafter', 'extendleave', 'extendpastbound', 'completionfrom',
            'conditions', 'appliesto']));
    }

    /**
     * Is this a date profile field?
     *
     * @param int $fieldid field id
     * @return bool
     */
    private static function is_date_field(int $fieldid): bool {
        global $DB;
        return $DB->record_exists('user_info_field', ['id' => $fieldid, 'datatype' => 'datetime']);
    }

    /**
     * Checks an apply scope.
     *
     * @param string $scope scope
     * @return void
     */
    private static function check_scope(string $scope): void {
        if (!in_array($scope, [self::SCOPE_EXISTING, self::SCOPE_NEW], true)) {
            throw new \moodle_exception('error:badrule', 'local_duedates', '', 'applyscope');
        }
    }

    /**
     * Stores a definition.
     *
     * @param int $ruleid rule id
     * @param int $version version number
     * @param int $sortorder evaluation order
     * @param array $fields rulever fields
     * @param string $scope apply scope
     * @return void
     */
    private static function insert_version(int $ruleid, int $version, int $sortorder, array $fields, string $scope): void {
        global $DB, $USER;
        $DB->insert_record('local_duedates_rulever', ['ruleid' => $ruleid, 'version' => $version, 'sortorder' => $sortorder,
            'applyscope' => $scope, 'timecreated' => time(), 'usermodified' => $USER->id] + $fields);
    }

    /**
     * Adds a rule at the end of the target's list.
     *
     * @param int $targetid target id
     * @param string $name rule name
     * @param array $def definition
     * @param string $scope apply scope
     * @return int rule id
     */
    public static function create_rule(int $targetid, string $name, array $def, string $scope = self::SCOPE_EXISTING): int {
        global $DB, $USER;
        self::check_scope($scope);
        $fields = self::definition($def);
        $transaction = $DB->start_delegated_transaction();
        $max = (int) $DB->get_field_sql("SELECT MAX(v.sortorder) FROM {local_duedates_rulever} v
            JOIN {local_duedates_rule} r ON r.id = v.ruleid WHERE r.targetid = ?", [$targetid]);
        $ruleid = $DB->insert_record('local_duedates_rule', ['targetid' => $targetid, 'name' => $name,
            'status' => $def['status'] ?? 'active', 'activefrom' => $def['activefrom'] ?? 0,
            'activeto' => $def['activeto'] ?? 0, 'currentversion' => 1, 'timecreated' => time(),
            'timemodified' => time(), 'usermodified' => $USER->id]);
        self::insert_version($ruleid, 1, $max + 1, $fields, $scope);
        $transaction->allow_commit();
        return $ruleid;
    }

    /**
     * Edits a rule by adding a version.
     *
     * @param int $ruleid rule id
     * @param array $def complete definition
     * @param string $scope apply scope
     * @return void
     */
    public static function update_rule(int $ruleid, array $def, string $scope): void {
        global $DB;
        self::check_scope($scope);
        $fields = self::definition($def);
        $transaction = $DB->start_delegated_transaction();
        $rule = $DB->get_record('local_duedates_rule', ['id' => $ruleid], '*', MUST_EXIST);
        $old = $DB->get_record(
            'local_duedates_rulever',
            ['ruleid' => $ruleid, 'version' => $rule->currentversion],
            '*',
            MUST_EXIST
        );
        self::insert_version($ruleid, $rule->currentversion + 1, $old->sortorder, $fields, $scope);
        self::touch_rule($ruleid, ['currentversion' => $rule->currentversion + 1]);
        $transaction->allow_commit();
    }

    /**
     * Updates rule fields.
     *
     * @param int $ruleid rule id
     * @param array $fields changed fields
     * @return void
     */
    private static function touch_rule(int $ruleid, array $fields): void {
        global $DB, $USER;
        $DB->update_record('local_duedates_rule', (object) ($fields + ['id' => $ruleid, 'timemodified' => time(),
            'usermodified' => $USER->id]));
    }

    /**
     * Changes status: draft, active, disabled or deleted.
     *
     * @param int $ruleid rule id
     * @param string $status new status
     * @return void
     */
    public static function set_rule_status(int $ruleid, string $status): void {
        if (!in_array($status, ['draft', 'active', 'disabled', 'deleted'], true)) {
            throw new \moodle_exception('error:badrule', 'local_duedates', '', 'status');
        }
        self::touch_rule($ruleid, ['status' => $status]);
    }

    /**
     * Sets the period in which a rule may gain users.
     *
     * @param int $ruleid rule id
     * @param int $from timestamp or 0
     * @param int $to timestamp or 0
     * @return void
     */
    public static function set_rule_window(int $ruleid, int $from, int $to): void {
        self::touch_rule($ruleid, ['activefrom' => $from, 'activeto' => $to]);
    }

    /**
     * Reorders all the rules of a target.
     *
     * For existing assignments positions change on every version. Otherwise each rule gets a new version, so that
     * existing assignments keep the whole previous order.
     *
     * @param int $targetid target id
     * @param int[] $ruleids every rule of the target, first evaluated first
     * @param string $scope apply scope
     * @return void
     */
    public static function reorder_rules(int $targetid, array $ruleids, string $scope): void {
        global $DB;
        self::check_scope($scope);
        $rules = $DB->get_records('local_duedates_rule', ['targetid' => $targetid]);
        $ruleids = array_map('intval', array_values($ruleids));
        $live = array_keys(array_filter($rules, static fn($r) => $r->status !== 'deleted'));
        if (
            count($ruleids) !== count(array_unique($ruleids)) || array_diff($live, $ruleids)
                || array_diff($ruleids, array_keys($rules))
        ) {
            throw new \moodle_exception('error:badrule', 'local_duedates', '', 'order');
        }
        $transaction = $DB->start_delegated_transaction();
        foreach ($ruleids as $index => $ruleid) {
            if ($scope === self::SCOPE_EXISTING) {
                $DB->set_field('local_duedates_rulever', 'sortorder', $index + 1, ['ruleid' => $ruleid]);
                continue;
            }
            $version = $rules[$ruleid]->currentversion;
            $old = (array) $DB->get_record(
                'local_duedates_rulever',
                ['ruleid' => $ruleid, 'version' => $version],
                '*',
                MUST_EXIST
            );
            unset(
                $old['id'],
                $old['ruleid'],
                $old['version'],
                $old['sortorder'],
                $old['applyscope'],
                $old['timecreated'],
                $old['usermodified']
            );
            self::insert_version($ruleid, $version + 1, $index + 1, $old, $scope);
            self::touch_rule($ruleid, ['currentversion' => $version + 1]);
        }
        $transaction->allow_commit();
    }

    /**
     * Sets a manual due date that outranks every rule, then recalculates the user.
     *
     * @param int $assignid assignment id
     * @param int $ymd due date
     * @param string $reason reason
     * @return int exception id
     */
    public static function set_exception(int $assignid, int $ymd, string $reason): int {
        global $DB, $USER;
        daymap::validate($ymd);
        if (trim($reason) === '') {
            throw new \moodle_exception('error:reasonrequired', 'local_duedates');
        }
        $assign = $DB->get_record('local_duedates_assign', ['id' => $assignid], '*', MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        self::close_exceptions($assignid);
        $id = $DB->insert_record('local_duedates_except', ['assignid' => $assignid, 'duedate' => $ymd, 'reason' => $reason,
            'setby' => $USER->id, 'timeset' => time()]);
        $transaction->allow_commit();
        (new resolver((int) $assign->targetid))->recalculate([$assign->userid], 'exception');
        return $id;
    }

    /**
     * Revokes the exception, then recalculates the user.
     *
     * @param int $assignid assignment id
     * @return void
     */
    public static function revoke_exception(int $assignid): void {
        global $DB;
        $assign = $DB->get_record('local_duedates_assign', ['id' => $assignid], '*', MUST_EXIST);
        self::close_exceptions($assignid);
        (new resolver((int) $assign->targetid))->recalculate([$assign->userid], 'exception');
    }

    /**
     * Closes live exceptions.
     *
     * @param int $assignid assignment id
     * @return void
     */
    private static function close_exceptions(int $assignid): void {
        global $DB, $USER;
        $DB->execute("UPDATE {local_duedates_except} SET timerevoked = ?, revokedby = ?
                       WHERE assignid = ? AND timerevoked IS NULL", [time(), $USER->id, $assignid]);
    }
}
