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
 * Turns a stored AND/OR condition tree into SQL.
 *
 * @package    local_duedates
 * @copyright  2026 local_duedates contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tree {
    /**
     * Returns the available condition types, including those of other plugins.
     *
     * Another plugin adds a type with a class in its local_duedates\\condition namespace that extends base.
     *
     * @return base[] keyed by type name
     */
    public static function get_types(): array {
        $types = [];
        foreach (['cohort', 'enrolment', 'profilefield', 'usercreated', 'userfield'] as $name) {
            $class = __NAMESPACE__ . '\\' . $name;
            $types[$name] = new $class();
        }
        foreach (\core_component::get_component_classes_in_namespace(null, 'local_duedates\condition') as $class => $unused) {
            if (is_subclass_of($class, base::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $types[$class] = new $class();
            }
        }
        return $types;
    }

    /**
     * Returns one type.
     *
     * @param string $name type name
     * @return base
     */
    private static function type(string $name): base {
        $types = self::get_types();
        if (!isset($types[$name])) {
            throw new \moodle_exception('error:condition', 'local_duedates', '', s($name));
        }
        return $types[$name];
    }

    /**
     * Validates a tree.
     *
     * @param array $node group or leaf
     * @return void
     */
    public static function validate(array $node): void {
        if (isset($node['op'])) {
            if (!in_array($node['op'], ['and', 'or'], true)) {
                throw new \moodle_exception('error:badoperator', 'local_duedates', '', s($node['op']));
            }
            foreach ($node['items'] ?? [] as $item) {
                self::validate($item);
            }
            return;
        }
        self::type($node['type'] ?? '')->validate($node);
    }

    /**
     * Returns SQL for a tree. An empty AND matches everyone and an empty OR matches nobody.
     *
     * @param array $node group or leaf
     * @param string $useralias alias of the user table
     * @param \stdClass $target the target record
     * @return array [sql, params]
     */
    public static function get_sql(array $node, string $useralias, \stdClass $target): array {
        if (!isset($node['op'])) {
            return self::type($node['type'] ?? '')->get_sql($node, $useralias, $target);
        }
        $parts = [];
        $params = [];
        foreach ($node['items'] ?? [] as $item) {
            [$sql, $itemparams] = self::get_sql($item, $useralias, $target);
            $parts[] = "($sql)";
            $params += $itemparams;
        }
        if (!$parts) {
            return [$node['op'] === 'and' ? '1 = 1' : '1 = 0', []];
        }
        return ['(' . implode($node['op'] === 'and' ? ' AND ' : ' OR ', $parts) . ')', $params];
    }
}
