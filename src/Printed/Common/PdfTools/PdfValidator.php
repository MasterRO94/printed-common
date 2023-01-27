<?php

namespace Printed\Common\PdfTools;

use Printed\Common\PdfTools\Cpdf\CpdfPdfInformationExtractor;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Validator\ConstraintViolation;

class PdfValidator
{
    const ERROR_CODE_PDF_OPEN_TIMEOUT = '90bbc4b4-2959-450b-8ae1-927c49ff6748';
    const ERROR_CODE_PDF_MALFORMED_UNKNOWN_WAY = 'b9d74f7a-9d45-4254-a567-5394c73c28b1';
    const ERROR_CODE_PDF_MALFORMED_OPENS_WITH_WARNINGS = '9c2858ae-5700-44a1-8994-d68e4d282ff5';
    const ERROR_CODE_PDF_WITH_PASSWORD = '8ac7b72a-b383-40dc-9cc8-9ca937a56203';
    const ERROR_CODE_PDF_ENCRYPTED_ENCRYPTION_USED = '6cc4d9e4-4fbd-4e74-8c52-31e18baff654';
    const ERROR_CODE_PDF_ENCRYPTED_RESTRICTIVE_PERMISSIONS = '90ff6fc1-910d-4ba6-b241-531283f33adc';

    /** @var TranslatorInterface */
    private $translator;

    /** @var CpdfPdfInformationExtractor */
    private $cpdfPdfInformationExtractor;

    public function __construct(
        TranslatorInterface $translator,
        CpdfPdfInformationExtractor $cpdfPdfInformationExtractor
    ) {
        $this->translator = $translator;
        $this->cpdfPdfInformationExtractor = $cpdfPdfInformationExtractor;
    }

    /**
     * @param File $file
     * @param array $options
     * @return ConstraintViolation[]
     */
    public function validatePdfFileFast(File $file, array $options = [])
    {
        $options = array_replace_recursive([
            'messages' => [
                self::ERROR_CODE_PDF_OPEN_TIMEOUT => "Uploaded pdf's information couldn't be read.",
                self::ERROR_CODE_PDF_MALFORMED_UNKNOWN_WAY => "Uploaded pdf couldn't be opened, because it's broken in an unknown way.",
                self::ERROR_CODE_PDF_MALFORMED_OPENS_WITH_WARNINGS => "Uploaded pdf could be opened, but with errors or warnings.",
                self::ERROR_CODE_PDF_WITH_PASSWORD => "Uploaded pdf couldn't be opened, because it requires a password.",
                self::ERROR_CODE_PDF_ENCRYPTED_ENCRYPTION_USED => "Uploaded pdf could be opened, but is partially encrypted.",
                self::ERROR_CODE_PDF_ENCRYPTED_RESTRICTIVE_PERMISSIONS => "Uploaded pdf could be opened, but has restrictive permissions defined.",
            ],

            /*
             * Healthy files open instantaneously, however when slightly broken or outright broken pdfs are given, cpdf
             * tries to recover it before failing hard. This is a useful feature, but unfortunately it takes undefined time
             * to finish and it can't be disabled. This timeout is there to kill cpdf if it tries to recover a broken file
             * for too long.
             */
            'pdfOpenTimeoutSeconds' => 10,
        ], $options);

        $errorMessages = $options['messages'];

        $pdfInformation = $this->cpdfPdfInformationExtractor->readPdfInformation($file, [
            'pdfOpenTimeoutSeconds' => $options['pdfOpenTimeoutSeconds'],
        ]);

        if ($pdfInformation->couldNotOpenDueToOpenTimeout()) {
            return [
                $this->createConstraintViolation(
                    self::ERROR_CODE_PDF_OPEN_TIMEOUT,
                    $this->translator->trans($errorMessages[self::ERROR_CODE_PDF_OPEN_TIMEOUT])
                )
            ];
        }

        if ($pdfInformation->isPasswordProtected()) {
            return [
                $this->createConstraintViolation(
                    self::ERROR_CODE_PDF_WITH_PASSWORD,
                    $this->translator->trans($errorMessages[self::ERROR_CODE_PDF_WITH_PASSWORD])
                )
            ];
        }

        if ($pdfInformation->couldNotOpenDueToAnyReason()) {
            return [
                $this->createConstraintViolation(
                    self::ERROR_CODE_PDF_MALFORMED_UNKNOWN_WAY,
                    $this->translator->trans($errorMessages[self::ERROR_CODE_PDF_MALFORMED_UNKNOWN_WAY])
                )
            ];
        }

        if ($pdfInformation->isEncrypted()) {
            return [
                $this->createConstraintViolation(
                    self::ERROR_CODE_PDF_ENCRYPTED_ENCRYPTION_USED,
                    $this->translator->trans($errorMessages[self::ERROR_CODE_PDF_ENCRYPTED_ENCRYPTION_USED])
                )
            ];
        }

        if ($pdfInformation->isWithRestrictivePermissions()) {
            return [
                $this->createConstraintViolation(
                    self::ERROR_CODE_PDF_ENCRYPTED_RESTRICTIVE_PERMISSIONS,
                    $this->translator->trans($errorMessages[self::ERROR_CODE_PDF_ENCRYPTED_RESTRICTIVE_PERMISSIONS])
                )
            ];
        }

        /*
         * Note that this needs to go after encryption checks, because opening encrypted pdfs raises warnings.
         */
        if ($pdfInformation->opensWithWarnings()) {
            return [
                $this->createConstraintViolation(
                    self::ERROR_CODE_PDF_MALFORMED_OPENS_WITH_WARNINGS,
                    $this->translator->trans($errorMessages[self::ERROR_CODE_PDF_MALFORMED_OPENS_WITH_WARNINGS])
                )
            ];
        }

        return [];
    }

    /**
     * @param string $errorCode
     * @param string $message
     * @param array $errorParameters
     * @return ConstraintViolation
     */
    private function createConstraintViolation($errorCode, $message, array $errorParameters = [])
    {
        return new ConstraintViolation(
            $message,
            $message,
            $errorParameters,
            null,
            null,
            null,
            null,
            $errorCode
        );
    }
}
