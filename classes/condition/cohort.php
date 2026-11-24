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

namespace local_duedates\condition;

/**
 * Cohort membership condition.
 *
 * @package    local_duedates
 * @copyright  2026 local_duedates contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort extends base {
    /**
     * Human readable name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('condition_cohort', 'local_duedates');
    }

    /**
     * Throws an exception if the configuration is not usable.
     *
     * @param array $config the leaf configuration
     * @return void
     */
    public function validate(array $config): void {
        $this->operator($config, ['member', 'notmember']);
        if (empty($config['cohortids'])) {
            throw new \moodle_exception('error:badfield', 'local_duedates', '', 'cohortids');
        }
    }

    /**
     * Returns an SQL boolean expression and its parameters.
     *
     * @param array $config the leaf configuration
     * @param string $useralias alias of the user table
     * @param \stdClass $target the target record
     * @return array [sql, params]
     */
    public function get_sql(array $config, string $useralias, \stdClass $target): array {
        $op = $this->operator($config, ['member', 'notmember']);
        $ids = implode(',', array_map('intval', (array) $config['cohortids']));
        $not = $op === 'notmember' ? 'NOT ' : '';
        return ["{$not}EXISTS (SELECT 1 FROM {cohort_members} cohm
                  WHERE cohm.userid = {$useralias}.id AND cohm.cohortid IN ($ids))", []];
    }
}
