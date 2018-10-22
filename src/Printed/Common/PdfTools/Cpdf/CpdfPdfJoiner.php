<?php

namespace Printed\Common\PdfTools\Cpdf;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

class CpdfPdfJoiner
{
    /** @var string */
    private $vendorBinDir;

    /**
     * @param string $vendorBinDir
     */
    public function __construct($vendorBinDir)
    {
        $this->vendorBinDir = $vendorBinDir;
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
            'cpdf -i %s -o %s',
            implode(' -i ', $inputFiles),
            $outputFile->getPathname()
        );

        $cpdfProcess = new Process($command, $this->vendorBinDir);
        $cpdfProcess->mustRun();

        if ($cpdfProcess->getErrorOutput()) {
            throw new \RuntimeException("`cpdf` join command produced error output: {$cpdfProcess->getErrorOutput()}.");
        }

        return $outputFile;
    }
}
