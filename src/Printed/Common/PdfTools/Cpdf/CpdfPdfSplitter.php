<?php

namespace Printed\Common\PdfTools\Cpdf;

use Printed\Common\Filesystem\TemporaryFile;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

class CpdfPdfSplitter
{
    /** @var CpdfPdfInformationExtractor */
    private $cpdfPdfInformationExtractor;

    /** @var CpdfBinaryConfiguration */
    private $binaryConfig;

    /**
     * @param CpdfPdfInformationExtractor $cpdfPdfInformationExtractor
     * @param string $binaryPath Full path to the cpdf binary.
     *
     * @throws Exception\CpdfException
     */
    public function __construct(
        CpdfPdfInformationExtractor $cpdfPdfInformationExtractor,
        $binaryPath
    ) {
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
         * Max page count. The number must not be larger than the number of pages in the pdf.
         */
        $pageCountToExtract = $this->cpdfPdfInformationExtractor->getPagesCount($pdfFile);
        if (
            $options['maxPageCount']
            && $pageCountToExtract > $options['maxPageCount']
        ) {
            $pageCountToExtract = $options['maxPageCount'];
        }

        $intermediaryTemporaryFile = new TemporaryFile(new Filesystem(), sys_get_temp_dir());

        $process1 = new Process(
            sprintf(
                'exec ./%s -remove-bookmarks -i %s AND -no-preserve-objstm -o %s',
                $this->binaryConfig->getFilename(),
                $inputPathname,
                $intermediaryTemporaryFile->getPathname()
            ),
            $this->binaryConfig->getPath()
        );
        $process1->mustRun();

        /*
         * -remove-bookmarks fixes an issue caused by corrupt bookmarks.
         * @see https://github.com/johnwhitington/cpdf-source/issues/123
         */
        $command = implode(' ', [
//            sprintf(
//                'exec "./%1$s -remove-bookmarks -i %2$s -stdout | ./%1$s -stdin 1-%3$d',
//                $this->binaryConfig->getFilename(),
//                $inputPathname,
//                $pageCountToExtract
//            ),
            sprintf(
                'exec ./%1$s -i %2$s 1-%3$d',
                $this->binaryConfig->getFilename(),
//                $inputPathname,
                $intermediaryTemporaryFile->getPathname(),
                $pageCountToExtract
            ),
//            'AND -remove-bookmarks',
//            'AND -i 1-%d',
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
