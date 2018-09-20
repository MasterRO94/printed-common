<?php

namespace Printed\PdfTools\Utils\Geometry\PlaneGeometry;

use Printed\PdfTools\Utils\FuzzyFloatComparator;
use Printed\PdfTools\Utils\MeasurementConverter;

/**
 * Class Rectangle
 *
 * Rectangle on a 2d plane.
 *
 * It's down to you where you define the origin point. Also
 * it's down to you to ensure, that when working with many rectangles,
 * all of them has the same origin point. @todo Maybe that needs fixing
 *
 *
 * @package Printed\PdfTools\Utils\Geometry\PlaneGeometry
 */
class Rectangle
{
    /** @var float */
    private $x;

    /** @var float */
    private $y;

    /** @var float */
    private $width;

    /** @var float */
    private $height;

    /** @var string One of MeasurementConverter::UNIT_**/
    private $units;


    public function __construct($x, $y, $width, $height, $units = MeasurementConverter::UNIT_NO_UNIT)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = $width;
        $this->height = $height;
        $this->units = $units;
    }

    /**
     * @param array $llAndUrPoints Of structure:
     *      [llx: float, lly: float, urx: float, ury: float]
     * @param string $units One of MeasurementConverter::UNIT_*
     * @return Rectangle
     */
    public static function createFromLowerLeftAndUpperRightPointsInArray(
        array $llAndUrPoints, $units = MeasurementConverter::UNIT_NO_UNIT
    ) {
        return new self(
            $llAndUrPoints[0],
            $llAndUrPoints[1],
            $llAndUrPoints[2] - $llAndUrPoints[0],
            $llAndUrPoints[3] - $llAndUrPoints[1],
            $units
        );
    }

    /**
     * @return float
     */
    public function getX()
    {
        return $this->x;
    }

    /**
     * @return float
     */
    public function getY()
    {
        return $this->y;
    }

    /**
     * @return float
     */
    public function getWidth()
    {
        return $this->width;
    }

    /**
     * @return float
     */
    public function getHeight()
    {
        return $this->height;
    }

    public function getRightEdge()
    {
        return $this->x + $this->width;
    }

    public function getBottomEdge()
    {
        return $this->y + $this->height;
    }

    public function getLongestSide()
    {
        return max($this->width, $this->height);
    }

    /**
     * @return string
     */
    public function getUnits()
    {
        return $this->units;
    }

    /**
     * Is this rectangle the same as an another.
     *
     * It must be the same size and position on the plane.
     *
     * @param Rectangle $otherRectangle
     * @return bool
     */
    public function sameAs(Rectangle $otherRectangle)
    {
        $this->assertSameUnits($otherRectangle);

        return FuzzyFloatComparator::areEqual($this->x, $otherRectangle->x)
            && FuzzyFloatComparator::areEqual($this->y, $otherRectangle->y)
            && FuzzyFloatComparator::areEqual($this->width, $otherRectangle->width)
            && FuzzyFloatComparator::areEqual($this->height, $otherRectangle->height)
        ;
    }

    /**
     * Is this rectangle centered in another one?
     *
     * Center points of two rectangles must be at the same point on the plane.
     *
     * @param Rectangle $otherRectangle
     * @return bool
     */
    public function isCenteredIn(Rectangle $otherRectangle)
    {
        return $this->getCenterPoint()->sameAs($otherRectangle->getCenterPoint());
    }

    /**
     * Get center point of this rectangle
     *
     * @return Point
     */
    public function getCenterPoint()
    {
        return new Point(
            $this->x + $this->width / 2,
            $this->y + $this->height / 2
        );
    }

    /**
     * @param Rectangle $other
     * @param float $offsetValue
     * @return bool
     */
    public function isInsideOtherAndOffsetFromAllEdgesAtLeastBy(
        Rectangle $other, $offsetValue
    ) {
        $this->assertSameUnits($other);

        return FuzzyFloatComparator::isFirstGreaterOrEqualSecond(
                $this->x - $other->x, $offsetValue, 2
            )
            && FuzzyFloatComparator::isFirstGreaterOrEqualSecond(
                $this->y - $other->y, $offsetValue, 2
            )
            && FuzzyFloatComparator::isFirstGreaterOrEqualSecond(
                $other->getRightEdge() - $this->getRightEdge(), $offsetValue, 2
            )
            && FuzzyFloatComparator::isFirstGreaterOrEqualSecond(
                $other->getBottomEdge() - $this->getBottomEdge(), $offsetValue, 2
            )
        ;
    }

    public function getWidthToHeightAspectRatio()
    {
        return $this->width / $this->height;
    }

    /**
     * Throw, if this rectangle's units are different than other rectangle's
     *
     * @param Rectangle $otherRectangle
     */
    private function assertSameUnits(Rectangle $otherRectangle)
    {
        if ($this->units !== $otherRectangle->units) {
            throw new \DomainException('Two rectangles are expressed in different units');
        }
    }

}
