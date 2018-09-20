<?php

namespace Printed\PdfTools\Utils\Geometry;

use Printed\PdfTools\Utils\MeasurementConverter;
use Printed\PdfTools\Utils\Geometry\Enums\Orientation;
use Printed\PdfTools\Utils\FuzzyFloatComparator;

/**
 * @todo We should use some proper library for it instead (see packagist)
 */
class RectangularShape
{

    /** @var float */
    private $x;
    
    /** @var float */
    private $y;
    
    /** @var string One of MeasurementConverter::UNIT_ consts */
    private $units;

    public function __construct($x, $y, $units = null)
    {
        $this->x = (float) $x;
        $this->y = (float) $y;
        $this->units = $units ?: MeasurementConverter::UNIT_MM;

        $this->isValid();
    }

    public function isValid()
    {
        if ($this->x <= 0 || $this->y <= 0) {
            throw new GeometryException(
                "Dimensions must not be negative or zero",
                GeometryException::CODE_DIMENSIONS_MUST_BE_POSITIVE
            );
        }
    }

    /**
     *
     * Only landscape and portrait orientations are supported. Any other are
     * treated as Portrait
     *
     * @param string $orientation One of Orientation:: consts
     * @return RectangularShape
     */
    public function createSelfForOrientation($orientation)
    {
        return $this->createForOrientation($this->x, $this->y, $this->units, $orientation);
    }

    /**
     * 
     * @return string One of Orientation:: consts
     */
    public function getOrientation()
    {
        if ($this->isSquare()) {
            return Orientation::SQUARE;
        } elseif ($this->x > $this->y) {
            return Orientation::LANDSCAPE;
        } else {
            return Orientation::PORTRAIT;
        }
    }

    public function isSquare()
    {
        return FuzzyFloatComparator::areEqual($this->x, $this->y);
    }

    public function getWidth($units = null)
    {
        return $units
            ? MeasurementConverter::getConversion($this->x, $this->units, $units)
            : $this->x
        ;
    }

    public function getHeight($units = null)
    {
        return $units
            ? MeasurementConverter::getConversion($this->y, $this->units, $units)
            : $this->y
        ;
    }

    public function getMeasurementUnits()
    {
        return $this->units;
    }

    /**
     * Create self for inverted (i.e. rotated by 90 degrees) orientation.
     *
     * @return RectangularShape
     */
    public function createSelfForInvertedOrientation()
    {
        return $this->createSelfForOrientation(
            $this->getOrientation() === Orientation::LANDSCAPE
                ? Orientation::PORTRAIT
                : Orientation::LANDSCAPE
        );
    }

    /**
     * Create rectangular shape with specified orientation
     * 
     * @param int $x
     * @param int $y
     * @param int $units
     * @param string $orientation One of Enums\Orientation:: consts
     * @return RectangularShape
     */
    public static function createForOrientation($x, $y, $units, $orientation)
    {
        if ($orientation === Orientation::LANDSCAPE) {
            if ($x > $y) {
                return new self($x, $y, $units);
            } else {
                return new self($y, $x, $units);
            }
        } else {
            if ($x > $y) {
                return new self($y, $x, $units);
            } else {
                return new self($x, $y, $units);
            }
        }
    }

    /**
     * 
     * @todo Take into account the units
     *
     * @param RectangularShape $targetShape
     * @param string $resizeStrategy Const from Enums\ResizeStrategy
     */
    public function resizeTo(RectangularShape $targetShape, $resizeStrategy)
    {
        // currently always using FIT_WITHIN strategy

        $scaleRatioOnWidth = $targetShape->getWidth() / $this->x;
        $scaleRatioOnHeight = $targetShape->getHeight() / $this->y;

        $scaleRatio = $scaleRatioOnWidth < $scaleRatioOnHeight
            ? $scaleRatioOnWidth
            : $scaleRatioOnHeight
        ;

        $this->scale($scaleRatio);
    }

    /**
     * 
     * @param float $scaleRatio
     */
    public function scale($scaleRatio)
    {
        $this->x *= $scaleRatio;
        $this->y *= $scaleRatio;
    }

    /**
     * @todo Make units-aware
     * @param RectangularShape $other
     * @return bool
     */
    public function isSmallerThan(RectangularShape $other)
    {
        $thisArea = $this->getArea();
        $otherArea = $other->getArea();

        if (FuzzyFloatComparator::areEqual($thisArea, $otherArea)) {
            return false;
        }

        if ($thisArea < $otherArea) {
            return true;
        }

        return false;
    }

    /**
     * @todo Make units-aware
     * @param RectangularShape $other
     * @return bool
     */
    public function contains(RectangularShape $other)
    {
        $thisLandscape = $this->createSelfForOrientation(Orientation::LANDSCAPE);
        $otherLandscape = $other->createSelfForOrientation(Orientation::LANDSCAPE);

        return
            $thisLandscape->getWidth() >= $otherLandscape->getWidth()
            && $thisLandscape->getHeight() >= $otherLandscape->getHeight()
        ;
    }

    public function getArea()
    {
        return $this->x * $this->y;
    }
}
