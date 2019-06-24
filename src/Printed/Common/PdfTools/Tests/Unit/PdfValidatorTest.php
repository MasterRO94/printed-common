<?php

namespace Printed\Common\PdfTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit_Framework_MockObject_MockObject as MockObject;
use Printed\Common\PdfTools\Cpdf\CpdfPdfInformationExtractor;
use Printed\Common\PdfTools\Cpdf\Exception\CpdfException;
use Printed\Common\PdfTools\PdfValidator;
use Printed\Common\PdfTools\Tests\TestUtils;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Translation\TranslatorInterface;

class PdfValidatorTest extends TestCase
{
    /** @var TranslatorInterface|MockObject */
    private $translator;

    public function setUp()
    {
        parent::setUp();

        $this->translator = $this->getMockForAbstractClass(TranslatorInterface::class);
        $this->translator->expects($this->any())
            ->method('trans')
            ->willReturnArgument(0);
    }

    /**
     * @test
     * @dataProvider dataProviderBrokenPdfs
     * @param string $pdfFilename
     * @param string $expectedErrorType
     */
    public function validateUploadedPdf_brokenPdfs_producesValidationErrors(
        $pdfFilename,
        $expectedErrorType
    ) {
        $pdfValidator = $this->createPdfValidator();
        $pdfFile = $this->getTestFile($pdfFilename);

        $errors = $pdfValidator->validatePdfFileFast($pdfFile);

        if (!$errors) {
            $this->fail(sprintf('Expected `%s` error type, but got none.', $expectedErrorType));
        }

        $error = $errors[0];

        $this->assertEquals($expectedErrorType, $error->getCode());
    }

    public function dataProviderBrokenPdfs()
    {
        return [
            ['RenamedEpsToPdf.pdf', PdfValidator::ERROR_CODE_PDF_MALFORMED_UNKNOWN_WAY ],
            ['CrashedAcrobat.pdf', PdfValidator::ERROR_CODE_PDF_MALFORMED_OPENS_WITH_WARNINGS ],
            ['PdfWithPassword123.pdf', PdfValidator::ERROR_CODE_PDF_WITH_PASSWORD ],
            ['Locked.pdf', PdfValidator::ERROR_CODE_PDF_ENCRYPTED_ENCRYPTION_USED ],
        ];
    }

    /**
     * @test
     */
    public function validateUploadedPdf_correctPdf_producesNoValidationErrors()
    {
        $editorUploadValidator = $this->createPdfValidator();
        $pdfFile = $this->getTestFile('CorrectPdf.pdf');

        $errors = $editorUploadValidator->validatePdfFileFast($pdfFile);

        $this->assertEmpty($errors);
    }

    /**
     * @test
     */
    public function validateUploadedPdf_shellUnsafePdfName_doesNotCrash()
    {
        $editorUploadValidator = $this->createPdfValidator();
        $pdfFile = $this->getTestFile('Shell Unsafe Name $(echo $(env)).pdf');

        $errors = $editorUploadValidator->validatePdfFileFast($pdfFile);

        $this->assertEmpty($errors);
    }

    /**
     * @return PdfValidator
     *
     * @throws CpdfException
     */
    private function createPdfValidator()
    {
        $cpdfBinaryPath = TestUtils::getPathToCpdfBinary();

        return new PdfValidator($this->translator, new CpdfPdfInformationExtractor($cpdfBinaryPath));
    }

    /**
     * @param string $testFileName
     * @return File
     */
    private function getTestFile($testFileName)
    {
        $projectDir = TestUtils::getProjectDir();

        /*
         * I'm not amazed with the hardcoded composer vendor subdir here. But you know, it worked at the time.
         */
        return new File("{$projectDir}/vendor/printed/common-test-files/pdf/{$testFileName}");
    }
}
