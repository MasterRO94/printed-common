<?php

namespace Printed\PdfTools;

use Printed\PdfTools\Cpdf\CpdfPdfInformationExtractor;
use Printed\PdfTools\Utils\MeasurementConverter;
use Printed\PdfTools\Utils\SymfonyProcessRunner;
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
     * @todo Dedupe code.
     *
     * @param File $pdfFile
     * @param File $outputFile
     * @param int $pageNumber
     * @param array $options
     * @return File
     */
    public function generatePreviewForPage(File $pdfFile, File $outputFile, $pageNumber, array $options = [])
    {
        $options = array_merge([
            'previewSizePx' => 200,
            /*
             * In seconds.
             */
            'timeout' => 60,
        ], $options);

        if (self::RENDERING_INTERMEDIARY_BITMAP_SIZE_PX < $options['previewSizePx']) {
            throw new \InvalidArgumentException(sprintf("Can't render a pdf page preview that's larger than `%d` px", self::RENDERING_INTERMEDIARY_BITMAP_SIZE_PX));
        }

        $pdfBoxesInformation = $this->cpdfPdfInformationExtractor->readPdfBoxesInformationOfFirstPageInFile($pdfFile);

        $longestMediaBoxSidePt = $this->measurementConverter->getConversion(
            $pdfBoxesInformation->getMediaBox()->getLongestSide(),
            MeasurementConverter::UNIT_PT,
            MeasurementConverter::UNIT_IN
        );

        /*
         * Calculate the dpi that will produce the intermediary bitmap's size. Don't go below 5 dpi.
         */
        $renderingDpi = ceil(self::RENDERING_INTERMEDIARY_BITMAP_SIZE_PX / $longestMediaBoxSidePt);
        if ($renderingDpi < 5) {
            $renderingDpi = 5;
        }

        $this->createDownscaledPreviewFromPdf($pdfFile, $options, $pageNumber, $renderingDpi, $outputFile);

        return $outputFile;
    }

    /**
     * @todo Dedupe code.
     *
     * @param File $pdfFile
     * @param string $pathToOutput Path to output all files
     * @param array $options
     * @return File[]
     */
    public function generatePagePreviews(File $pdfFile, $pathToOutput, array $options)
    {
        $options = array_merge([
            'previewSizePx' => 200,
            /*
             * In seconds.
             */
            'timeout' => 60,
        ], $options);

        if (self::RENDERING_INTERMEDIARY_BITMAP_SIZE_PX < $options['previewSizePx']) {
            throw new \InvalidArgumentException(sprintf("Can't render a pdf page preview that's larger than `%d` px", self::RENDERING_INTERMEDIARY_BITMAP_SIZE_PX));
        }

        $pagesCount = $this->cpdfPdfInformationExtractor->getPagesCount($pdfFile);
        $pdfBoxesInformation = $this->cpdfPdfInformationExtractor->readPdfBoxesInformationOfFirstPageInFile($pdfFile);

        $longestMediaBoxSidePt = $this->measurementConverter->getConversion(
            $pdfBoxesInformation->getMediaBox()->getLongestSide(),
            MeasurementConverter::UNIT_PT,
            MeasurementConverter::UNIT_IN
        );

        /*
         * Calculate the dpi that will produce the intermediary bitmap's size. Don't go below 5 dpi.
         */
        $renderingDpi = ceil(self::RENDERING_INTERMEDIARY_BITMAP_SIZE_PX / $longestMediaBoxSidePt);
        if ($renderingDpi < 5) {
            $renderingDpi = 5;
        }

        $outputFiles = [];

        for ($pageNumber = 1; $pageNumber <= $pagesCount; $pageNumber ++) {
            $outputFile = new File(sprintf('%s/%d.png', $pathToOutput, $pageNumber), false);

            $this->createDownscaledPreviewFromPdf($pdfFile, $options, $pageNumber, $renderingDpi, $outputFile);

            $outputFiles[] = $outputFile;
        }

        return $outputFiles;
    }

    /**
     * @param File $pdfFile
     * @param array $options
     * @param int $pageNumber
     * @param int $renderingDpi
     * @param File $outputFile
     * @return void
     */
    private function createDownscaledPreviewFromPdf(File $pdfFile, array $options, $pageNumber, $renderingDpi, $outputFile)
    {
        $previewProcess = $this->buildProcessForHighResPreview(
            $pdfFile,
            $pageNumber,
            $renderingDpi,
            $outputFile
        );

        $downScaleProcess = $this->buildProcessForDownscalePreview(
            $outputFile,
            $options['previewSizePx'],
            $outputFile
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
     * @return Process
     */
    private function buildProcessForHighResPreview(File $inputFile, $pageNumber, $renderingDpi, File $outputFile)
    {
        return new Process(sprintf(
            'exec %1$s -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -dFirstPage=%2$d -dLastPage=%3$d -dTextAlphaBits=2 -dGraphicsAlphaBits=2 -r%4$d -sOutputFile=%5$s %6$s',
            $this->pathToGhostscript,
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
    private function buildProcessForDownscalePreview(File $inputFile, $previewSizePx, File $outputFile)
    {
        return new Process(sprintf(
            'exec %1$s -resize %2$dx%2$d png:%3$s png:%4$s',
            $this->pathToConvert,
            $previewSizePx,
            escapeshellarg($inputFile->getPathname()),
            escapeshellarg($outputFile->getPathname())
        ));
    }
}
