<?php

namespace Printed\PdfTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Printed\PdfTools\BinaryPathConfiguration;
use Printed\PdfTools\Cpdf\CpdfPdfInformationExtractor;
use Printed\PdfTools\PdfPreviewGenerator;
use Printed\PdfTools\Utils\MeasurementConverter;
use Symfony\Component\HttpFoundation\File\File;

class PdfPreviewGeneratorTest extends TestCase
{
    /** @var PdfPreviewGenerator */
    private $pdfPreviewGenerator;

    public function setUp()
    {
        $binaryPathConfiguration = new BinaryPathConfiguration(
            '/usr/local/bin/gs',
            '/usr/local/bin/convert'
        );

        $cpdfInformationExtractor = new CpdfPdfInformationExtractor(__DIR__ . '/../../../../../');
        $measurementConverter = new MeasurementConverter();

        $this->pdfPreviewGenerator = new PdfPreviewGenerator(
            $binaryPathConfiguration,
            $cpdfInformationExtractor,
            $measurementConverter
        );
    }

    /**
     * @test
     */
    public function generatesAFirstPagePreview()
    {
        $file = new File(__DIR__ . '/fixtures/48ec3913-d1d9-4964-b73d-071366173406.pdf');
        $outputFile = new File(__DIR__ . '/fixtures/48ec3913-d1d9-4964-b73d-071366173406.png', false);

        $this->pdfPreviewGenerator->generateFirstPagePreview(
            $file,
            $outputFile,
            [
                'previewSizePx' => 600,
                'timeout' => 5
            ]
        );

//        unlink($outputFile->getPathname());
    }

    /**
     * @test
     */
    public function generatesPreviewsForAllPages()
    {
        $file = new File(__DIR__ . '/fixtures/48ec3913-d1d9-4964-b73d-071366173406.pdf');
        $outputFilePath = __DIR__ . '/fixtures/';

        $this->pdfPreviewGenerator->generatePagePreviews(
            $file,
            $outputFilePath,
            [
                'previewSizePx' => 600,
                'timeout' => 5
            ]
        );
    }
}
