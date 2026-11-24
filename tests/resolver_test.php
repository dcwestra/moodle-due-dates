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

namespace local_duedates;

use local_duedates\local\assignment;
use local_duedates\local\daymap;
use local_duedates\local\manager;
use local_duedates\local\resolver;

/**
 * Tests for due date resolution.
 *
 * @package    local_duedates
 * @copyright  2026 local_duedates contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_duedates\local\resolver
 */
final class resolver_test extends \advanced_testcase {
    /** @var \stdClass course */
    private \stdClass $course;
    /** @var \stdClass target */
    private \stdClass $target;
    /** @var int hire date field */
    private int $hire;
    /** @var int division field */
    private int $division;

    /**
     * Creates the fields, course and target.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setTimezone('America/New_York');
        $gen = $this->getDataGenerator();
        $this->hire = $gen->create_custom_profile_field(['shortname' => 'hiredate', 'name' => 'Hire date',
            'datatype' => 'datetime', 'param1' => 1970, 'param2' => 2050])->id;
        $this->division = $gen->create_custom_profile_field(['shortname' => 'division', 'name' => 'Division',
            'datatype' => 'text'])->id;
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->target = manager::create_target($this->course->id);
    }

    /**
     * Creates an enrolled user.
     *
     * @param string|null $division division
     * @param int|null $hired hire day
     * @param int $enrolled enrolment day
     * @return int user id
     */
    private function user(?string $division, ?int $hired, int $enrolled = 20260101): int {
        global $DB;
        $user = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            null,
            'manual',
            daymap::start($enrolled)
        );
        foreach ([$this->division => $division, $this->hire => $hired ? daymap::start($hired) : null] as $field => $value) {
            if ($value !== null) {
                $DB->insert_record('user_info_data', ['userid' => $user->id, 'fieldid' => $field, 'data' => $value,
                    'dataformat' => 0]);
            }
        }
        return (int) $user->id;
    }

    /**
     * Adds a rule.
     *
     * @param array $def definition
     * @param array|null $condition leaf
     * @param string $scope scope
     * @return int
     */
    private function rule(array $def, ?array $condition = null, string $scope = 'existing'): int {
        return manager::create_rule(
            $this->target->id,
            'Rule',
            $def + ['conditions' => ['op' => 'and', 'items' => $condition ? [$condition] : []]],
            $scope
        );
    }

    /**
     * Fixed date definition.
     *
     * @param int $ymd day
     * @return array
     */
    private function fixed(int $ymd): array {
        return ['datetype' => 'fixed', 'fixeddate' => $ymd];
    }

    /**
     * Thirty days after hire.
     *
     * @return array
     */
    private function afterhire(): array {
        return ['datetype' => 'relative', 'offsetn' => 30, 'offsetunit' => 'day', 'anchor' => 'profilefield',
            'anchorfieldid' => $this->hire];
    }

    /**
     * Division condition.
     *
     * @return array
     */
    private function isdiv1(): array {
        return ['type' => 'profilefield', 'fieldid' => $this->division, 'operator' => 'equals', 'value' => 'DIV1'];
    }

    /**
     * Hired on or after a day.
     *
     * @return array
     */
    private function ishired(): array {
        return ['type' => 'profilefield', 'fieldid' => $this->hire, 'operator' => 'onorafter', 'value' => 20260601];
    }

    /**
     * Recalculates.
     *
     * @param array|null $userids users
     */
    private function run_now(?array $userids = null): void {
        (new resolver((int) $this->target->id))->recalculate($userids);
    }

    /**
     * Assignment of a user.
     *
     * @param int $userid user
     * @return \stdClass|false
     */
    private function assign(int $userid) {
        global $DB;
        return $DB->get_record('local_duedates_assign', ['targetid' => $this->target->id, 'userid' => $userid]);
    }

    /**
     * Due day of a user.
     *
     * @param int $userid user
     * @return int
     */
    private function due(int $userid): int {
        $assign = $this->assign($userid);
        return $assign && $assign->lifecycle === 'active' ? (int) $assign->duedate : 0;
    }

    /**
     * Stores a course completion.
     *
     * @param int $userid user
     * @param int $time time
     */
    private function complete(int $userid, int $time): void {
        global $DB;
        $DB->insert_record('course_completions', ['userid' => $userid, 'course' => $this->course->id,
            'timecompleted' => $time]);
    }

    /**
     * The acceptance scenario with the rule order chosen by the project owner.
     */
    public function test_acceptance_scenario(): void {
        $first = $this->rule($this->fixed(20261218), $this->isdiv1());
        $second = $this->rule($this->afterhire(), $this->ishired());
        $third = $this->rule($this->fixed(20260630), ['type' => 'enrolment', 'operator' => 'before', 'value' => 20260601]);
        $existing = $this->user('DIV2', 20200102);
        $newhire = $this->user('DIV2', 20260810, 20260810);
        $div1late = $this->user('DIV1', 20261130, 20261101);
        $div1early = $this->user('DIV1', 20261101, 20261101);
        $div1existing = $this->user('DIV1', 20200102);
        $done = $this->user('DIV2', 20200102);
        $late = $this->user('DIV2', 20200102);
        $nobody = $this->user('DIV2', null, 20260701);
        $this->complete($done, daymap::start(20260615));
        $this->complete($late, daymap::end(20260630) + 1);
        // Enrolments that start in the future are not active, so move the clock-sensitive ones to the past.
        global $DB;
        $DB->execute('UPDATE {user_enrolments} SET timestart = ? WHERE timestart > ?', [daymap::start(20260605), time()]);
        $this->run_now();

        $this->assertSame(20260630, $this->due($existing));
        $this->assertSame(20260909, $this->due($newhire));
        $this->assertSame(20261218, $this->due($div1late));
        $this->assertSame(20261218, $this->due($div1early));
        $this->assertSame(20261218, $this->due($div1existing));
        $this->assertSame(daymap::end(20260630), (int) $this->assign($existing)->timedue);
        $now = daymap::start(20261124);
        $this->assertSame('overdue', assignment::status($this->assign($existing), 7, $now));
        $this->assertSame('notdue', assignment::status($this->assign($div1existing), 7, $now));
        $this->assertSame('completed', assignment::status($this->assign($done), 7, $now));
        $this->assertSame('completedlate', assignment::status($this->assign($late), 7, $now));
        $this->assertFalse($this->assign($nobody));

        // With the division rule last, overdue people stay overdue and new hires keep thirty days.
        manager::reorder_rules($this->target->id, [$second, $third, $first], 'existing');
        $this->run_now();
        $this->assertSame(20260630, $this->due($div1existing));
        $this->assertSame('overdue', assignment::status($this->assign($div1existing), 7, $now));
        $this->assertSame(20261201, $this->due($div1early));
        $this->assertSame(20261230, $this->due($div1late));
    }

    /**
     * Every matching rule is recorded, and the next one applies when the first stops matching.
     */
    public function test_fall_through_and_removal(): void {
        global $DB;
        $this->rule($this->fixed(20261218), $this->isdiv1());
        $this->rule($this->fixed(20270115), ['type' => 'userfield', 'field' => 'city', 'operator' => 'equals', 'value' => 'Here']);
        $user = $this->user('DIV1', null);
        $DB->set_field('user', 'city', 'Here', ['id' => $user]);
        $this->run_now();
        $this->assertSame(20261218, $this->due($user));
        $this->assertSame(2, $DB->count_records('local_duedates_cand', ['active' => 1]));
        $DB->set_field('user_info_data', 'data', 'DIV2', ['userid' => $user]);
        $this->run_now([$user]);
        $this->assertSame(20270115, $this->due($user));
        $DB->set_field('user', 'city', 'There', ['id' => $user]);
        $this->run_now();
        $this->assertSame('removed', $this->assign($user)->lifecycle);
        $this->assertSame('removed', assignment::status($this->assign($user), 7));
        $DB->set_field('user', 'city', 'Here', ['id' => $user]);
        $this->run_now();
        $this->assertSame(20270115, $this->due($user));
        $this->assertSame(4, $DB->count_records('local_duedates_history'));
        $this->assertSame(0, $DB->count_records('local_duedates_work'));
    }

    /**
     * Anchor corrections are followed or frozen; repeated runs change nothing.
     */
    public function test_freezing_and_corrections(): void {
        global $DB;
        $follow = $this->rule($this->afterhire(), $this->ishired());
        $user = $this->user(null, 20260810);
        $this->run_now();
        $this->run_now();
        $this->assertSame(1, $DB->count_records('local_duedates_history'));
        $DB->set_field('user_info_data', 'data', daymap::start(20260811), ['userid' => $user]);
        $this->run_now();
        $this->assertSame(20260910, $this->due($user));
        manager::update_rule($follow, $this->afterhire() + ['followanchor' => 0], 'existing');
        $this->run_now();
        $DB->set_field('user_info_data', 'data', daymap::start(20260901), ['userid' => $user]);
        $this->run_now();
        $this->assertSame(20260910, $this->due($user));
    }

    /**
     * Edits can apply to new assignments only.
     */
    public function test_new_only_scope(): void {
        global $DB;
        $a = $this->rule($this->fixed(20261201));
        $old = $this->user(null, null);
        $this->run_now();
        manager::update_rule($a, $this->fixed(20261224), 'new');
        $b = $this->rule($this->fixed(20270101), null, 'new');
        manager::reorder_rules($this->target->id, [$b, $a], 'new');
        $new = $this->user(null, null);
        $this->run_now();
        $this->assertSame(20261201, $this->due($old));
        $this->assertSame(20270101, $this->due($new));
        $this->assertSame(1, $DB->count_records('local_duedates_cand', ['assignid' => $this->assign($old)->id]));
        manager::update_rule($a, $this->fixed(20270202), 'existing');
        $this->run_now();
        $this->assertSame(20270202, $this->due($old));
        $this->assertSame(20270101, $this->due($new));
    }

    /**
     * Exceptions outrank rules and can be revoked.
     */
    public function test_exception(): void {
        $rule = $this->rule($this->fixed(20261201));
        $user = $this->user(null, null);
        $this->run_now();
        manager::set_exception($this->assign($user)->id, 20270105, 'Approved');
        $this->assertSame(20270105, $this->due($user));
        manager::update_rule($rule, $this->fixed(20261111), 'existing');
        $this->run_now();
        $this->assertSame(20270105, $this->due($user));
        $this->assertSame(20261111, (int) $this->assign($user)->basedue);
        manager::revoke_exception($this->assign($user)->id);
        $this->assertSame(20261111, $this->due($user));
        $this->expectException(\moodle_exception::class);
        manager::set_exception($this->assign($user)->id, 20270105, ' ');
    }

    /**
     * Completion is final.
     */
    public function test_completion_is_locked(): void {
        $rule = $this->rule($this->fixed(20261201));
        $user = $this->user(null, null);
        $this->complete($user, daymap::start(20261130));
        $this->run_now();
        manager::update_rule($rule, $this->fixed(20260101), 'existing');
        $this->run_now();
        $assign = $this->assign($user);
        $this->assertSame('completed', $assign->lifecycle);
        $this->assertEquals(20261201, $assign->duedate);
        $this->assertEquals(0, $assign->islate);
    }

    /**
     * Completions before the rule's window do not count.
     */
    public function test_completion_window(): void {
        $this->rule($this->fixed(20261201) + ['completionfrom' => daymap::start(20260101)]);
        $user = $this->user(null, null);
        $this->complete($user, daymap::start(20251201));
        $this->run_now();
        $this->assertSame('active', $this->assign($user)->lifecycle);
    }

    /**
     * Any activity type can be a target, and a failure counts only when allowed.
     *
     * @dataProvider module_provider
     * @param string $module module name
     */
    public function test_module_completion(string $module): void {
        global $DB;
        $cm = $this->getDataGenerator()->create_module($module, ['course' => $this->course->id, 'completion' => 2,
            'completionview' => 1]);
        $this->target = manager::create_target($this->course->id, $cm->cmid);
        $this->rule($this->fixed(20261201));
        $pass = $this->user(null, null);
        $fail = $this->user(null, null);
        foreach ([$pass => COMPLETION_COMPLETE_PASS, $fail => COMPLETION_COMPLETE_FAIL] as $userid => $state) {
            $DB->insert_record('course_modules_completion', ['coursemoduleid' => $cm->cmid, 'userid' => $userid,
                'completionstate' => $state, 'timemodified' => daymap::end(20261201) + 1]);
        }
        $this->run_now();
        $this->assertSame('completedlate', assignment::status($this->assign($pass), 7));
        $this->assertSame('active', $this->assign($fail)->lifecycle);
        $DB->set_field('local_duedates_target', 'countfail', 1, ['id' => $this->target->id]);
        $this->run_now();
        $this->assertSame('completed', $this->assign($fail)->lifecycle);
    }

    /**
     * Module types.
     *
     * @return array
     */
    public static function module_provider(): array {
        return ['scorm' => ['scorm'], 'assign' => ['assign']];
    }

    /**
     * Suspended users are paused, and unenrolled or deleted users lose the assignment.
     */
    public function test_suspended_unenrolled_deleted(): void {
        global $DB;
        $this->rule($this->fixed(20261201), $this->isdiv1());
        $paused = $this->user('DIV1', null);
        $left = $this->user('DIV1', null);
        $deleted = $this->user('DIV1', null);
        $this->run_now();
        $DB->set_field('user', 'suspended', 1, ['id' => $paused]);
        $DB->delete_records('user_info_data', ['userid' => $paused]);
        $DB->delete_records('user_enrolments', ['userid' => $paused]);
        $DB->delete_records('user_enrolments', ['userid' => $left]);
        delete_user($DB->get_record('user', ['id' => $deleted]));
        $this->run_now();
        $this->assertSame(20261201, $this->due($paused));
        $this->assertSame('removed', $this->assign($left)->lifecycle);
        $this->assertSame('removed', $this->assign($deleted)->lifecycle);
    }

    /**
     * A closed rule gains nobody but keeps its users; a disabled one loses them.
     */
    public function test_rule_window_and_status(): void {
        $rule = $this->rule($this->fixed(20261201));
        $old = $this->user(null, null);
        $this->run_now();
        manager::set_rule_window($rule, 0, time() - 10);
        $new = $this->user(null, null);
        $this->run_now();
        $this->assertSame(20261201, $this->due($old));
        $this->assertFalse($this->assign($new));
        manager::set_rule_status($rule, 'disabled');
        $this->run_now();
        $this->assertSame('removed', $this->assign($old)->lifecycle);
    }

    /**
     * Bad, empty, padded, negative and far future stored dates are handled.
     */
    public function test_profile_date_values(): void {
        global $DB;
        $this->rule($this->afterhire());
        $expected = ['' => 0, '0' => 0, 'rubbish' => 0, '12:34?' => 0, '0946702800' => 20000131,
            (string) daymap::start(19650301) => 19650331, (string) daymap::start(20450101) => 20450131];
        $users = [];
        foreach ($expected as $value => $due) {
            $users[$value] = $this->user(null, null);
            $DB->insert_record('user_info_data', ['userid' => $users[$value], 'fieldid' => $this->hire,
                'data' => (string) $value, 'dataformat' => 0]);
        }
        $this->run_now();
        foreach ($expected as $value => $due) {
            $this->assertSame($due, $this->due($users[$value]), "value $value");
        }
    }

    /**
     * Bounds and other anchors.
     */
    public function test_bounds_and_anchors(): void {
        $base = ['datetype' => 'relative', 'offsetn' => 1, 'offsetunit' => 'month'];
        $rule = $this->rule($base + ['anchor' => 'enrolment']);
        $user = $this->user(null, null, 20260131);
        $this->run_now();
        $this->assertSame(20260228, $this->due($user));
        manager::update_rule($rule, $base + ['anchor' => 'enrolment', 'notbefore' => 20260401], 'existing');
        $this->run_now();
        $this->assertSame(20260401, $this->due($user));
        manager::update_rule($rule, $base + ['anchor' => 'enrolment', 'notafter' => 20260215], 'existing');
        $this->run_now();
        $this->assertSame(20260215, $this->due($user));
        manager::update_rule($rule, $base + ['anchor' => 'firstmatched'], 'existing');
        $this->run_now();
        $this->assertSame(daymap::add(daymap::from_time(time()), 1, 'month'), $this->due($user));
        manager::update_rule($rule, $base + ['anchor' => 'firstaccess'], 'existing');
        $this->run_now();
        $this->assertSame('removed', $this->assign($user)->lifecycle);
    }

    /**
     * Condition operators.
     */
    public function test_conditions(): void {
        global $DB;
        $in = $this->user('DIV1', 20260601);
        $none = $this->user(null, null);
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $in);
        $div = ['type' => 'profilefield', 'fieldid' => $this->division];
        $date = ['type' => 'profilefield', 'fieldid' => $this->hire];
        $cases = [
            [$div + ['operator' => 'equals', 'value' => 'div1'], [$in]],
            [$div + ['operator' => 'notequals', 'value' => 'DIV1'], [$none]],
            [$div + ['operator' => 'contains', 'value' => 'iv'], [$in]],
            [$div + ['operator' => 'inlist', 'value' => ['DIV9', 'DIV1']], [$in]],
            [$div + ['operator' => 'empty'], [$none]],
            [$div + ['operator' => 'notempty'], [$in]],
            [$date + ['operator' => 'empty'], [$none]],
            [$date + ['operator' => 'notempty'], [$in]],
            [$date + ['operator' => 'before', 'value' => 20260601], []],
            [$date + ['operator' => 'between', 'value' => 20260601, 'value2' => 20260601], [$in]],
            [['type' => 'cohort', 'operator' => 'member', 'cohortids' => [$cohort->id]], [$in]],
            [['type' => 'cohort', 'operator' => 'notmember', 'cohortids' => [$cohort->id]], [$none]],
            [['type' => 'enrolment', 'operator' => 'enrolled'], [$in, $none]],
            [['type' => 'usercreated', 'operator' => 'onorafter', 'value' => 20200101], [$in, $none]],
            [['op' => 'or', 'items' => [$this->isdiv1(), $div + ['operator' => 'empty']]], [$in, $none]],
            [['op' => 'or', 'items' => []], []],
        ];
        foreach ($cases as $index => [$condition, $expected]) {
            $this->target = manager::create_target($this->course->id, $this->getDataGenerator()
                ->create_module('page', ['course' => $this->course->id])->cmid);
            $this->rule($this->fixed(20261201), $condition);
            $this->run_now();
            $got = $DB->get_fieldset_select('local_duedates_assign', 'userid', 'targetid = ?', [$this->target->id]);
            $this->assertEqualsCanonicalizing($expected, $got, "case $index");
        }
    }

    /**
     * The number of queries does not grow with the number of users.
     */
    public function test_set_based(): void {
        global $DB;
        $this->rule($this->fixed(20261201), $this->isdiv1());
        $this->rule($this->afterhire(), $this->ishired());
        $count = function (int $users) use ($DB): int {
            for ($i = 0; $i < $users; $i++) {
                $this->user($i % 2 ? 'DIV1' : 'DIV2', 20260810);
            }
            $before = $DB->perf_get_queries();
            $this->run_now();
            return $DB->perf_get_queries() - $before;
        };
        $count(2);
        $small = $count(2);
        $large = $count(60);
        $this->assertLessThanOrEqual($small + 1, $large);
        $this->assertEquals(64, $DB->count_records('local_duedates_assign', ['lifecycle' => 'active']));
    }

    /**
     * Calendar arithmetic across month ends and clock changes.
     */
    public function test_daymap(): void {
        $this->assertSame(20260228, daymap::add(20260131, 1, 'month'));
        $this->assertSame(20280229, daymap::add(20280131, 1, 'month'));
        $this->assertSame(20260910, daymap::add(20260903, 1, 'week'));
        $this->assertSame(23 * HOURSECS - 1, daymap::end(20260308) - daymap::start(20260308));
        $this->assertSame(25 * HOURSECS - 1, daymap::end(20261101) - daymap::start(20261101));
        $this->expectException(\moodle_exception::class);
        daymap::validate(20260230);
    }
}
