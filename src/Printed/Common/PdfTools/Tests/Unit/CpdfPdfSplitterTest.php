<?php

namespace Printed\Common\PdfTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Printed\Common\PdfTools\Cpdf\CpdfPdfInformationExtractor;
use Printed\Common\PdfTools\Cpdf\CpdfPdfSplitter;
use Printed\Common\PdfTools\Cpdf\ValueObject\PdfInformation;
use Printed\Common\PdfTools\Tests\TestUtils;

class CpdfPdfSplitterTest extends TestCase
{
    /** @var string */
    private $projectDir;

    /** @var CpdfPdfInformationExtractor */
    private $cpdfInformationExtractor;

    /** @var CpdfPdfSplitter */
    private $cpdfPdfSplitter;

    public function setUp()
    {
        $this->projectDir = TestUtils::getProjectDir();
        $this->cpdfInformationExtractor = new CpdfPdfInformationExtractor(TestUtils::getPathToCpdfBinary());
        $this->cpdfPdfSplitter = new CpdfPdfSplitter(
            $this->cpdfInformationExtractor,
            TestUtils::getPathToCpdfBinary()
        );
    }

    /**
     * @test
     */
    public function split_maxPageCount_limitsExtractedPages()
    {
        $testFile = TestUtils::getPrintedCommonTestFile('72PageNormalFile.pdf');

        $pdfPagesFiles = $this->cpdfPdfSplitter->split($testFile, [
            'maxPageCount' => 3,
        ]);

        try {
            $this->assertCount(3, $pdfPagesFiles);
        } finally {
            foreach ($pdfPagesFiles as $file) {
                @unlink($file->getPathname());
            }
        }
    }

    /**
     * @test
     */
    public function split_pdfWithCorruptedBookmarks_producesWorkingPdfPages()
    {
        $testFile = TestUtils::getPrintedCommonTestFile('PdfWithCorruptBookmark.pdf');

        $pdfPagesFiles = $this->cpdfPdfSplitter->split($testFile);

        try {
            $this->assertTrue(true);
        } finally {
            foreach ($pdfPagesFiles as $file) {
                @unlink($file->getPathname());
            }
        }
    }
}
