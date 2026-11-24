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
 * Custom profile field condition.
 *
 * @package    local_duedates
 * @copyright  2026 local_duedates contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profilefield extends base {
    /**
     * Human readable name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('condition_profilefield', 'local_duedates');
    }

    /** @var array operators by field data type; other types are treated as text */
    private const OPERATORS = [
        'datetime' => ['before', 'onorafter', 'between', 'empty', 'notempty'],
        'checkbox' => ['equals', 'notequals'],
        'text' => ['equals', 'notequals', 'contains', 'inlist', 'empty', 'notempty'],
    ];

    /**
     * Returns the field definition.
     *
     * @param array $config the leaf configuration
     * @return \stdClass
     */
    private function field(array $config): \stdClass {
        global $DB;
        $field = $DB->get_record('user_info_field', ['id' => (int) ($config['fieldid'] ?? 0)], 'id, datatype');
        if (!$field) {
            throw new \moodle_exception('error:badfield', 'local_duedates', '', (int) ($config['fieldid'] ?? 0));
        }
        return $field;
    }

    /**
     * Returns operators for a field type.
     *
     * @param string $datatype profile field data type
     * @return string[]
     */
    public static function get_operators(string $datatype): array {
        return self::OPERATORS[$datatype] ?? self::OPERATORS['text'];
    }

    /**
     * Throws an exception if the configuration is not usable.
     *
     * @param array $config the leaf configuration
     * @return void
     */
    public function validate(array $config): void {
        $op = $this->operator($config, self::get_operators($this->field($config)->datatype));
        $this->validate_dates($config, $op);
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
        global $DB;
        $field = $this->field($config);
        $op = $this->operator($config, self::get_operators($field->datatype));
        if ($field->datatype === 'datetime') {
            $expr = \local_duedates\local\sql::profile_timestamp((int) $field->id, $useralias);
            [$sql, $params] = $expr;
            if ($op === 'empty' || $op === 'notempty') {
                return ["($sql IS " . ($op === 'empty' ? '' : 'NOT ') . 'NULL)', $params];
            }
            // An EXISTS is used so that the expression, and its parameter, appears only once.
            [$inner, $innerparams] = \local_duedates\local\sql::numeric('uid.data');
            $test = $this->date_sql('dv.val', $config, $op);
            return ["EXISTS (SELECT 1 FROM (SELECT uid.userid, $inner AS val FROM {user_info_data} uid
                      WHERE uid.fieldid = {$field->id}) dv WHERE dv.userid = {$useralias}.id AND $test)", $innerparams];
        }
        $text = $DB->sql_compare_text('uid.data', 255);
        $exists = "EXISTS (SELECT 1 FROM {user_info_data} uid WHERE uid.userid = {$useralias}.id
                    AND uid.fieldid = {$field->id} AND ";
        if ($op === 'empty' || $op === 'notempty') {
            return [($op === 'empty' ? 'NOT ' : '') . $exists . "$text <> '')", []];
        }
        $value = $config['value'] ?? '';
        $negate = $op === 'notequals';
        if ($field->datatype === 'checkbox') {
            // A missing value means not ticked.
            $negate = $negate !== empty($value);
            $value = '1';
        }
        [$sql, $params] = $this->text_sql($text, $negate || $op === 'notequals' ? 'equals' : $op, $value);
        return [($negate ? 'NOT ' : '') . $exists . "$sql)", $params];
    }
}
