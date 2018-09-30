<?php

namespace Printed\Common\PdfTools\Cpdf\ValueObject;

use Printed\Common\PdfTools\Utils\Geometry\PlaneGeometry\Rectangle;

/**
 * Class PdfBoxesInformation
 *
 * I.e. Pdf's MediaBox, TrimBox and so on.
 */
class PdfBoxesInformation
{
    /** @var Rectangle */
    private $mediaBox;

    /** @var Rectangle|null */
    private $trimBox;

    public function __construct(Rectangle $mediaBox, Rectangle $trimBox = null)
    {
        $this->mediaBox = $mediaBox;
        $this->trimBox = $trimBox;
    }

    /**
     * @return Rectangle
     */
    public function getMediaBox()
    {
        return $this->mediaBox;
    }

    /**
     * @return Rectangle|null
     */
    public function getTrimBox()
    {
        return $this->trimBox;
    }
}
