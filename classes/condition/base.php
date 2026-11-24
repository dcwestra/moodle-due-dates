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
 * Base class for pluggable audience condition types.
 *
 * A condition returns an SQL boolean expression about one user; it never loads users into PHP.
 *
 * @package    local_duedates
 * @copyright  2026 local_duedates contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /** @var int counter used to build unique parameter names */
    private static int $counter = 0;

    /**
     * Returns an SQL boolean expression and its parameters.
     *
     * @param array $config the leaf configuration
     * @param string $useralias alias of the user table
     * @param \stdClass $target the target record
     * @return array [sql, params]
     */
    abstract public function get_sql(array $config, string $useralias, \stdClass $target): array;

    /**
     * Throws an exception if the configuration is not usable.
     *
     * @param array $config the leaf configuration
     * @return void
     */
    abstract public function validate(array $config): void;

    /**
     * Human readable name.
     *
     * @return string
     */
    abstract public function get_name(): string;

    /**
     * Returns a parameter name that is unique in this request.
     *
     * @return string
     */
    public static function param(): string {
        return 'ddp' . (++self::$counter);
    }

    /**
     * Requires a permitted operator.
     *
     * @param array $config the leaf configuration
     * @param string[] $allowed permitted operators
     * @return string
     */
    protected function operator(array $config, array $allowed): string {
        $op = $config['operator'] ?? '';
        if (!in_array($op, $allowed, true)) {
            throw new \moodle_exception('error:badoperator', 'local_duedates', '', s($op));
        }
        return $op;
    }

    /**
     * Validates the dates used by a date operator.
     *
     * @param array $config the leaf configuration
     * @param string $op operator
     * @return void
     */
    protected function validate_dates(array $config, string $op): void {
        if (in_array($op, ['before', 'onorafter', 'between'], true)) {
            \local_duedates\local\daymap::validate((int) ($config['value'] ?? 0));
        }
        if ($op === 'between') {
            \local_duedates\local\daymap::validate((int) ($config['value2'] ?? 0));
        }
    }

    /**
     * Compares an SQL timestamp expression with calendar days.
     *
     * @param string $expr SQL giving a timestamp or null
     * @param array $config the leaf configuration
     * @param string $op before, onorafter or between (inclusive)
     * @return string
     */
    protected function date_sql(string $expr, array $config, string $op): string {
        $from = \local_duedates\local\daymap::start((int) $config['value']);
        if ($op === 'before') {
            return "($expr < $from)";
        }
        if ($op === 'onorafter') {
            return "($expr >= $from)";
        }
        $to = \local_duedates\local\daymap::end((int) $config['value2']);
        return "($expr >= $from AND $expr <= $to)";
    }

    /**
     * Compares an SQL text expression.
     *
     * @param string $expr SQL text expression
     * @param string $op equals, contains or inlist
     * @param mixed $value the value or list of values
     * @return array [sql, params]
     */
    protected function text_sql(string $expr, string $op, $value): array {
        global $DB;
        if ($op === 'contains') {
            $p = self::param();
            return [$DB->sql_like($expr, ":$p", false), [$p => '%' . $DB->sql_like_escape((string) $value) . '%']];
        }
        $values = $op === 'inlist' ? array_values((array) $value) : [$value];
        $sql = [];
        $params = [];
        foreach ($values as $one) {
            $p = self::param();
            $sql[] = $DB->sql_equal($expr, ":$p", false);
            $params[$p] = (string) $one;
        }
        return $sql ? ['(' . implode(' OR ', $sql) . ')', $params] : ['1 = 0', []];
    }
}
