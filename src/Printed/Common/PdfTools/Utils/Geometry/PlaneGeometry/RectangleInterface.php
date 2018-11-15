<?php

namespace Printed\Common\PdfTools\Utils\Geometry\PlaneGeometry;

/**
 * Some parts of this package allows you to provide your own factory function for creating Rectangles. Your rectangles
 * need to implement this interface.
 */
interface RectangleInterface
{
    /**
     * @return int
     */
    public function getX();

    /**
     * @return int
     */
    public function getY();

    /**
     * @return int
     */
    public function getWidth();

    /**
     * @return int
     */
    public function getHeight();

    /**
     * @return string One of MeasurementConverter::UNIT_*
     */
    public function getUnits();

    /**
     * @return int
     */
    public function getRightEdge();

    /**
     * @return int
     */
    public function getBottomEdge();

    /**
     * @return int
     */
    public function getLongestSide();
}
