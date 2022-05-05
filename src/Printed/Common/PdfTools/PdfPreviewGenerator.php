<?php

namespace Printed\Common\PdfTools;

use Printed\Common\PdfTools\Cpdf\CpdfPdfInformationExtractor;
use Printed\Common\PdfTools\Cpdf\ValueObject\PdfInformation;
use Printed\Common\PdfTools\Utils\MeasurementConverter;
use Printed\Common\PdfTools\Utils\SymfonyProcessRunner;
use Printed\Common\PdfTools\ValueObject\PreviewFileAndPagePdfBoxesInformation;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

/**
 * Class PdfPreviewGenerator
 *
 * Requires Imagemagick and Ghostscript available as pdc-convert and pdc-gs respectively.
 *
 * What's going on here:
 *
 * 1. Pdf is rendered to an intermediary png that's big enough to produce nice renders of curves and fonts and that's
 *    small enough not to take whole day to render.
 *
 * 2. The intermediary png is downscaled to the final png size.
 *
 * Why:
 * 1. Rendering directly to the final png size produces lower quality curves and fonts compared to the "render big
 *    and downscale" process described above. This is because ghostscript doesn't antialias curves, so to work-around
 *    this, it's given more pixels to render to, which are then collapsed by imagemagick that uses it's downscaling
 *    algorithms, which eventually produce very nicely antialiased curves and fonts.
 *
 * 2. You can't tell ghostscript to render a specific size of raster image. It does however allow you to select
 *    a rendering resolution (dpi), which by default is 72. I therefore take the media-box size (which is expressed in pt)
 *    and calculate the dpi that will produce the intermediary image of the size I want.
 *
 *    1. Don't be surprised to see small-sized pdfs (e.g. 85x55 business card) to be rendered at ca. 600 dpi. There's
 *    a good reason the dpi is so ridiculously high in this case. Small-sized pdfs tend to have small text in it (e.g. 8pt),
 *    which end up very blocky if rendered at the default 72dpi. 600dpi does miracles in that matter, rendering an
 *    excellent quality fonts.
 *
 *    2. Don't be surprised to see large-sized pdfs (e.g. 500x2000 banners) to be rendered at ca. 10dpi. There's
 *    a good reason why the render is still very good quality despite the ridiculously low dpi: elements like text
 *    are generally massive in such large-sized pdfs, therefore they don't need big dpi to render nicely. The side-effect
 *    of rendering at such a low dpi is that the rendering is ridiculously fast (about 1s for a 2m banner is remarkable).
 *
 *    3. I decided I don't want to go below 5 dpi, because ghostscript crashes at 1 dpi for some pdfs. Since I'm not
 *    sure why, I decided to have a safe margin by forcing at least 5 dpi. This means that artwork larger than 10m
 *    will start to consume server's resources significantly (since the intermediary bitmap will start to grow in size).
 *    Fyi.
 *
 * 3. Despite doing decent job while rendering, I enabled antialiasing for text and graphics in the pdfs. I chose
 *    the medium quality levels of antialiasing (i.e. 2 out of possible values: 1, 2, 4), because they didn't prove
 *    massively detrimental to the performance and also give a nice touch in certain circumstances.
 *
 * 4. The renders are in png to preserve transparency from the source files.
 *
 * Caveats:
 * 1. Rendering time isn't related to the size of the pdf, but to the complexity of the pdf. It's possible to bring
 *    pdc severs to their knees by uploading small-sized pdfs (e.g. 85x55, which would be rendered at ca. 600dpi)
 *    with complex vector graphics. That's, however, why there is a timeout on the cli command, which alleviates
 *    the problem to some extend. The problem should be monitored, though, since it's perfectly doable for anyone
 *    to DDOS the previewing by e.g. calling the order wizard file upload endpoint with the small-sized pdf using
 *    curl in a loop.
 *
 * PDFs to test with:
 * 1. Go to "afp://192.168.11.122/pdc_development/Test upload files/pdf" and test with:
 *    1. Lee's business card (small-size pdf with small text and curves)
 *    2. Folded product artwork (the one with a lot of curves)
 *    3. Multipage and banner pdfs (just to test multipage and large artwork rendering speed)
 *
 * Room for improvement:
 * 1. Upgrade ghostsript to latest for more ICC support.
 * 2. Define the DefaultCMYK icc profile to be the same that Brian uses in Pitstop (ISO Coated v2 at the time of writing).
 *    Ghostscript uses SWOP by default.
 * 3. Introduce eps support.
 *
 *
 * @maintainer ddera (primary)
 * @maintainer asnowdon (secondary)
 */
class PdfPreviewGenerator
{
    /**
     * The longer side of the bitmap that's rendered as the intermediary preview of a pdf page.
     */
    const RENDERING_INTERMEDIARY_BITMAP_SIZE_PX = 2000;

    /** @var CpdfPdfInformationExtractor */
    private $cpdfPdfInformationExtractor;

    /** @var MeasurementConverter */
    private $measurementConverter;

    /** @var string */
    private $pathToGhostscript;

    /** @var string */
    private $pathToConvert;

    public function __construct(
        BinaryPathConfiguration $binaryPathConfiguration,
        CpdfPdfInformationExtractor $cpdfPdfInformationExtractor,
        MeasurementConverter $measurementConverter
    ) {
        $this->cpdfPdfInformationExtractor = $cpdfPdfInformationExtractor;
        $this->measurementConverter = $measurementConverter;
        $this->pathToGhostscript = $binaryPathConfiguration->getGhostscript();
        $this->pathToConvert = $binaryPathConfiguration->getConvert();
    }

    /**
     * @param File $pdfFile
     * @param File $outputFile
     * @param array $options
     * @return File
     */
    public function generateFirstPagePreview(File $pdfFile, File $outputFile, array $options)
    {
        return $this->generatePreviewForPage($pdfFile, $outputFile, 1, $options);
    }

    /**
     * @param File $pdfFile
     * @param File $outputFile
     * @param int $pageNumber
     * @param array $options
     * @return File|PreviewFileAndPagePdfBoxesInformation|null See $options. Fyi, this may be null only if you use a specific
     *  option. Otherwise, it either succeeds or crashes.
     */
    public function generatePreviewForPage(File $pdfFile, File $outputFile, $pageNumber, array $options = [])
    {
        $options = array_merge([
            'previewSizePx' => 200,

            /*
             * In seconds.
             */
            'timeout' => 60,

            /*
             * Generates preview for a bounding box of the given dimensions, if provided.
             * The artwork in the PDF file will be centred and, if smaller, padded with
             * background colour, if larger, cropped.
             */
            'relativeDimensionsWidth' => null,
            'relativeDimensionsHeight' => null,
            'relativeDimensionsUnit' => MeasurementConverter::UNIT_MM,

            /*
             * To get page information as well as the preview in one go. Note that the page information is retrieved
             * from the file regardless of what this option says, as the information is needed for previewing.
             */
            'returnPreviewsWithPagePdfBoxesInformation' => false,

            /*
             * If "false", ask yourself how you want to handle errors then. Setting this to "false" makes sense only, when
             * you set "returnPreviewsWithPagePdfBoxesInformation" to true, in which case the exceptions will be returned
             * to you for inspection. Otherwise, you just get no preview files with no clue what went wrong.
             *
             * DANGER: The code will still crash if the file can't even be opened (malformed file or with password). I
             * recommend you check the "hopeless errors" case yourself before you call this method.
             */
            'crashOnPreviewingErrors' => true,

            /*
             * Set to "false" if you want to short-circuit previewing if the file appears to open with warnings (cpdf-wise).
             * It's great in conjunction with "returnPreviewsWithPagePdfBoxesInformation", where you can still get the
             * pages' geometry back despite the warnings.
             */
            'attemptPreviewingDespitePdfWarnings' => true,

            /*
             * Whether to render pngs with white background or transparent background
             */
            'withTransparency' => false,
        ], $options);

        if (self::RENDERING_INTERMEDIARY_BITMAP_SIZE_PX < $options['previewSizePx']) {
            throw new \InvalidArgumentException(sprintf("Can't render a pdf page preview that's larger than `%d` px", self::RENDERING_INTERMEDIARY_BITMAP_SIZE_PX));
        }

        $pdfBoxesInformation = $this->cpdfPdfInformationExtractor->readPdfBoxesInformationOfPageInFile($pdfFile, $pageNumber);

        /*
         * Warnings during reading box information
         */
        if (
            !$options['attemptPreviewingDespitePdfWarnings']
            && $pdfBoxesInformation->getCpdfErrorOutput()
        ) {
            $runtimeException = new \RuntimeException("Previewing skipped due to warnings during page information retrieval. Warnings output: `{$pdfBoxesInformation->getCpdfErrorOutput()}`");

            if ($options['crashOnPreviewingErrors']) {
                throw $runtimeException;
            }

            return $options['returnPreviewsWithPagePdfBoxesInformation']
                ? PreviewFileAndPagePdfBoxesInformation::createForFailedPreview($pdfBoxesInformation, $runtimeException)
                : null;
        }

        /*
         * Calculate rendering DPI based on the PDF artwork and - if provided -
         * the relative dimensions as well
         */
        $longestRelativeDimensionsSideInches = 0.0;
        $relativeDimensionsWidth = (float) $options['relativeDimensionsWidth'];
        $relativeDimensionsHeight = (float) $options['relativeDimensionsHeight'];
        if ($relativeDimensionsWidth && $relativeDimensionsHeight) {
            $relativeDimensionsWidthInches = $this->measurementConverter->getConversion(
                $relativeDimensionsWidth,
                $options['relativeDimensionsUnit'],
                MeasurementConverter::UNIT_IN
            );
            $relativeDimensionsHeightInches = $this->measurementConverter->getConversion(
                $relativeDimensionsHeight,
                $options['relativeDimensionsUnit'],
                MeasurementConverter::UNIT_IN
            );
            $longestRelativeDimensionsSideInches =
                $relativeDimensionsWidthInches > $relativeDimensionsHeightInches
                    ? $relativeDimensionsWidthInches
                    : $relativeDimensionsHeightInches
            ;
        }

        $longestMediaBoxSideInches = $this->measurementConverter->getConversion(
            $pdfBoxesInformation->getMediaBox()->getLongestSide(),
            MeasurementConverter::UNIT_PT,
            MeasurementConverter::UNIT_IN
        );
        if ($longestRelativeDimensionsSideInches > $longestMediaBoxSideInches) {
            $longestMediaBoxSideInches = $longestRelativeDimensionsSideInches;
        }

        /*
         * Calculate the dpi that will produce the intermediary bitmap's size. Don't go below 5 dpi.
         */
        $renderingDpi = ceil(self::RENDERING_INTERMEDIARY_BITMAP_SIZE_PX / $longestMediaBoxSideInches);
        if ($renderingDpi < 5) {
            $renderingDpi = 5;
        }

        /*
         * Calculate the relative dimensions width and height in pixels
         */
        $relativeDimensionsWidthPx = $relativeDimensionsHeightPx = null;
        if ($relativeDimensionsWidth && $relativeDimensionsHeight) {
            $relativeDimensionsWidthPx = $relativeDimensionsWidthInches * $renderingDpi;
            $relativeDimensionsHeightPx = $relativeDimensionsHeightInches * $renderingDpi;
        }
        $options['relativeDimensionsHeightPx'] = $relativeDimensionsHeightPx;
        $options['relativeDimensionsWidthPx'] = $relativeDimensionsWidthPx;

        /*
         * Preview
         */
        $previewProcessException = null;

        try {
            $this->createDownscaledPreviewFromPdf(
                $pdfFile,
                $options,
                $pageNumber,
                $renderingDpi,
                $outputFile
            );
        } catch (\Exception $exception) {
            /*
             * Crashes are handled outside of try-catch.
             */
            $previewProcessException = $exception;
        }

        /*
         * Handle optional preview crash
         */
        if (
            $options['crashOnPreviewingErrors']
            && $previewProcessException
        ) {
            throw $previewProcessException;
        }

        if ($options['returnPreviewsWithPagePdfBoxesInformation']) {
            return $previewProcessException
                ? PreviewFileAndPagePdfBoxesInformation::createForFailedPreview($pdfBoxesInformation, $previewProcessException)
                : PreviewFileAndPagePdfBoxesInformation::createForSuccessfulPreview($pdfBoxesInformation, $outputFile);
        }

        return $previewProcessException ? null : $outputFile;
    }

    /**
     * @param File $pdfFile
     * @param string|null $pathToOutput Path to output all files. Pass null if you're constructing the output files
     *  yourself.
     * @param array $options
     * @return File[]|PreviewFileAndPagePdfBoxesInformation[] See $options.
     */
    public function generatePagePreviews(File $pdfFile, $pathToOutput = null, array $options = [])
    {
        $options = array_merge([
            'previewSizePx' => 200,

            /*
             * In seconds.
             *
             * Provide timeout for timing out individual previewing processes.
             * Provide cumulative timeout for timing out the whole previewing process (effectively: this method's wall time).
             *
             * Both can be defined at the same time, in which case each page can't take longer than "timeout" amount of
             * time to preview AND all the previewing process can't take longer than "cumulativeTimeout" amount of time.
             */
            'timeout' => 60,
            'cumulativeTimeout' => null,

            /**
             * @var int|null Pages after this number won't be previewed. Useful to avoid getting pwned.
             */
            'maxPagesPreviews' => null,

            /*
             * Override if you want to construct output files yourself. Your override must return an instance of a File.
             */
            'outputFileFactoryFn' => function ($pathToOutput, $pageNumber) {
                return new File(sprintf('%s/%d.png', $pathToOutput, $pageNumber), false);
            },

            /*
             * See the option for ::generatePreviewForPage()
             */
            'returnPreviewsWithPagePdfBoxesInformation' => false,

            /*
             * See the option for ::generatePreviewForPage()
             */
            'crashOnPreviewingErrors' => true,

            /*
             * See the option for ::generatePreviewForPage()
             */
            'attemptPreviewingDespitePdfWarnings' => true,

            /*
             * See the option for ::generatePreviewForPage()
             */
            'withTransparency' => false,

            /**
             * @var PdfInformation|null If you have an instance of pdf information for the supplied file already, you
             *  can optimise this function by providing it as an option. Note that providing pdf information not for the
             *  pdf file in question, is an undefined behaviour.
             */
            'pdfInformation' => null,

            /**
             * @var callable Provide this option, if you'd like to track the progress of previewing.
             */
            'previewingProgressFn' => function ($previewingProgressPercentage) {}
        ], $options);

        if (self::RENDERING_INTERMEDIARY_BITMAP_SIZE_PX < $options['previewSizePx']) {
            throw new \InvalidArgumentException(sprintf("Can't render a pdf page preview that's larger than `%d` px", self::RENDERING_INTERMEDIARY_BITMAP_SIZE_PX));
        }

        $pdfInformation = $options['pdfInformation'] ?: $this->cpdfPdfInformationExtractor->readPdfInformation($pdfFile);

        $pagesCount = $pdfInformation->getPagesCountOrThrow();
        $maxPageNumberToPreview = $options['maxPagesPreviews'] < $pagesCount ? $options['maxPagesPreviews'] : $pagesCount;

        /*
         * Timeout calculating function
         */
        $calculatePreviewingTimeoutFn = !$options['cumulativeTimeout']
            ? static function () use ($options) { return $options['timeout']; }
            : static function () use ($options) {
                static $previewingFinishTimestamp = null;

                if (!$previewingFinishTimestamp) {
                    $previewingFinishTimestamp = time() + $options['cumulativeTimeout'];
                }

                $remainingPreviewingTimeSeconds = $previewingFinishTimestamp - time();

                /*
                 * "Cleverly" don't allow the remaining time to evaluate less than 1. I rely on Symfony process to throw
                 * the timeout exception when the selected process timeout is 1 second.
                 *
                 * Note: this is important this works this way, so 'crashOnPreviewingErrors':false still not crashes
                 * regardless of the fact that the time limit was exceeded.
                 */
                if ($remainingPreviewingTimeSeconds < 1) {
                    $remainingPreviewingTimeSeconds = 1;
                }

                /*
                 * Use the single page timeout if if it's smaller than the remaining cumulative timeout.
                 */
                if ($remainingPreviewingTimeSeconds > $options['timeout']) {
                    $remainingPreviewingTimeSeconds = $options['timeout'];
                }

                return $remainingPreviewingTimeSeconds;
            };

        /** @var File[]|PreviewFileAndPagePdfBoxesInformation[] $results */
        $results = [];

        /*
         * Preview
         */
        for ($pageNumber = 1; $pageNumber <= $maxPageNumberToPreview; $pageNumber ++) {
            /*
             * Previewing progress
             */
            $options['previewingProgressFn']((int) (100 * $pageNumber / $maxPageNumberToPreview));

            $previewingTimeout = $calculatePreviewingTimeoutFn();

            /*
             * Output file
             */
            $outputFile = $options['outputFileFactoryFn']($pathToOutput, $pageNumber);

            /*
             * Preview
             */
            $result = $this->generatePreviewForPage($pdfFile, $outputFile, $pageNumber, [
                'previewSizePx' => $options['previewSizePx'],
                'returnPreviewsWithPagePdfBoxesInformation' => $options['returnPreviewsWithPagePdfBoxesInformation'],
                'crashOnPreviewingErrors' => $options['crashOnPreviewingErrors'],
                'attemptPreviewingDespitePdfWarnings' => $options['attemptPreviewingDespitePdfWarnings'],
                'withTransparency' => $options['withTransparency'],
                'timeout' => $previewingTimeout,
            ]);

            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param File $pdfFile
     * @param array $options
     * @param int $pageNumber
     * @param int $renderingDpi
     * @param File $outputFile
     * @return void
     */
    private function createDownscaledPreviewFromPdf(File $pdfFile, array $options, $pageNumber, $renderingDpi, File $outputFile)
    {
        $previewProcess = $this->buildProcessForHighResPreview(
            $pdfFile,
            $pageNumber,
            $renderingDpi,
            $outputFile,
            $options
        );

        $downScaleProcess = $this->buildProcessForDownscalePreview(
            $outputFile,
            $options['previewSizePx'],
            $outputFile,
            $options['relativeDimensionsWidthPx'],
            $options['relativeDimensionsHeightPx']
        );

        SymfonyProcessRunner::runSymfonyProcessesWithTimeout([
            $previewProcess,
            $downScaleProcess,
        ], $options['timeout']);
    }

    /**
     * The provided File must be a PDF.
     *
     * @param File $inputFile
     * @param int $pageNumber
     * @param int $renderingDpi
     * @param File $outputFile
     * @param array $options Same as for ::generatePagePreviews()
     * @return Process
     */
    private function buildProcessForHighResPreview(File $inputFile, $pageNumber, $renderingDpi, File $outputFile, array $options = [])
    {
        return new Process(sprintf(
            implode(' ', [
                'exec %1$s -dSAFER -dBATCH -dNOPAUSE -sDEVICE=%2$d',
                '-dFirstPage=%3$d -dLastPage=%4$d',
                '-dTextAlphaBits=2 -dGraphicsAlphaBits=2',
                '-r%5$d -sOutputFile=%6$s %7$s'
            ]),
            $this->pathToGhostscript,
            $options['withTransparency'] ? 'pngalpha' : 'png16m',
            $pageNumber,
            $pageNumber,
            $renderingDpi,
            escapeshellarg($outputFile->getPathname()),
            escapeshellarg($inputFile->getPathname())
        ));
    }

    /**
     * This provided File must already be converted to a PNG.
     *
     * @param File $inputFile
     * @param int $previewSizePx
     * @param File $outputFile
     * @return Process
     */
    private function buildProcessForDownscalePreview(
        File $inputFile,
        $previewSizePx,
        File $outputFile,
        $relativeDimensionsWidthPx = null,
        $relativeDimensionsHeightPx = null
    ) {
        return new Process(sprintf(
            implode(' ', array_filter([
                'exec %1$s',
                ($relativeDimensionsHeightPx && $relativeDimensionsWidthPx)
                    ? '-gravity Center -extent %5$dx%6$d+0+0 -crop %5$dx%6$d+0+0'
                    : null,
                '-resize %2$dx%2$d png:%3$s png:%4$s',
            ])),
            $this->pathToConvert,
            $previewSizePx,
            escapeshellarg($inputFile->getPathname()),
            escapeshellarg($outputFile->getPathname()),
            $relativeDimensionsWidthPx,
            $relativeDimensionsHeightPx
        ));
    }
}
