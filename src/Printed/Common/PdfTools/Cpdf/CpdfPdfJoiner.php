<?php

namespace Printed\Common\PdfTools\Cpdf;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

class CpdfPdfJoiner
{
    /** @var string */
    private $binaryPath;

    /** @var string */
    private $binaryFilename;

    /**
     * @param $binaryPath
     *
     * @throws Exception\CpdfException
     */
    public function __construct($binaryPath)
    {
        CpdfBinaryValidator::assertBinaryPath($binaryPath);

        $pathInfo = pathinfo($binaryPath);

        $this->binaryPath = $pathInfo['dirname'];
        $this->binaryFilename = $pathInfo['basename'];
    }

    /**
     * Joins an array of PDF File objects into the provided File.
     *
     * @param File[] $files
     * @param File|null $outputFile
     * @return File
     */
    public function join(array $files, File $outputFile)
    {
        $inputFiles = [];

        foreach ($files as $file) {
            $inputFiles[] = $file->getPathname();
        }

        $command = sprintf(
            './%s -i %s -o %s',
            $this->binaryFilename,
            implode(' -i ', $inputFiles),
            $outputFile->getPathname()
        );

        $cpdfProcess = new Process($command, $this->binaryPath);
        $cpdfProcess->mustRun();

        if ($cpdfProcess->getErrorOutput()) {
            throw new \RuntimeException("`cpdf` join command produced error output: {$cpdfProcess->getErrorOutput()}.");
        }

        return $outputFile;
    }
}
