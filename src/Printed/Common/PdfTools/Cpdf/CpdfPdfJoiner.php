<?php

namespace Printed\Common\PdfTools\Cpdf;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

class CpdfPdfJoiner
{
    /** @var CpdfBinaryConfiguration */
    private $binaryConfig;

    /**
     * @param $binaryPath
     *
     * @throws Exception\CpdfException
     */
    public function __construct($binaryPath)
    {
        $this->binaryConfig = CpdfBinaryConfiguration::create($binaryPath);
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
            'exec ./%s -i %s -o %s',
            $this->binaryConfig->getFilename(),
            implode(' -i ', $inputFiles),
            $outputFile->getPathname()
        );

        $cpdfProcess = new Process($command, $this->binaryConfig->getPath());
        $cpdfProcess->mustRun();

        if ($cpdfProcess->getErrorOutput()) {
            throw new \RuntimeException("`cpdf` join command produced error output: {$cpdfProcess->getErrorOutput()}.");
        }

        return $outputFile;
    }
}
