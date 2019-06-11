<?php

namespace Printed\Common\PdfTools\Cpdf;

use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

class CpdfPdfSplitter
{
    /** @var string */
    private $binaryPath;

    /** @var string */
    private $binaryFilename;

    /**
     * @param string $binaryPath Full path to the cpdf binary.
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
     * @param File $pdfFile
     * @param array $options An array of options to use in the spit command.
     *
     * @return File[]
     */
    public function split(File $pdfFile, array $options = [])
    {
        $options = array_merge([
            'preventPreserveObjectStreams' => true,
        ], $options);

        if ($pdfFile->guessExtension() !== 'pdf') {
            throw new RuntimeException("Can't split by pages a non-pdf file. File: `{$pdfFile->getPathname()}`.");
        }

        $outputFiles = [];

        $inputPathname = $pdfFile->getPathname();

        $pathInfo = pathinfo($inputPathname);

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

        /*
         * -remove-bookmarks fixes an issue caused by corrupt bookmarks.
         * @see https://github.com/johnwhitington/cpdf-source/issues/123
         */
        $command = implode(' ', [
            sprintf('./%s -remove-bookmarks -i %s', $this->binaryFilename, $inputPathname),
            sprintf(
                'AND %s -split -o %s',
                implode(' ', $extraArguments),
                $outputPathname
            ),
        ]);

        $cpdfProcess = new Process($command, $this->binaryPath);
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
