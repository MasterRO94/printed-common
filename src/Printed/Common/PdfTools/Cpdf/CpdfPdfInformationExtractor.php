<?php

namespace Printed\Common\PdfTools\Cpdf;

use Printed\Common\PdfTools\Cpdf\ValueObject\PdfBoxesInformation;
use Printed\Common\PdfTools\Utils\Geometry\PlaneGeometry\Rectangle;
use Printed\Common\PdfTools\Utils\MeasurementConverter;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

/**
 * Class CpdfPdfInformationExtractor
 *
 * Convenience class to regexp the output of `cpdf -info`.
 *
 * Note: If the pdf file is broken or can't be opened for any reason, you can expect exceptions. Using PdfValidator on
 * the desired pdf file first is a good idea.
 *
 * This namespace is a massive copy&paste from PRWE, but adapted to run on php5.
 *
 * @todo Ideally, I should extract this functionality into a pdc common project, so it can be used in any pdc
 * project. The problem is with supporting php5 and php7 at the same time.
 */
class CpdfPdfInformationExtractor
{
    /** @var string */
    private $projectDir;

    public function __construct($projectDir)
    {
        $this->projectDir = $projectDir;
    }

    /**
     * Note: This will throw if the pdf file is broken. Using PdfValidator first is a good idea.
     *
     * @param File $file
     * @return int
     */
    public function getPagesCount(File $file)
    {
        return (int) $this->extractCpdfInfoLine($file, 4, 'Pages:');
    }

    /**
     * Note: This will throw if the pdf file is broken. Using PdfValidator first is a good idea.
     *
     * @param File $pdfFile
     * @return PdfBoxesInformation
     */
    public function readPdfBoxesInformationOfFirstPageInFile(File $pdfFile)
    {
        return $this->readPdfBoxesInformationOfPageInFile($pdfFile, 1);
    }

    /**
     * Note: This will throw if the pdf file is broken. Using PdfValidator first is a good idea.
     *
     * @param File $pdfFile
     * @param int $pageNumber
     * @return PdfBoxesInformation
     */
    public function readPdfBoxesInformationOfPageInFile(File $pdfFile, $pageNumber)
    {
        /*
         * Fyi: In the cpdf command, do not use the "AND" operator when you don't need it. Apparently it makes cpdf
         * split the pdf in memory, which in turn causes some subtly incorrect pdfs to fail.
         */
        $cpdfProcess = new Process(
            sprintf(
                'exec vendor/bin/cpdf -i %1$s %2$s-%2$s -page-info',
                escapeshellarg($pdfFile->getPathname()),
                $pageNumber
            ),
            $this->projectDir
        );

        $cpdfProcess->mustRun();

        if ($cpdfProcess->getErrorOutput()) {
            throw new \RuntimeException("`cpdf -page-info` produced error output: {$cpdfProcess->getErrorOutput()}. Assuming file is unusable.");
        }

        /*
         * The output looks like this:
         *
         * Page 1:
         * Label:
         * MediaBox: 0.000000 0.000000 1124.000000 2686.000000
         * CropBox: 0.000000 0.000000 1124.000000 2686.000000
         * BleedBox:
         * TrimBox:
         * ArtBox:
         * Rotation: 0
         */
        $cpdfPageInfoOutput = $this->convertCpdfOutputToKeyValueHashMap($cpdfProcess->getOutput());
        $cpdfPageInfoOutputJsonEncoded = json_encode($cpdfPageInfoOutput);

        $createPdfBoxRectangleFn = function ($boxName) use ($cpdfPageInfoOutput, $cpdfPageInfoOutputJsonEncoded) {
            if (!isset($cpdfPageInfoOutput[$boxName])) {
                throw new \RuntimeException("Couldn't retrieve `{$boxName}` pdf box from cpdf output: `{$cpdfPageInfoOutputJsonEncoded}`.");
            }

            $pdfBoxRaw = $cpdfPageInfoOutput[$boxName];

            if (!$pdfBoxRaw) {
                return null;
            }

            /*
             * The coords are: lower_left_x, lower_left_y, upper_right_x, upper_right_y in points (pt)
             */
            $pdfBoxCoordinates = explode(' ', $pdfBoxRaw);
            $pdfBoxCoordinates = array_map(function ($coordinate) {
                return (float) $coordinate;
            }, $pdfBoxCoordinates);

            return new Rectangle(
                $pdfBoxCoordinates[0],
                $pdfBoxCoordinates[1],
                $pdfBoxCoordinates[2] - $pdfBoxCoordinates[0],
                $pdfBoxCoordinates[3] - $pdfBoxCoordinates[1],
                MeasurementConverter::UNIT_PT
            );
        };

        $mediaBox = $createPdfBoxRectangleFn('MediaBox');
        $trimBox = $createPdfBoxRectangleFn('TrimBox');

        if (!$mediaBox) {
            throw new \RuntimeException("Cpdf couldn't read a pdf's MediaBox. Cpdf output: {$cpdfPageInfoOutputJsonEncoded}");
        }

        return new PdfBoxesInformation(
            $mediaBox,
            $trimBox
        );
    }

    /**
     * @param File $file
     * @param int $outputLineIndex
     * @param string $lineBeginning
     * @return string
     */
    private function extractCpdfInfoLine(File $file, $outputLineIndex, $lineBeginning)
    {
        $outputLines = explode(PHP_EOL, $this->produceCpdfInfoOutput($file->getPathname()));

        if (!array_key_exists($outputLineIndex, $outputLines)) {
            throw new \RuntimeException("Couldn't extract {$outputLineIndex} line from `cpdf -info` output");
        }

        $outputLine = $outputLines[$outputLineIndex];

        if (strpos($outputLine, $lineBeginning) !== 0) {
            throw new \RuntimeException("'`Cpdf -info` didn't produce the `{$lineBeginning}` line as the `{$outputLineIndex}` line");
        }

        preg_match("/^{$lineBeginning}(.*)/", $outputLine, $regexpMatches);

        return trim($regexpMatches[1]);
    }

    /**
     * @param string $filePath
     * @return string
     */
    private function produceCpdfInfoOutput($filePath)
    {
        $cpdfProcess = new Process(
            sprintf('exec vendor/bin/cpdf -info -i %s', escapeshellarg($filePath)),
            $this->projectDir,
            null,
            null,
            /*
             * Healthy files produce the information almost instantaneously. There's no point waiting for the processing
             * of the broken files for too long.
             *
             * This is a good candidate for an option, however I didn't need it at the time of writing this.
             */
            10
        );

        $cpdfProcess->mustRun();

        if ($cpdfProcess->getErrorOutput()) {
            throw new \RuntimeException("`cpdf -info` produced error output: {$cpdfProcess->getErrorOutput()}. Assuming file is unusable.");
        }

        return $cpdfProcess->getOutput();
    }

    /**
     * Convert
     *
     * Page 1:
     * Label:
     * MediaBox: 0.000000 0.000000 1124.000000 2686.000000
     * CropBox: 0.000000 0.000000 1124.000000 2686.000000
     *
     * to
     *
     * {
     *   'Page 1' => '',
     *   'Label' => '',
     *   'MediaBox' => '0.000000 0.000000 1124.000000 2686.000000',
     *   'CropBox' => '0.000000 0.000000 1124.000000 2686.000000',
     * }
     *
     * Will throw if the output contains lines, that don't have ":" to split on (unless the line is empty)
     *
     * @param string $cpdfOutput
     * @return array
     */
    private function convertCpdfOutputToKeyValueHashMap($cpdfOutput)
    {
        $outputLines = explode(PHP_EOL, $cpdfOutput);
        $outputLinesKeyValue = [];

        foreach ($outputLines as $outputLine) {
            if ($outputLine === '') {
                continue;
            }

            $keyValueSplit = explode(':', $outputLine);

            if (count($keyValueSplit) !== 2) {
                throw new \RuntimeException("Cpdf produced an unexpected output at line: `{$outputLine}`. Whole output: `$cpdfOutput`");
            }

            $outputLinesKeyValue[trim($keyValueSplit[0])] = trim($keyValueSplit[1]);
        }

        return $outputLinesKeyValue;
    }
}
