<?php

namespace Printed\Common\PdfTools\Cpdf\ValueObject;

use Printed\Common\PdfTools\Utils\Geometry\PlaneGeometry\RectangleInterface;

/**
 * Class PdfBoxesInformation
 *
 * I.e. Pdf's MediaBox, TrimBox and so on.
 */
class PdfBoxesInformation
{
    /** @var RectangleInterface */
    private $mediaBox;

    /** @var RectangleInterface|null */
    private $trimBox;

    public function __construct(RectangleInterface $mediaBox, RectangleInterface $trimBox = null)
    {
        $this->mediaBox = $mediaBox;
        $this->trimBox = $trimBox;
    }

    /**
     * @return RectangleInterface
     */
    public function getMediaBox()
    {
        return $this->mediaBox;
    }

    /**
     * @return RectangleInterface|null
     */
    public function getTrimBox()
    {
        return $this->trimBox;
    }
}
