<?php

namespace Printed\Common\PdfTools\Cpdf;

use Printed\Common\PdfTools\Cpdf\ValueObject\PdfBoxesInformation;
use Printed\Common\PdfTools\Cpdf\ValueObject\PdfInformation;
use Printed\Common\PdfTools\Utils\Geometry\PlaneGeometry\Rectangle;
use Printed\Common\PdfTools\Utils\Geometry\PlaneGeometry\RectangleInterface;
use Printed\Common\PdfTools\Utils\MeasurementConverter;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Class CpdfPdfInformationExtractor
 *
 * Convenience class to regexp the output of `cpdf -info`.
 *
 * This namespace is a massive copy&paste from PRWE, but adapted to run on php5.
 */
class CpdfPdfInformationExtractor
{
    /** @var string */
    private $projectDir;

    /** @var array */
    private $options;

    public function __construct($projectDir, array $options = [])
    {
        $options = array_merge([
            /*
             * Useful to override if you have a Rectangle class in your own codebase and you want to use it instead of this
             * package's one.
             *
             * You need to return an instance of RectangleInterface from this callable.
             */
            'rectangleFactoryFn' => function ($x, $y, $width, $height, $units = MeasurementConverter::UNIT_NO_UNIT) {
                return new Rectangle($x, $y, $width, $height, $units);
            },
        ], $options);

        $this->projectDir = $projectDir;
        $this->options = $options;
    }

    /**
     * A graceful way to get pdf file information and to pass it around your own code (instead of constructing it
     * over and over again).
     *
     * DANGER: this method still throws exceptions on massive bs, so if you want to be completely safe, you need to
     * catch those exceptions yourself and think of what to do with them (e.g. log and assume 0 pdf pages)
     *
     * @param File $file
     * @param array $options
     * @return PdfInformation
     */
    public function readPdfInformation($file, array $options = [])
    {
        $options = array_merge([
            'pdfOpenTimeoutSeconds' => 10,
        ], $options);

        $cpdfProcess = new Process(
            sprintf('exec vendor/bin/cpdf -info -i %s', escapeshellarg($file->getPathname())),
            $this->projectDir,
            null,
            null,
            $options['pdfOpenTimeoutSeconds']
        );

        try {
            $cpdfProcess->run();
        } catch (ProcessTimedOutException $exception) {
            return new PdfInformation(
                $file,
                $cpdfProcess,
                [
                    'openOperationTimeout' => true,
                ]
            );
        }

        /*
         * Check exit codes
         */
        switch ($cpdfProcess->getExitCode()) {
            case 0:
                break;

            case 1:
                return new PdfInformation(
                    $file,
                    $cpdfProcess,
                    [
                        'isPasswordSecured' => true,
                    ]
                );

            case 2:
                return new PdfInformation(
                    $file,
                    $cpdfProcess,
                    [
                        'brokenPdfFile' => true,
                    ]
                );

            default:
                throw new \RuntimeException("Cpdf unexpectedly finished with exit code other than 0, 1 or 2 for file: `{$file->getPathname()}`");
        }

        /*
         * Gather pdf file information. Note that due to the fact that pdfs can fail in unlimited number of ways, not
         * all the information is available all the time.
         */

        /*
         * Opens with warnings?
         */
        $pdfPageOpensWithWarnings = (bool) $cpdfProcess->getErrorOutput();

        /*
         * I expect this output:
         *
         *  Encryption: 128bit AES, Metadata encrypted
         *  Permissions: No assemble, No copy, No edit
         *  Linearized: true
         *  Version: 1.7
         *  Pages: 1
         *  (more lines ...)
         */
        $processOutputLines = explode(PHP_EOL, $cpdfProcess->getOutput());

        /*
         * Encryption line
         */
        $isPdfFileEncrypted = null;

        if (($encryptionLine = isset($processOutputLines[0]) ? $processOutputLines[0] : null)) {
            /*
             * Assert the line content
             */
            if (0 !== strpos($encryptionLine, 'Encryption:')) {
                throw new \RuntimeException("'`Cpdf -info` didn't produce the encryption line as the first line");
            }

            preg_match('/^Encryption:(.*)/', $encryptionLine, $regexpMatches);

            $isPdfFileEncrypted = trim($regexpMatches[1]) !== 'Not encrypted';
        }

        /*
         * Permissions line
         */
        $isPdfFileWithRestrictedPermissions = null;

        if (($permissionsLine = isset($processOutputLines[1]) ? $processOutputLines[1] : null)) {
            /*
             * Assert the line content
             */
            if (0 !== strpos($permissionsLine, 'Permissions:')) {
                throw new \RuntimeException("'`Cpdf -info` didn't produce the permissions line as the second line");
            }

            preg_match('/^Permissions:(.*)/', $permissionsLine, $regexpMatches);

            $isPdfFileWithRestrictedPermissions = trim($regexpMatches[1]) !== '';
        }

        /*
         * Pages line
         */
        $pdfPagesCount = null;
        if (($pagesLine = isset($processOutputLines[4]) ? $processOutputLines[4] : null)) {
            /*
             * Assert the line content
             */
            if (0 !== strpos($pagesLine, 'Pages:')) {
                throw new \RuntimeException("'`Cpdf -info` didn't produce the pages line as the fifth line");
            }

            preg_match('/^Pages:(.*)/', $pagesLine, $regexpMatches);

            $pdfPagesCount = (int) trim($regexpMatches[1]);
        }

        return new PdfInformation(
            $file,
            $cpdfProcess,
            [],
            [
                'opensWithWarnings' => $pdfPageOpensWithWarnings,
                'encrypted' => $isPdfFileEncrypted,
                'withRestrictivePermissions' => $isPdfFileWithRestrictedPermissions,
                'pagesCount' => $pdfPagesCount,
            ]
        );
    }

    /**
     * Note: This will throw if the pdf file is broken. Using PdfValidator or ::readPdfInformation() might be an interesting
     * option for you instead.
     *
     * @param File $file
     * @return int
     */
    public function getPagesCount(File $file)
    {
        return $this->readPdfInformation($file)->getPagesCountOrThrow();
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

        /*
         * Note that this will crash on hopeless errors but not on cpdf warnings, which is desired. Cpdf will try to
         * recover from warnings. When it recovers, the exit code is 0, allowing the script to proceed. I still note that
         * there were opening warnings in the return value of this method later on.
         */
        $cpdfProcess->mustRun();

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

        /**
         * @param string $boxName
         * @return RectangleInterface
         */
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

            /*
             * Retrieve rotation. The page coords are before rotation and not after it. I need to rotate the coords myself
             * here.
             */
            $pdfPageRotation = (int) (isset($cpdfPageInfoOutput['Rotation']) ? $cpdfPageInfoOutput['Rotation'] : 0);

            /*
             * On 90 and 270 deg rotation, swap x with y and width with height. It sounds wrong in the case of x and y,
             * but trust me - it works.
             */
            if (in_array($pdfPageRotation, [90, 270])) {
                list($pdfBoxCoordinates[0], $pdfBoxCoordinates[1]) = [$pdfBoxCoordinates[1], $pdfBoxCoordinates[0]];
                list($pdfBoxCoordinates[2], $pdfBoxCoordinates[3]) = [$pdfBoxCoordinates[3], $pdfBoxCoordinates[2]];
            }

            return $this->options['rectangleFactoryFn'](
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
            throw new \RuntimeException("Cpdf couldn't read a pdf's MediaBox from `{$pdfFile->getPathname()}`. Cpdf output: `{$cpdfPageInfoOutputJsonEncoded}`, error output: `{$cpdfProcess->getErrorOutput()}`");
        }

        /*
         * In some pdf (most notably, Lee's business card test pdf) the mediabox offsets (i.e. x and y) aren't 0. This is
         * an error, but apparently the whole world is handling it and so need I.
         *
         * If the offsets of the media box aren't 0, then I need to move the trimbox by the broken media box's offset.
         */
        if ($trimBox) {
            $trimBox = $this->options['rectangleFactoryFn'](
                $trimBox->getX() - $mediaBox->getX(),
                $trimBox->getY() - $mediaBox->getY(),
                $trimBox->getWidth(),
                $trimBox->getHeight(),
                $trimBox->getUnits()
            );
        }

        return new PdfBoxesInformation(
            $mediaBox,
            $trimBox,
            $cpdfProcess->getErrorOutput()
        );
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
