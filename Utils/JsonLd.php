<?php

namespace TeiEditionBundle\Utils;

/**
 *
 */
class JsonLd
{
    /** No instances */
    private function __construct() {}

    /**
     * Convert
     *
     * @param  String|\DateTime $date  date string
     * @return String        formatted date string (either YYYY or YYYY-MM-DD)
     */
    public static function formatDate8601($date)
    {
        if (is_object($date) && $date instanceof \DateTime) {
            return $date->format('Y-m-d');
        }

        if (preg_match('/^\d{4}$/', $date)) {
            // just a year
            return $date;
        }

        $ret = [];
        $parts = date_parse($date);
        foreach ([ 'year', 'month', 'day' ] as $part) {
            if (0 != $parts[$part]) {
                $ret[] = sprintf('year' == $part ? '%04d' : '%02d',
                                 $parts[$part]);
            }
        }
        if (empty($ret)) {
            return;
        }

        return count($ret) < 3 ? $ret[0] : implode('-', $ret);
    }

    /**
     * Normalize whitespace coming from XML
     * TODO: this should already be called when reading the TEI-file
     */
    public static function normalizeWhitespace($txt)
    {
        if (is_null($txt)) {
            return;
        }

        // http://stackoverflow.com/a/33980774
        return preg_replace(['(\s+)u', '(^\s|\s$)u'], [' ', ''], $txt);
    }
}
