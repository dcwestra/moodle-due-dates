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
 * Standard user field condition.
 *
 * @package    local_duedates
 * @copyright  2026 local_duedates contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userfield extends base {
    /**
     * Human readable name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('condition_userfield', 'local_duedates');
    }

    /** @var string[] fields that may be used */
    public const FIELDS = ['username', 'email', 'idnumber', 'institution', 'department', 'city', 'country', 'lang', 'auth',
        'firstname', 'lastname'];

    /** @var string[] permitted operators */
    private const OPERATORS = ['equals', 'notequals', 'contains', 'inlist', 'empty', 'notempty'];

    /**
     * Throws an exception if the configuration is not usable.
     *
     * @param array $config the leaf configuration
     * @return void
     */
    public function validate(array $config): void {
        $this->operator($config, self::OPERATORS);
        if (!in_array($config['field'] ?? '', self::FIELDS, true)) {
            throw new \moodle_exception('error:badfield', 'local_duedates', '', s($config['field'] ?? ''));
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
        $this->validate($config);
        $op = $config['operator'];
        $expr = "{$useralias}.{$config['field']}";
        if ($op === 'empty') {
            return ["($expr IS NULL OR $expr = '')", []];
        }
        if ($op === 'notempty') {
            return ["($expr IS NOT NULL AND $expr <> '')", []];
        }
        [$sql, $params] = $this->text_sql($expr, $op === 'notequals' ? 'equals' : $op, $config['value'] ?? '');
        return [$op === 'notequals' ? "NOT ($sql)" : $sql, $params];
    }
}
