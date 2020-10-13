<?php

namespace Printed\Common\ImageTools\Enum;

/**
 * ImageMagick orientation values as outputted by the "identify -format" cli command.
 *
 * A thorough explanation with pictures: https://stackoverflow.com/a/40055711/2282328
 */
class ImageMagickImageOrientation
{
    const UNDEFINED = 'Undefined';
    const TOP_LEFT = 'TopLeft';
    const TOP_RIGHT = 'TopRight';
    const BOTTOM_RIGHT = 'BottomRight';
    const BOTTOM_LEFT = 'BottomLeft';
    const LEFT_TOP = 'LeftTop';
    const RIGHT_TOP = 'RightTop';
    const RIGHT_BOTTOM = 'RightBottom';
    const LEFT_BOTTOM = 'LeftBottom';
    const UNRECOGNIZED = 'Unrecognized';

    /**
     * Is an orientation a multiple of 90 degrees.
     *
     * @param string $orientation
     * @return bool
     */
    public static function isOrientationSideWays($orientation)
    {
        return in_array($orientation, [
            self::LEFT_TOP,
            self::RIGHT_TOP,
            self::RIGHT_BOTTOM,
            self::LEFT_BOTTOM,
        ]);
    }
}