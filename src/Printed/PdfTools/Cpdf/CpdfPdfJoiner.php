<?php

namespace Printed\PdfTools\Cpdf;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

class CpdfPdfJoiner
{
    /** @var string */
    private $projectDir;

    /**
     * @param string $projectDir
     */
    private function __construct($projectDir)
    {
        $this->projectDir = $projectDir;
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
            'vendor/bin/cpdf -i %s -o %s',
            implode(' -i ', $inputFiles),
            $outputFile->getPathname()
        );

        $cpdfProcess = new Process($command, $this->projectDir);
        $cpdfProcess->mustRun();

        if ($cpdfProcess->getErrorOutput()) {
            throw new \RuntimeException("`cpdf` join command produced error output: {$cpdfProcess->getErrorOutput()}.");
        }

        return $outputFile;
    }
}