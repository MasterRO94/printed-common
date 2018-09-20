<?php

namespace Printed\PdfTools\Utils\Geometry;


/**
 * A rectangular shape which has an offset in a relation to a parent rectangular shape
 */
class OffsetRectangularShape
{
    /** @var RectangularShape */
    private $shape;

    /** @var int */
    private $offsetTopLeftX;

    /** @var int */
    private $offsetTopLeftY;


    public function __construct(RectangularShape $shape)
    {
        $this->shape = $shape;
        $this->offsetTopLeftX = 0;
        $this->offsetTopLeftY = 0;
    }

    public function placeInCenterOf(RectangularShape $otherShape)
    {
        // find top left corner for centralized crop (+ make sure ints are produced)
        // Pro tip: Formula: z = (y - x) / 2
        $this->offsetTopLeftX = round(($otherShape->getWidth() - $this->shape->getWidth()) / 2);
        $this->offsetTopLeftY = round(($otherShape->getHeight() - $this->shape->getHeight()) / 2);
    }

    public function getTopLeftXOffset()
    {
        return $this->offsetTopLeftX;
    }

    public function getTopLeftYOffset()
    {
        return $this->offsetTopLeftY;
    }

}