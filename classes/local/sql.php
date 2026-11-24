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
 * Cross database SQL fragments. Every fragment is a scalar expression about one user.
 *
 * @package    local_duedates
 * @copyright  2026 local_duedates contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sql {
    /**
     * Name of the 64 bit integer type in a cast.
     *
     * @return string
     */
    public static function bigint(): string {
        global $DB;
        return $DB->get_dbfamily() === 'mysql' ? 'SIGNED' : 'BIGINT';
    }

    /**
     * Number held in a text column, or null when it is empty, zero or not a number.
     *
     * @param string $column column name
     * @return array [sql, params]
     */
    public static function numeric(string $column): array {
        global $DB;
        // Core's casts are too narrow: 32 bit integers or single precision floats on PostgreSQL.
        $cast = "CAST($column AS " . self::bigint() . ')';
        if (!$DB->sql_regex_supported()) {
            return ["NULLIF($cast, 0)", []];
        }
        $p = \local_duedates\condition\base::param();
        return ["(CASE WHEN $column " . $DB->sql_regex() . " :$p THEN NULLIF($cast, 0) ELSE NULL END)",
            [$p => '^-?[0-9]{1,15}$']];
    }

    /**
     * Timestamp in a date profile field.
     *
     * @param int $fieldid field id
     * @param string $useralias alias of the user table
     * @return array [sql, params]
     */
    public static function profile_timestamp(int $fieldid, string $useralias): array {
        [$sql, $params] = self::numeric('pft.data');
        return ["(SELECT $sql FROM {user_info_data} pft WHERE pft.userid = {$useralias}.id AND pft.fieldid = $fieldid)", $params];
    }

    /**
     * Earliest start of the active enrolments in a course, or null.
     *
     * @param int $courseid course id
     * @param string $useralias alias of the user table
     * @return string
     */
    public static function enrolment_start(int $courseid, string $useralias): string {
        $now = time();
        return "(SELECT MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END)
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE ue.userid = {$useralias}.id AND e.courseid = $courseid AND ue.status = 0 AND e.status = 0
                    AND ue.timestart <= $now AND (ue.timeend = 0 OR ue.timeend > $now))";
    }

    /**
     * First recorded view of the target, or null.
     *
     * Core records views only for activities with view tracking.
     *
     * @param \stdClass $target target record
     * @param string $useralias alias of the user table
     * @return string
     */
    public static function first_access(\stdClass $target, string $useralias): string {
        $where = $target->cmid ? 'cmv.coursemoduleid = ' . (int) $target->cmid
            : 'cmv.coursemoduleid IN (SELECT cm.id FROM {course_modules} cm WHERE cm.course = ' . (int) $target->courseid . ')';
        return "(SELECT MIN(cmv.timecreated) FROM {course_modules_viewed} cmv
                  WHERE cmv.userid = {$useralias}.id AND $where)";
    }
}
