<?php

namespace Printed\Common\PdfTools\ValueObject;

use Printed\Common\PdfTools\Cpdf\ValueObject\PdfBoxesInformation;
use Symfony\Component\HttpFoundation\File\File;

class PreviewFileAndPagePdfBoxesInformation
{
    /** @var File */
    private $previewFile;

    /** @var PdfBoxesInformation */
    private $pdfBoxesInformation;

    public function __construct(File $previewFile, PdfBoxesInformation $pdfBoxesInformation)
    {
        $this->previewFile = $previewFile;
        $this->pdfBoxesInformation = $pdfBoxesInformation;
    }

    /**
     * @return File
     */
    public function getPreviewFile()
    {
        return $this->previewFile;
    }

    /**
     * @return PdfBoxesInformation
     */
    public function getPdfBoxesInformation()
    {
        return $this->pdfBoxesInformation;
    }
}
