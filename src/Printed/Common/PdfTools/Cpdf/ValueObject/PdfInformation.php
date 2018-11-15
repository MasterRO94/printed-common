<?php

namespace Printed\Common\PdfTools\Cpdf\ValueObject;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PdfInformation
{
    /** @var File */
    private $file;

    /** @var Process */
    private $finishedCpdfInfoProcess;

    /** @var array */
    private $openingErrors;

    /** @var array */
    private $pdfInformation;

    public function __construct(
        File $file,
        Process $finishedCpdfInfoProcess,
        array $openingErrors,
        array $pdfInformation = []
    ) {
        $openingErrors = array_merge([
            'openOperationTimeout' => false,
            'brokenPdfFile' => false,
            'isPasswordSecured' => false,
        ], $openingErrors);

        $pdfInformation = array_merge([
            /*
             * "null"s mean that the information was not determinable due to previous errors
             */
            'opensWithWarnings' => null,
            'encrypted' => null,
            'withRestrictivePermissions' => null,
            'pagesCount' => null,
        ], $pdfInformation);

        $this->file = $file;
        $this->finishedCpdfInfoProcess = $finishedCpdfInfoProcess;
        $this->openingErrors = $openingErrors;
        $this->pdfInformation = $pdfInformation;
    }

    public function couldNotOpenDueToOpenTimeout()
    {
        return $this->openingErrors['openOperationTimeout'];
    }

    public function couldNotOpenDueToBrokenFile()
    {
        return $this->openingErrors['brokenPdfFile'];
    }

    public function isPasswordProtected()
    {
        return $this->openingErrors['isPasswordSecured'];
    }

    public function couldNotOpenDueToAnyReason()
    {
        return false !== array_search(true, array_values($this->openingErrors));
    }

    public function opensWithWarnings()
    {
        return true === $this->pdfInformation['opensWithWarnings'];
    }

    public function isEncrypted()
    {
        return true === $this->pdfInformation['encrypted'];
    }

    public function isWithRestrictivePermissions()
    {
        return true === $this->pdfInformation['withRestrictivePermissions'];
    }

    /**
     * @return int|null Null if it was not determinable due to errors.
     */
    public function getPagesCount()
    {
        return $this->pdfInformation['pagesCount'];
    }

    public function getPagesCountOrThrow()
    {
        $pagesCount = $this->getPagesCount();

        if (null === $pagesCount) {
            $errorMessage = sprintf(
                "Couldn't read pdf file pages count. Pdf file: `%s`. Opening errors: `%s`. Error details: `%s`",
                $this->file->getPathname(),
                json_encode($this->openingErrors),
                $this->finishedCpdfInfoProcess->isSuccessful() ? 'none' : (new ProcessFailedException($this->finishedCpdfInfoProcess))->getMessage()
            );

            throw new \RuntimeException($errorMessage);
        }

        return $pagesCount;
    }

}
