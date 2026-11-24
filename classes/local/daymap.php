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
 * Calendar days in the site timezone, as YYYYMMDD integers.
 *
 * @package    local_duedates
 * @copyright  2026 local_duedates contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class daymap {
    /**
     * Site timezone.
     *
     * @return \DateTimeZone
     */
    public static function timezone(): \DateTimeZone {
        return \core_date::get_server_timezone_object();
    }

    /**
     * Throws an exception unless this is a real date.
     *
     * @param int $ymd date
     * @return void
     */
    public static function validate(int $ymd): void {
        if (!checkdate(intdiv($ymd, 100) % 100, $ymd % 100, intdiv($ymd, 10000))) {
            throw new \moodle_exception('error:baddate', 'local_duedates', '', $ymd);
        }
    }

    /**
     * Midnight at the start of a day.
     *
     * @param int $ymd date
     * @return \DateTimeImmutable
     */
    private static function date(int $ymd): \DateTimeImmutable {
        return \DateTimeImmutable::createFromFormat('!Ymd', sprintf('%08d', $ymd), self::timezone());
    }

    /**
     * First second of a day.
     *
     * @param int $ymd date
     * @return int
     */
    public static function start(int $ymd): int {
        return self::date($ymd)->getTimestamp();
    }

    /**
     * Last second of a day.
     *
     * @param int $ymd date
     * @return int
     */
    public static function end(int $ymd): int {
        return self::date($ymd)->modify('+1 day')->getTimestamp() - 1;
    }

    /**
     * Day containing a moment.
     *
     * @param int $time timestamp
     * @return int
     */
    public static function from_time(int $time): int {
        return (int) (new \DateTimeImmutable('@' . $time))->setTimezone(self::timezone())->format('Ymd');
    }

    /**
     * Adds a period. Month ends are clamped: 31 January plus one month is the last day of February.
     *
     * @param int $ymd date
     * @param int $n amount
     * @param string $unit day, week or month
     * @return int
     */
    public static function add(int $ymd, int $n, string $unit): int {
        $date = self::date($ymd);
        if ($unit !== 'month') {
            return (int) $date->modify(sprintf('%+d days', $unit === 'week' ? $n * 7 : $n))->format('Ymd');
        }
        $first = $date->modify('first day of this month')->modify(sprintf('%+d months', $n));
        $day = min((int) $date->format('j'), (int) $first->format('t'));
        return (int) $first->format('Ym') * 100 + $day;
    }

    /**
     * Makes sure that the table covers a range of days.
     *
     * @param int $from first day
     * @param int $to last day
     * @return void
     */
    public static function ensure(int $from, int $to): void {
        global $DB;
        $tz = self::timezone()->getName();
        if (get_config('local_duedates', 'daymaptz') !== $tz) {
            $DB->delete_records('local_duedates_daymap');
            set_config('daymaptz', $tz, 'local_duedates');
        }
        $have = $DB->get_fieldset_select('local_duedates_daymap', 'ymd', 'ymd >= ? AND ymd <= ?', [$from, $to]);
        $have = array_flip($have);
        $rows = [];
        for ($date = self::date($from); (int) $date->format('Ymd') <= $to; $date = $date->modify('+1 day')) {
            $ymd = (int) $date->format('Ymd');
            if (!isset($have[$ymd])) {
                $rows[] = ['ymd' => $ymd, 'daystart' => $date->getTimestamp(),
                    'dayend' => $date->modify('+1 day')->getTimestamp() - 1];
            }
        }
        if ($rows) {
            $DB->insert_records('local_duedates_daymap', $rows);
        }
    }
}
