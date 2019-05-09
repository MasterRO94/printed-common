<?php

namespace Printed\Common\PdfTools\Cpdf;

use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

class CpdfPdfSplitter
{
    private $vendorBinDir;

    /**
     * @param string $vendorBinDir
     */
    private function __construct($vendorBinDir)
    {
        $this->vendorBinDir = $vendorBinDir;
    }

    /**
     * @param File $pdfFile
     * @param string[] $options An array of options to use in the spit command.
     *
     * @return File[]
     */
    public function split(File $pdfFile, array $options = [])
    {
        $options = array_merge($options, [
            'preventPreserveObjectStreams' => false,
        ]);

        $outputFiles = [];

        $pathname = $pdfFile->getPathname();

        $pathInfo = pathinfo($pathname);

        $extraArguments = [];

        if ($options['preventPreserveObjectStreams']) {
            $extraArguments[] = '-no-preserve-objstm';
        }

        $outputPathname = sprintf(
            '%s/%s.@N.%s',
            $pathInfo['dirname'],
            $pathInfo['filename'],
            $pathInfo['extension']
        );

        $command = sprintf(
            './cpdf -split %s %s -o %s',
            implode(' ', $extraArguments),
            $pathname,
            $outputPathname
        );

        $cpdfProcess = new Process($command, $this->vendorBinDir);
        $cpdfProcess->mustRun();

        if ($cpdfProcess->getErrorOutput()) {
            throw new RuntimeException("`cpdf` split command produced error output: {$cpdfProcess->getErrorOutput()}.");
        }

        $globFiles = glob(
            sprintf('%s/%s.[0-9]*.%s', $pathInfo['dirname'], $pathInfo['filename'], $pathInfo['extension'])
        );

        foreach ($globFiles as $globFile) {
            $outputFiles[] = new File($globFile);
        }

        // Sort the files naturally (i.e. 1,2,3..,10 instead of 1,10,2,3..).
        natsort($outputFiles);

        return array_values($outputFiles);
    }
}
