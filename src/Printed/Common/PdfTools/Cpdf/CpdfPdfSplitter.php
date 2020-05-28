<?php

namespace Printed\Common\PdfTools\Cpdf;

use Printed\Common\Filesystem\TemporaryFile;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

class CpdfPdfSplitter
{
    /** @var Filesystem */
    private $filesystem;

    /** @var CpdfPdfInformationExtractor */
    private $cpdfPdfInformationExtractor;

    /** @var CpdfBinaryConfiguration */
    private $binaryConfig;

    /**
     * @param Filesystem $filesystem
     * @param CpdfPdfInformationExtractor $cpdfPdfInformationExtractor
     * @param string $binaryPath Full path to the cpdf binary.
     *
     * @throws Exception\CpdfException
     */
    public function __construct(
        Filesystem $filesystem,
        CpdfPdfInformationExtractor $cpdfPdfInformationExtractor,
        $binaryPath
    ) {
        $this->filesystem = $filesystem;
        $this->cpdfPdfInformationExtractor = $cpdfPdfInformationExtractor;
        $this->binaryConfig = CpdfBinaryConfiguration::create($binaryPath);
    }

    /**
     * The split files will be put in the same directory as the source file. This might cause problems
     * (i.e. name conflicts) if you misuse this method.
     *
     * @param File $pdfFile
     * @param array $options An array of options to use in the spit command.
     *
     * @return File[]
     */
    public function split(File $pdfFile, array $options = [])
    {
        $options = array_merge([
            /**
             * @var int|null Null means "Symfony default" (i.e. not unlimitted).
             */
            'timeoutSeconds' => null,
            /*
             * @deprecated This option now is always on due to cpdf "-remove-bookmarks" op's creating broken files without
             *  it. The cases that are fixed by having this option always on are unit tested, so feel free to disable this
             *  option to see in what way the pdfs are broken.
             */
            'preventPreserveObjectStreams' => true,
            /*
             * @var int|null Max number of extracted pages. Extra pages are ignored. You most likely always want to somehow
             *  ensure that you don't just split any pdf the end user provides you with. This options is one of the ways.
             *  Null means "no limit".
             */
            'maxPageCount' => null,
        ], $options);

        if ($pdfFile->guessExtension() !== 'pdf') {
            throw new RuntimeException("Can't split by pages a non-pdf file. File: `{$pdfFile->getPathname()}`.");
        }

        $outputFiles = [];

        $inputPathname = $pdfFile->getPathname();

        $pathInfo = pathinfo($inputPathname);

        $extraArguments = [];

        $outputPathname = sprintf(
            '%s/%s.@N.%s',
            $pathInfo['dirname'],
            $pathInfo['filename'],
            $pathInfo['extension']
        );

        /*
         * Max page count. The number must not be larger than the number of pages in the pdf.
         */
        $pageCountToExtract = $this->cpdfPdfInformationExtractor->getPagesCount($pdfFile);
        if (
            $options['maxPageCount']
            && $pageCountToExtract > $options['maxPageCount']
        ) {
            $pageCountToExtract = $options['maxPageCount'];
        }

        $remainingTimeoutSeconds = $options['timeoutSeconds'];

        /*
         * PDF splitting is done in 2 steps:
         *
         * 1. Remove bookmarks. Save output to a temporary file.
         * 2. Split the pdf stored in the temporary file.
         *
         * Reason: to cover all use cases. This is unit tested to feel free to experiment with different implementations
         * if needed.
         */

        /*
         * -remove-bookmarks fixes an issue caused by corrupt bookmarks.
         * @see https://github.com/johnwhitington/cpdf-source/issues/123
         */
        list(
            $intermediaryTemporaryFile,
            $remainingTimeoutSeconds
        ) = $this->removePdfBookmarks($pdfFile, $remainingTimeoutSeconds);

        $command = implode(' ', [
            sprintf(
                'exec ./%1$s -i %2$s 1-%3$d',
                $this->binaryConfig->getFilename(),
                $intermediaryTemporaryFile->getPathname(),
                $pageCountToExtract
            ),
            sprintf(
                'AND %s -no-preserve-objstm -split -o %s',
                implode(' ', $extraArguments),
                $outputPathname
            ),
        ]);

        $cpdfProcess = new Process(
            $command,
            $this->binaryConfig->getPath(),
            null,
            null,
            $remainingTimeoutSeconds === null ? 60 : $remainingTimeoutSeconds
        );
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

    /**
     * @param File $pdfFile
     * @param int|null $remainingTimeoutSeconds
     * @return array Tuple: [
     *  $intermediaryTemporaryFile: TemporaryFile,
     *  $remainingTimeoutSeconds: ?int,
     * ]
     */
    private function removePdfBookmarks(File $pdfFile, $remainingTimeoutSeconds)
    {
        /*
         * Future improvement: Better way would be to use a factory for temporary files but there wasn't available at the
         * time of writing this. Apologies for #excuses.
         *
         * Note that local /tmp folder is preferred here (over any mounted equivalent) for speed.
         */
        $intermediaryTemporaryFile = new TemporaryFile($this->filesystem, sys_get_temp_dir());

        $cliCommand = sprintf(
            'exec ./%s -remove-bookmarks -i %s AND -no-preserve-objstm -o %s',
            $this->binaryConfig->getFilename(),
            $pdfFile->getPathname(),
            $intermediaryTemporaryFile->getPathname()
        );

        $cliCommandTimeStart = time();

        $removePdfBookmarksProcess = new Process(
            $cliCommand,
            $this->binaryConfig->getPath(),
            null,
            null,
            $remainingTimeoutSeconds === null ? 60 : $remainingTimeoutSeconds
        );
        $removePdfBookmarksProcess->mustRun();

        return [
            $intermediaryTemporaryFile,
            $remainingTimeoutSeconds === null
                ? null
                : $remainingTimeoutSeconds - (time() - $cliCommandTimeStart),
        ];
    }
}
