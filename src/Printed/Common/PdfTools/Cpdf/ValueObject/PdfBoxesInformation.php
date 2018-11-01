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

    /**
     * This is useful to know whether there were pdf opening errors that cpdf managed to recover from and still read
     * the boxes information.
     *
     * @var string|null
     */
    private $cpdfErrorOutput;

    public function __construct(
        RectangleInterface $mediaBox,
        RectangleInterface $trimBox = null,
        $cpdfErrorOutput = null
    ) {
        $this->mediaBox = $mediaBox;
        $this->trimBox = $trimBox;
        $this->cpdfErrorOutput = $cpdfErrorOutput ?: null;
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

    public function getCpdfErrorOutput()
    {
        return $this->cpdfErrorOutput;
    }
}
