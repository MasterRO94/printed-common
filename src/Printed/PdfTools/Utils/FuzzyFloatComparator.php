<?php

namespace Printed\PdfTools\Utils;


/**
 * Fuzzy float numbers comparator
 *
 * @todo Refactor: just sprintf or number_format the floats and
 *      compare the strings instead. No need for funky integer
 *      casting shit here.
 */
class FuzzyFloatComparator
{

    /**
     *
     * @param float $a
     * @param float $b
     * @param int $accuracy The abs difference between $a and $b cannot be larger
     *      than (1 / pow(10, $accuracy))
     * @return bool
     */
    public static function areEqual($a, $b, $accuracy = 4)
    {
        $epsilon = 1 / pow(10, $accuracy);

        return abs($a - $b) <= $epsilon;
    }

    /**
     * @param float $a
     * @param float $b
     * @return bool
     */
    public static function isFirstLessThanSecond($a, $b)
    {
        return $a < $b;
    }

    /**
     * @param float $a
     * @param float $b
     * @param int $accuracy
     * @return bool
     */
    public static function isFirstGreaterOrEqualSecond($a, $b, $accuracy = 4)
    {
        return !self::isFirstLessThanSecond($a, $b)
            || self::areEqual($a, $b, $accuracy)
        ;
    }

}
