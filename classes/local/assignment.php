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
 * Reads assignments.
 *
 * @package    local_duedates
 * @copyright  2026 local_duedates contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment {
    /**
     * Derives the status. It is not stored, so it cannot become stale.
     *
     * @param \stdClass $assign assignment record
     * @param int $duesoondays days before the due date that count as due soon
     * @param int|null $now current time
     * @return string notdue, duesoon, overdue, completed, completedlate, removed or superseded
     */
    public static function status(\stdClass $assign, int $duesoondays, ?int $now = null): string {
        $now = $now ?? time();
        if ($assign->lifecycle === 'completed') {
            return $assign->islate ? 'completedlate' : 'completed';
        }
        if ($assign->lifecycle === 'superseded') {
            return 'superseded';
        }
        if ($assign->lifecycle !== 'active') {
            return 'removed';
        }
        if ($assign->timedue < $now) {
            return 'overdue';
        }
        return $assign->duedate <= daymap::add(daymap::from_time($now), $duesoondays, 'day') ? 'duesoon' : 'notdue';
    }
}
