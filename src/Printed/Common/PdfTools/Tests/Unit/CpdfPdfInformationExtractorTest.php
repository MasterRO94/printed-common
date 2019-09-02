<?php

namespace Printed\Common\PdfTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Printed\Common\PdfTools\Cpdf\CpdfPdfInformationExtractor;
use Printed\Common\PdfTools\Cpdf\ValueObject\PdfInformation;
use Printed\Common\PdfTools\Tests\TestUtils;

class CpdfPdfInformationExtractorTest extends TestCase
{
    /** @var string */
    private $projectDir;

    /** @var CpdfPdfInformationExtractor */
    private $cpdfInformationExtractor;

    public function setUp()
    {
        $this->projectDir = TestUtils::getProjectDir();
        $this->cpdfInformationExtractor = new CpdfPdfInformationExtractor(TestUtils::getPathToCpdfBinary());
    }

    public function produceTestCasesForReadPdfInformation()
    {
        return [
            0 => [
                'CorrectPdf.pdf', // test file
                function (PdfInformation $pdfInformation) {
                    $this->assertFalse($pdfInformation->couldNotOpenDueToAnyReason());
                    $this->assertFalse($pdfInformation->isEncrypted());
                    $this->assertFalse($pdfInformation->isWithRestrictivePermissions());
                    $this->assertNotNull($pdfInformation->getPagesCount());
                } // result assertions
            ],
            1 => [
                'RenamedEpsToPdf.pdf',
                function (PdfInformation $pdfInformation) {
                    $this->assertTrue($pdfInformation->couldNotOpenDueToBrokenFile());
                }
            ],
            2 => [
                'CrashedAcrobat.pdf',
                function (PdfInformation $pdfInformation) {
                    $this->assertFalse($pdfInformation->couldNotOpenDueToAnyReason());
                    $this->assertTrue($pdfInformation->opensWithWarnings());
                }
            ],
            3 => [
                'PdfWithPassword123.pdf',
                function (PdfInformation $pdfInformation) {
                    $this->assertTrue($pdfInformation->isPasswordProtected());
                }
            ],
            4 => [
                'Locked.pdf',
                function (PdfInformation $pdfInformation) {
                    $this->assertTrue($pdfInformation->isEncrypted());
                    $this->assertTrue($pdfInformation->isWithRestrictivePermissions());
                }
            ],
        ];
    }

    /**
     * @dataProvider produceTestCasesForReadPdfInformation
     * @test
     *
     * @param string $testFileName
     * @param callable $resultAssertionsFn
     */
    public function readPdfInformation_useCases_produceExpectedResult(
        $testFileName,
        callable $resultAssertionsFn
    ) {
        $testFile = TestUtils::getPrintedCommonTestFile($testFileName);

        $pdfInformation = $this->cpdfInformationExtractor->readPdfInformation($testFile);

        $resultAssertionsFn($pdfInformation);
    }

    /**
     * @test
     */
    public function readPdfInformation_shellUnsafePdfName_doesNotCrash()
    {
        $testFile = TestUtils::getPrintedCommonTestFile('Shell Unsafe Name $(echo $(env)).pdf');

        $this->cpdfInformationExtractor->readPdfInformation($testFile);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @test
     */
    public function readPdfBoxesInformationOfPageInFile_nonZeroMediaBox_trimBoxAligned()
    {
        $testFile = TestUtils::getPrintedCommonTestFile('MediaBoxNonZeroBleed3mm.pdf');

        $pdfBoxesInformation = $this->cpdfInformationExtractor->readPdfBoxesInformationOfPageInFile($testFile, 1);

        $threeMmInPt = 8.5;

        $this->assertEquals($threeMmInPt, $pdfBoxesInformation->getTrimBox()->getX(), '', 1);
        $this->assertEquals($threeMmInPt, $pdfBoxesInformation->getTrimBox()->getY(), '', 1);
    }
}
