<?php

namespace Printed\Common\PdfTools\Cpdf;

use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

class CpdfPdfSplitter
{
    /** @var CpdfBinaryConfiguration */
    private $binaryConfig;

    /**
     * @param string $binaryPath Full path to the cpdf binary.
     *
     * @throws Exception\CpdfException
     */
    public function __construct($binaryPath)
    {
        $this->binaryConfig = CpdfBinaryConfiguration::create($binaryPath);
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
            sprintf('exec ./%s -remove-bookmarks -i %s', $this->binaryConfig->getFilename(), $inputPathname),
            sprintf(
                'AND %s -split -o %s',
                implode(' ', $extraArguments),
                $outputPathname
            ),
        ]);

        $cpdfProcess = new Process($command, $this->binaryConfig->getPath());
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
