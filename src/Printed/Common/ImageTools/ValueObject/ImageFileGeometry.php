<?php

namespace Printed\Common\ImageTools\ValueObject;

use Printed\Common\PdfTools\Utils\Geometry\PlaneGeometry\RectangleInterface;

class ImageFileGeometry
{
    /** @var RectangleInterface */
    private $sizePx;

    /** @var RectangleInterface|null This can be null if the physical size wasn't determinable */
    private $sizeMm;

    public function __construct(RectangleInterface $sizePx, RectangleInterface $sizeMm = null)
    {
        $this->sizePx = $sizePx;
        $this->sizeMm = $sizeMm;
    }

    /**
     * @return RectangleInterface
     */
    public function getSizePx()
    {
        return $this->sizePx;
    }

    /**
     * @return int
     */
    public function getWidthPx()
    {
        return $this->sizePx->getWidth();
    }

    /**
     * @return int
     */
    public function getHeightPx()
    {
        return $this->sizePx->getHeight();
    }

    /**
     * @return null|RectangleInterface
     */
    public function getSizeMm()
    {
        return $this->sizeMm;
    }

    /**
     * @return float|null
     */
    public function getWidthMm()
    {
        return $this->sizeMm ? $this->sizeMm->getWidth() : null;
    }

    /**
     * @return float|null
     */
    public function getHeightMm()
    {
        return $this->sizeMm ? $this->sizeMm->getHeight() : null;
    }
}
