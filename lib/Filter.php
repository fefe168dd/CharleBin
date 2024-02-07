<?php
/**
 * PrivateBin
 *
 * a zero-knowledge paste bin
 *
 * @link      https://github.com/PrivateBin/PrivateBin
 * @copyright 2012 Sébastien SAUVAGE (sebsauvage.net)
 * @license   https://www.opensource.org/licenses/zlib-license.php The zlib/libpng License
 * @version   1.5.1
 */

namespace PrivateBin;

use Exception;

/**
 * Filter
 *
 * Provides data filtering functions.
 */
class Filter
{
    /**
     * format a given time string into a human readable label (localized)
     *
     * accepts times in the format "[integer][time unit]"
     *
     * @access public
     * @static
     * @param string $time
     * @return string
     * @throws Exception
     */
    public static function formatHumanReadableTime($time)
    {
        if (preg_match('/^(\d+) *(\w+)$/', $time, $matches) !== 1) {
            throw new Exception("Error parsing time format '$time'", 30);
        }
        $unit = match ($matches[2]) {
            'sec' => 'second',
            'min' => 'minute',
            default => rtrim($matches[2], 's'),
        };
        return I18n::_(['%d ' . $unit, '%d ' . $unit . 's'], (int) $matches[1]);
    }

    /**
     * format a given number of bytes in IEC 80000-13:2008 notation (localized)
     *
     * @access public
     * @static
     * @param int $size
     * @return string
     */
    public static function formatHumanReadableSize($size)
    {
        $iec = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'];
        $i = 0;
        while (($size / 1024) >= 1) {
            $size = $size / 1024;
            ++$i;
        }
        return number_format($size, ($i ? 2 : 0), '.', ' ') . ' ' . I18n::_($iec[$i]);
    }
}
