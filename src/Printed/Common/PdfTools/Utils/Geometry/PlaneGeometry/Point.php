<?php

namespace Printed\Common\PdfTools\Utils\Geometry\PlaneGeometry;

use Printed\Common\PdfTools\Utils\FuzzyFloatComparator;

/**
 * Class Point
 *
 * A point on a 2d plane
 *
 * @package Printed\PdfTools\Utils\Geometry\PlaneGeometry
 */
class Point
{
    /** @var float */
    private $x;

    /** @var float */
    private $y;


    /**
     * @param float $x
     * @param float $y
     */
    public function __construct($x, $y)
    {
        $this->x = $x;
        $this->y = $y;
    }

    /**
     * @param Point $otherPoint
     * @return bool
     */
    public function sameAs(Point $otherPoint)
    {
        return FuzzyFloatComparator::areEqual($this->x, $otherPoint->x)
            && FuzzyFloatComparator::areEqual($this->y, $otherPoint->y)
        ;
    }
}