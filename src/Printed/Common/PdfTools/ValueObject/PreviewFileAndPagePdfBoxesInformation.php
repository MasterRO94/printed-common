<?php

namespace Printed\Common\PdfTools\ValueObject;

use Printed\Common\PdfTools\Cpdf\ValueObject\PdfBoxesInformation;
use Symfony\Component\HttpFoundation\File\File;

class PreviewFileAndPagePdfBoxesInformation
{
    /** @var PdfBoxesInformation */
    private $pdfBoxesInformation;

    /** @var File|null */
    private $previewFile;

    /** @var \Exception|null */
    private $previewProcessException;

    private function __construct(
        PdfBoxesInformation $pdfBoxesInformation,
        File $previewFile = null,
        \Exception $previewProcessException = null
    ) {
        $this->pdfBoxesInformation = $pdfBoxesInformation;
        $this->previewFile = $previewFile;
        $this->previewProcessException = $previewProcessException;
    }

    static function createForSuccessfulPreview(PdfBoxesInformation $pdfBoxesInformation, File $previewFile)
    {
        return new self($pdfBoxesInformation, $previewFile);
    }

    static function createForFailedPreview(PdfBoxesInformation $pdfBoxesInformation, \Exception $previewProcessException)
    {
        return new self($pdfBoxesInformation, null, $previewProcessException);
    }

    /**
     * @return PdfBoxesInformation
     */
    public function getPdfBoxesInformation()
    {
        return $this->pdfBoxesInformation;
    }

    /**
     * @return File|null
     */
    public function getPreviewFile()
    {
        return $this->previewFile;
    }

    /**
     * @return \Exception|null
     */
    public function getPreviewProcessException()
    {
        return $this->previewProcessException;
    }
}
