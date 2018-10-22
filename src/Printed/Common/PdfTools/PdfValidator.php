<?php

namespace Printed\Common\PdfTools;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\ConstraintViolation;

class PdfValidator
{
    const ERROR_CODE_PDF_MALFORMED_UNKNOWN_WAY = 'b9d74f7a-9d45-4254-a567-5394c73c28b1';
    const ERROR_CODE_PDF_MALFORMED_OPENS_WITH_WARNINGS = '9c2858ae-5700-44a1-8994-d68e4d282ff5';
    const ERROR_CODE_PDF_WITH_PASSWORD = '8ac7b72a-b383-40dc-9cc8-9ca937a56203';
    const ERROR_CODE_PDF_ENCRYPTED_ENCRYPTION_USED = '6cc4d9e4-4fbd-4e74-8c52-31e18baff654';
    const ERROR_CODE_PDF_ENCRYPTED_RESTRICTIVE_PERMISSIONS = '90ff6fc1-910d-4ba6-b241-531283f33adc';

    /** @var TranslatorInterface */
    private $translator;

    /** @var string e.g. /var/www/my-php-project/vendor/bin */
    private $vendorBinDir;

    public function __construct(
        TranslatorInterface $translator,
        $vendorBinDir
    ) {
        $this->translator = $translator;
        $this->vendorBinDir = $vendorBinDir;
    }

    /**
     * @param File $file
     * @param array $errorMessages
     * @return ConstraintViolation[]
     */
    public function validatePdfFileFast(File $file, array $errorMessages = [])
    {
        $errorMessages = array_merge([
            self::ERROR_CODE_PDF_MALFORMED_UNKNOWN_WAY => "Uploaded pdf couldn't be opened, because it's broken in an unknown way.",
            self::ERROR_CODE_PDF_MALFORMED_OPENS_WITH_WARNINGS => "Uploaded pdf could be opened, but with errors or warnings.",
            self::ERROR_CODE_PDF_WITH_PASSWORD => "Uploaded pdf couldn't be opened, because it requires a password.",
            self::ERROR_CODE_PDF_ENCRYPTED_ENCRYPTION_USED => "Uploaded pdf could be opened, but is partially encrypted.",
            self::ERROR_CODE_PDF_ENCRYPTED_RESTRICTIVE_PERMISSIONS => "Uploaded pdf could be opened, but has restrictive permissions defined.",
        ], $errorMessages);

        /*
         * Perform fast checks with `cpdf -info`. This will catch obvious problems like: file
         * is encrypted (with password or with restrictive permissions) or file is malformed
         */

        $cpdfProcess = new Process(
            sprintf('cpdf -info -i %s', escapeshellarg($file->getPathname())),
            $this->vendorBinDir,
            null,
            null,
            /*
             * Give it 10 seconds tops. This shouldn't take that long. Healthy files should take less than
             * a second to get processed, regardless of their size.
             */
            10
        );

        $cpdfProcess->run();

        /*
         * 1. Check exit code, that says, that pdf is with password.
         * 2. Gracefully check the stdout.
         * 3. Check the exit code and the stderr.
         * 4. Check the stdout again, but this time crash if there are missing lines.
         */

        if ($cpdfProcess->getExitCode() === 1) {
            return [
                $this->createConstraintViolation(
                    self::ERROR_CODE_PDF_WITH_PASSWORD,
                    $this->translator->trans($errorMessages[self::ERROR_CODE_PDF_WITH_PASSWORD])
                )
            ];
        }

        $validationErrors = $this->checkCpdfStdOutput($cpdfProcess, $errorMessages, [ 'gracefully' => true ]);
        if ($validationErrors) {
            return $validationErrors;
        }

        $validationErrors = $this->checkCpdfExitCodeAndStdError($cpdfProcess, $errorMessages);
        if ($validationErrors) {
            return $validationErrors;
        }

        $validationErrors = $this->checkCpdfStdOutput($cpdfProcess, $errorMessages);
        if ($validationErrors) {
            return $validationErrors;
        }

        return [];
    }

    /**
     * @param Process $cpdfProcess
     * @param array $errorMessages
     * @param array $options
     * @return ConstraintViolation[]
     */
    private function checkCpdfStdOutput(Process $cpdfProcess, array $errorMessages, array $options = [])
    {
        $options = array_merge([
            'gracefully' => false,
        ], $options);

        $gracefulReturnOrThrowFn = function (\Exception $exception) use ($options) {
            if ($options['gracefully']) {
                return [];
            }

            throw $exception;
        };

        /*
         * Regexp the output. Fuck yeah. Everyday is a regexp day.
         *
         * I expect this output:
         *
         *  Encryption: 128bit AES, Metadata encrypted
         *  Permissions: No assemble, No copy, No edit
         *  Linearized: true
         *  Version: 1.7
         *  Pages: 1
         *  (more lines ...)
         */
        $outputLines = explode(PHP_EOL, $cpdfProcess->getOutput());

        if (count($outputLines) === 0) {
            return $gracefulReturnOrThrowFn(new \RuntimeException('`Cpdf -info` produced no output'));
        }

        $encryptionLine = isset($outputLines[0]) ? $outputLines[0] : null;

        if (!$encryptionLine || strpos($encryptionLine, 'Encryption:') !== 0) {
            return $gracefulReturnOrThrowFn(new \RuntimeException("'`Cpdf -info` didn't produce the encryption line as the first line"));
        }

        preg_match('/^Encryption:(.*)/', $encryptionLine, $regexpMatches);
        if (trim($regexpMatches[1]) !== 'Not encrypted') {
            return [
                $this->createConstraintViolation(
                    self::ERROR_CODE_PDF_ENCRYPTED_ENCRYPTION_USED,
                    $this->translator->trans($errorMessages[self::ERROR_CODE_PDF_ENCRYPTED_ENCRYPTION_USED])
                )
            ];
        }

        $permissionsLine = isset($outputLines[1]) ? $outputLines[1] : null;

        if (!$permissionsLine || strpos($permissionsLine, 'Permissions:') !== 0) {
            return $gracefulReturnOrThrowFn(new \RuntimeException("'`Cpdf -info` didn't produce the permissions line as the second line"));
        }

        preg_match('/^Permissions:(.*)/', $permissionsLine, $regexpMatches);
        if (trim($regexpMatches[1]) !== '') {
            return [
                $this->createConstraintViolation(
                    self::ERROR_CODE_PDF_ENCRYPTED_RESTRICTIVE_PERMISSIONS,
                    $this->translator->trans($errorMessages[self::ERROR_CODE_PDF_ENCRYPTED_RESTRICTIVE_PERMISSIONS])
                )
            ];
        }

        return [];
    }

    /**
     * @param Process $cpdfProcess
     * @param array $errorMessages
     * @return ConstraintViolation[]
     */
    private function checkCpdfExitCodeAndStdError(Process $cpdfProcess, array $errorMessages)
    {
        /*
        * The fact, that cpdf uses exit code 1 for invalid password and 2 for indicating, that the
        * pdf is malformed will bite me in the ass, because status codes 1 and 2 are used for something
        * different in bash.
        *
        * 1: Catchall for general errors
        * 2: Misuse of shell builtins (according to Bash documentation)
        */
        if ($cpdfProcess->getExitCode() === 2) {
            return [
                $this->createConstraintViolation(
                    self::ERROR_CODE_PDF_MALFORMED_UNKNOWN_WAY,
                    $this->translator->trans($errorMessages[self::ERROR_CODE_PDF_MALFORMED_UNKNOWN_WAY])
                )
            ];
        }

        if ($cpdfProcess->getExitCode() !== 0) {
            throw new \RuntimeException('Cpdf unexpectedly finished with exit code other than 0, 1 or 2');
        }

        if ($cpdfProcess->getErrorOutput()) {
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
