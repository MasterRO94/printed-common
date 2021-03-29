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
     * Split the pdf file ($pdfFile) into chunks,
     * writing the output files as "{$outputFileName}-001.pdf", "{$outputFileName}-002.pdf" etc.
     * The output files will be saved in the same directory where $pdfFile is
     * stored.
     *
     * e.g.
     * $pdfFile->getRealPath() === "/temp/upload-file-name.pdf"
     * "/temp/upload-file-name.pdf" contains 12 pages
     * $chunks === 3
     * The following pdfs will be returned:
     * {$outputFileDirectory}/{$outputFileName}-001.pdf (will contain pages 1,2 and 3 of "/temp/upload-file-name.pdf")
     * {$outputFileDirectory}/{$outputFileName}-002.pdf (will contain pages 4,5 and 6 of "/temp/upload-file-name.pdf")
     * {$outputFileDirectory}/{$outputFileName}-003.pdf (will contain pages 7,8 and 9 of "/temp/upload-file-name.pdf")
     * {$outputFileDirectory}/{$outputFileName}-004.pdf (will contain pages 10,11 and 12 of "/temp/upload-file-name.pdf")
     *
     * @param File $pdfFile - absolute path to the pdf file that will split
     * @param string $outputFileDirectory - an absolute path indicating where the
     *                                      output files should be saved.
     * @param string $outputFileName - The name that the output files will be
     *                                 given.
     * @param int $chunks - how many pages should each chunk  contain
     * @param int|null $timeoutSeconds - after how many seconds will the command timeout.
     *                                   If nothing provided it defaults to 60 seconds.
     *
     * @return File[]
     */
    public function chunks(
        File $pdfFile,
        $outputFileDirectory,
        $outputFileName,
        $chunks,
        $timeoutSeconds
    ) {
        $this->assertPdfFile($pdfFile);

        /*
         * Two steps are needed to split PDF into chunks:
         *
         * 1. Remove bookmarks. Save output to a temporary file.
         * 2. Split the pdf stored in the temporary file.
         *
         * Reason: to cover all use cases.
         */

        /*
         * -remove-bookmarks fixes an issue caused by corrupt bookmarks.
         * @see https://github.com/johnwhitington/cpdf-source/issues/123
         */
        list(
            $intermediaryTemporaryFile,
            $remainingTimeoutSeconds
            ) = $this->removePdfBookmarks($pdfFile, $timeoutSeconds);

        // Run the command
        return $this->runChunkCommand(
            $intermediaryTemporaryFile,
            $outputFileDirectory,
            $outputFileName,
            $chunks,
            $remainingTimeoutSeconds === null ? 60 : $remainingTimeoutSeconds
        );
    }

    /**
     * Split the pdf file ($pdfFile) into chunks,
     * writing the output files as "{$outputFileName}-001.pdf", "{$outputFileName}-002.pdf" etc.
     *
     * e.g.
     * $pdfFile->getRealPath() === "/temp/upload-file-name.pdf"
     * "/temp/upload-file-name.pdf" contains 12 pages
     * $chunks === 3
     * The following pdfs will be returned:
     * {$outputFileDirectory}/{$outputFileName}-001.pdf (will contain pages 1,2 and 3 of "/temp/upload-file-name.pdf")
     * {$outputFileDirectory}/{$outputFileName}-002.pdf (will contain pages 4,5 and 6 of "/temp/upload-file-name.pdf")
     * {$outputFileDirectory}/{$outputFileName}-003.pdf (will contain pages 7,8 and 9 of "/temp/upload-file-name.pdf")
     * {$outputFileDirectory}/{$outputFileName}-004.pdf (will contain pages 10,11 and 12 of "/temp/upload-file-name.pdf")
     *
     * @param File $pdfFile - The file that will be split
     * @param string $outputFileDirectory - an absolute path indicating where the
     *                                 output files should be saved.
     * @param string $outputFileName - The name that the output files will be
     *                                 given.
     * @param int $chunks - how many pages should each chunk  contain
     * @param int $timeout - after how many seconds will the command timeout.
     *                                   If nothing provided it defaults to 60 seconds.
     *
     * @throws RuntimeException
     * @return File[]
     */
    private function runChunkCommand(
        File $pdfFile,
        $outputFileDirectory,
        $outputFileName,
        $chunks,
        $timeout
    ) {
        $outputFileNamePrefix = "{$outputFileDirectory}/{$outputFileName}-";

        /**
         * Split the pdf file found at $path, into chunks,
         * writing the output files (new pdf files) as
         * "{$outputFileNamePrefix}001.pdf", "{$outputFileNamePrefix}002.pdf" etc.
         *
         * "-i" refers to input. It should be the file path of the pdf to split.
         * "-o" refers to output. Defines the naming convention (and location) of the generated pdfs.
         * "-chunk" refers to how many pages should be in the chunk
         *
         * For more information about coherentpdf (cpdf)
         * @see https://www.coherentpdf.com/usage-examples.html
         */
        $command = "{$this->binaryConfig->getFilename()} -split -i {$pdfFile->getRealPath()} -o {$outputFileNamePrefix}%%%.pdf -chunk {$chunks}";

        // run the command.
        $command = "exec ./" . $command;
        $currentWorkingDirectory = $this->binaryConfig->getPath();
        $process = new Process(
            $command,
            $currentWorkingDirectory,
            null,
            null,
            $timeout
        );
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException("
                Attempted '{$command}'
                \n Current Working Directory: '{$currentWorkingDirectory}'
                \n Exit Code: '{$process->getExitCode()}'
                \n Output: '{$process->getOutput()}'
            ");
        }

        // return the files generated by the command.
        $globFiles = glob("{$outputFileNamePrefix}*");
        $outputFiles = [];
        foreach ($globFiles as $globFile) {
            $outputFiles[] = new File($globFile);
        }
        // Sort the files naturally (i.e. 1,2,3..,10 instead of 1,10,2,3..).
        natsort($outputFiles);
        return $outputFiles;
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

    /**
     * @param File $file
     * @throws RuntimeException
     */
    private function assertPdfFile(File $file)
    {
        if ($file->guessExtension() !== 'pdf') {
            throw new RuntimeException("Can't split by pages a non-pdf file. File: `{$file->getPathname()}`.");
        }
    }
}
