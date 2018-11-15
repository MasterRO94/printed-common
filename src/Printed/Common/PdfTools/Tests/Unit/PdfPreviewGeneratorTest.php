<?php

namespace Printed\Common\PdfTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Printed\Common\PdfTools\BinaryPathConfiguration;
use Printed\Common\PdfTools\Cpdf\CpdfPdfInformationExtractor;
use Printed\Common\PdfTools\PdfPreviewGenerator;
use Printed\Common\PdfTools\Tests\TestUtils;
use Printed\Common\PdfTools\Utils\MeasurementConverter;
use Symfony\Component\HttpFoundation\File\File;

class PdfPreviewGeneratorTest extends TestCase
{
    /** @var PdfPreviewGenerator */
    private $pdfPreviewGenerator;

    public function setUp()
    {
        $binaryPathConfiguration = new BinaryPathConfiguration(
            'gs',
            'convert'
        );

        $cpdfInformationExtractor = new CpdfPdfInformationExtractor(TestUtils::getProjectDir());
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

        $this->expectNotToPerformAssertions();
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

        $this->expectNotToPerformAssertions();
    }
}
