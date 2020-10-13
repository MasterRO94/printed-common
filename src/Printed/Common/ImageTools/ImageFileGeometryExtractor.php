<?php

namespace Printed\Common\ImageTools;

use Printed\Common\ImageTools\Enum\ImageMagickImageOrientation;
use Printed\Common\ImageTools\ValueObject\ImageFileGeometry;
use Printed\Common\PdfTools\Utils\Geometry\PlaneGeometry\Rectangle;
use Printed\Common\PdfTools\Utils\MeasurementConverter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Extracts the width and height of an image that imagemagick can open.
 *
 * Q: Why a whole class for such a petty task that I can do by at least 3 other different ways myself?
 * A: php's \Imagick and "identify" spend ridiculous amount of time while reading this information. The bigger the file
 *    the longer it takes. This class uses some tricks to query just the dimensions and nothing else, which has proven
 *    to work. UPDATE: note that jpegs now require that orientation is read. Unlike querying image dimensions, this may
 *    take time, however it is still restricted time-wise so it's still somewhat safe to assume that reading the dimensions
 *    won't time-out your http request.
 */
class ImageFileGeometryExtractor
{
    const IMAGEMAGICK_UNIT_UNDEFINED = 'Undefined';
    const IMAGEMAGICK_UNIT_PIXELS_PER_INCH = 'PixelsPerInch';
    const IMAGEMAGICK_UNIT_PIXELS_PER_CM = 'PixelsPerCentimeter';

    /** @var MeasurementConverter */
    private $measurementConverter;

    /** @var LoggerInterface */
    private $logger;

    /** @var array */
    private $options;

    public function __construct(
        MeasurementConverter $measurementConverter,
        array $options = []
    ) {
        $options = array_merge([
            /*
             * Note that the output of "identify" varies between imagemagick versions. This class follows (and enforces)
             * the output of "pdc-identify". To put it in context: older imagemagick versions output "[number] [unit]" for
             * "%x", whereas the newer versions output "[number]" for "%x" and introduce "%U" for outputting units.
             */
            'imageMagickIdentifyCommand' => 'pdc-identify',

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

        $this->measurementConverter = $measurementConverter;
        $this->logger = new NullLogger();
        $this->options = $options;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param File $file
     * @param array $options
     * @return ImageFileGeometry
     */
    public function getImageFileGeometry(File $file, array $options = [])
    {
        $options = array_merge([
            /*
             * Will extract the first frame/layer/whatever from the raster file only. This is a noop for flat files.
             * This should always be enabled for extra speed. It was introduced to debug an nfs-syncing issue.
             */
            'forcefullyReadTheFirstFrameOnly' => true,
        ], $options);

        /*
         * Note the following:
         *
         * 1. -format "%[width]x%[height] %[resolution.x]x%[resolution.y] %[units]"
         * 2. -format "%wx%h %xx%y %U"
         *
         * Both are the same, but only the second form runs instantaneously
         *
         * Note #2: Only the first frame/page/picture is selected for inspection (see the [0] at the end of the command).
         * This obviously can be improved, however ask yourself why you ended up using this class against multi-page
         * files in the first place. Use CpdfPdfInformationExtractor for inspecting pdfs.
         *
         * DANGER: Do not use sprintf here to avoid having to escape the % signs in the identify command
         */
        $processCommand = 'exec ' . $this->options['imageMagickIdentifyCommand'] . ' -format "%w\n%h\n%x\n%y\n%U\n" ' . escapeshellarg($file->getPathname());

        if ($options['forcefullyReadTheFirstFrameOnly']) {
            $processCommand .= '[0]';
        }

        $identifyProcess = new Process(
            $processCommand,
            null,
            null,
            null,
            20
        );

        $identifyProcess->mustRun();

        $processOutputParts = explode(PHP_EOL, $identifyProcess->getOutput());

        /*
         * Assert the amount of output
         */
        if (
            /*
             * DANGER: I need this function to crash on multi-page/layer/frame files due to not being easily able to determine
             * in pdcv1 whether pdc-convert produces 1 or many files, when converting this file. This was considered a
             * temporary fix at the point of writing this so I didn't want to spend more time investigating it. If it
             * causes problems at the time you're reading this, please make sure pdcv1 usage handles multi-page/layer raster
             * files correctly (at the time of writing this, it wasn't crashing and was carrying on assuming that the preview
             * was empty (implementation detail, don't ask))
             */
//            $options['forcefullyReadTheFirstFrameOnly']
            6 !== count($processOutputParts)
        ) {
            throw new \RuntimeException("Imagemagick identify command produced unexpected output: {$identifyProcess->getOutput()}");
        }

        /*
         * Case when jpeg: run the identify again to read the orientation. This has to be done separately because to read
         * the orientation, I have to use the "long form attribute" instead of the "short" one, which doesn't result
         * in instantaneous execution. The bottom line is that this cli command can timeout on large/complex jpegs.
         *
         * Note that the use case here is to deal with photos taken on mobile phones that are arguably the 100% of cases
         * when jpegs use the orientation feature. For this reason, when a cli timeout is hit for large/complex jpegs
         * (that would not have been created on mobile phones), the orientation is assumed to be "un-rotated". If a
         * professional creates a 200MB jpeg file in photoshop that uses the orientation feature, they really get what
         * they ask for, for attempting to be clever.
         *
         * The cli timeout value is also very small due to the assumption that the jpegs aren't large/complex.
         */
        $imageOrientation = ImageMagickImageOrientation::UNDEFINED;
        if ('jpeg' === $file->guessExtension()) {
            $imageOrientation = $this->getImageFileOrientation($file, $options);
        }

        $widthPx = (int) trim($processOutputParts[0]);
        $heightPx = (int) trim($processOutputParts[1]);

        /*
         * Note that defaulting to 72 is what imagemagick does, and what imagemagick does is what Brian follows, so that's
         * cool.
         */
        $resolutionHorizontal = (int) trim($processOutputParts[2]) ?: 72;
        $resolutionVertical = (int) trim($processOutputParts[3]) ?: 72;

        /**
         * @var string One of the following: Undefined, PixelsPerInch, PixelsPerCentimeter
         */
        $resolutionUnit = trim($processOutputParts[4]) ?: self::IMAGEMAGICK_UNIT_UNDEFINED;

        /*
         * Case when the image orientation is sideways: swap the width and height values.
         */
        if (ImageMagickImageOrientation::isOrientationSideWays($imageOrientation)) {
            list($widthPx, $heightPx) = [$heightPx, $widthPx];
            list($resolutionHorizontal, $resolutionVertical) = [$resolutionVertical, $resolutionHorizontal];
        }

        /*
         * Calculate the physical size.
         *
         * Since this is constantly causing confusion, please know this: the physical size is just a hint from the customer.
         * Brian's switch workflow follows this hint, but there's nothing stopping us from ignoring it. We can print their
         * raster files at any size we want. Obviously, the raster file will be stretched/shrunk in case we do it. That's why
         * pdc sometimes shows a dpi hint, which is a measure of how much we are going to stretch/shrink customers' files.
         *
         * Keep in mind that the dpi hint mentioned above doesn't need (or respects) the physical size hint from the file.
         * Only the pixel dimensions (and the physical size that _we_ choose) contributes to the dpi hint.
         */
        list($widthMm, $heightMm) = $this->calculatePhysicalDimensions(
            $widthPx,
            $heightPx,
            $resolutionHorizontal,
            $resolutionVertical,
            $resolutionUnit
        );

        return new ImageFileGeometry(
            $this->options['rectangleFactoryFn'](0, 0, $widthPx, $heightPx),
            $widthMm === null ? null : $this->options['rectangleFactoryFn'](0, 0, $widthMm, $heightMm)
        );
    }

    /**
     * @param File $file
     * @param array $options Same as for ::getImageFileGeometry()
     * @return string One of ImageMagickImageOrientation::*
     */
    private function getImageFileOrientation(File $file, array $options)
    {
        $processCommand = 'exec ' . $this->options['imageMagickIdentifyCommand'] . ' -format "%[orientation]\n" ' . escapeshellarg($file->getPathname());

        if ($options['forcefullyReadTheFirstFrameOnly']) {
            $processCommand .= '[0]';
        }

        $identifyProcess = new Process(
            $processCommand,
            null,
            null,
            null,
            5
        );

        try {
            $identifyProcess->mustRun();
        } catch (ProcessTimedOutException $exception) {
            $this->logger->error(sprintf(
                "Could not read image file's orientation using imagemagick due to cli timeout. Assuming the orientation is undefined and moving on. File: `%s`, file size: `%s`, actual exception message: `%s`",
                $file->getPathname(),
                number_format($file->getSize()),
                $exception->getMessage()
            ));

            return ImageMagickImageOrientation::UNDEFINED;
        }

        $processOutputParts = explode(PHP_EOL, $identifyProcess->getOutput());

        if (
            /*
             * DANGER: I need this function to crash on multi-page/layer/frame files due to not being easily able to determine
             * in pdcv1 whether pdc-convert produces 1 or many files, when converting this file. This was considered a
             * temporary fix at the point of writing this so I didn't want to spend more time investigating it. If it
             * causes problems at the time you're reading this, please make sure pdcv1 usage handles multi-page/layer raster
             * files correctly (at the time of writing this, it wasn't crashing and was carrying on assuming that the preview
             * was empty (implementation detail, don't ask))
             */
//            $options['forcefullyReadTheFirstFrameOnly']
            2 !== count($processOutputParts)
        ) {
            throw new \RuntimeException("Imagemagick identify command for retrieving image orientation value produced unexpected output: {$identifyProcess->getOutput()}");
        }

        return trim($processOutputParts[0]) ?: ImageMagickImageOrientation::UNDEFINED;
    }

    /**
     * @param int $widthPx
     * @param int $heightPx
     * @param int $resolutionHorizontal
     * @param int $resolutionVertical
     * @param string $resolutionUnit
     * @return array Tuple of structure: [ widthMm?: float, heightMm?: float ]
     */
    private function calculatePhysicalDimensions($widthPx, $heightPx, $resolutionHorizontal, $resolutionVertical, $resolutionUnit)
    {
        /*
         * If for some reason the resolutions ended up to be 0, then assume I can't get physical size. At the very least,
         * I can't divide by zero.
         */
        if (!$resolutionHorizontal && !$resolutionVertical) {
            return [null, null];
        }

        $widthMm = null;
        $heightMm = null;

        switch ($resolutionUnit) {
            case self::IMAGEMAGICK_UNIT_PIXELS_PER_CM:
                $widthMm = $this->measurementConverter->getConversion(
                    $widthPx / $resolutionHorizontal, MeasurementConverter::UNIT_CM, MeasurementConverter::UNIT_MM
                );

                $heightMm = $this->measurementConverter->getConversion(
                    $heightPx / $resolutionVertical, MeasurementConverter::UNIT_CM, MeasurementConverter::UNIT_MM
                );

                break;

            case self::IMAGEMAGICK_UNIT_PIXELS_PER_INCH:
                $widthMm = $this->measurementConverter->getConversion(
                    $widthPx / $resolutionHorizontal, MeasurementConverter::UNIT_IN, MeasurementConverter::UNIT_MM
                );

                $heightMm = $this->measurementConverter->getConversion(
                    $heightPx / $resolutionVertical, MeasurementConverter::UNIT_IN, MeasurementConverter::UNIT_MM
                );

                break;

            case self::IMAGEMAGICK_UNIT_UNDEFINED:
                /*
                 * I decided that I won't try to guess/default the unit if it's not provided in the file. This might
                 * need changing if someone complains. That's highly unlikely, though
                 */
                return [null, null];

            default:
                throw new \RuntimeException("Unrecognised image file resolution unit: `{$resolutionUnit}`");
        }

        return [$widthMm, $heightMm];
    }
}
