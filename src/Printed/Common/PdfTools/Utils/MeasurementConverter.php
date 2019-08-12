<?php

namespace Printed\Common\PdfTools\Utils;

/**
 * Class MeasurementConverter
 * @package Printed\PdfTools\Utils
 * @todo Take out the image quality checking logic to a separate class
 */
class MeasurementConverter
{
    const QUALITY_CHECK_RESULT_POOR = 'POOR';
    const QUALITY_CHECK_RESULT_GOOD = 'GOOD';
    const QUALITY_CHECK_RESULT_STANDARD = 'STANDARD';
    const QUALITY_CHECK_RESULT_PERFECT = 'PERFECT';

    const MM_TO_CM = 10;
    const MM_TO_IN = 0.0393700787;
    const MM_TO_PT = 2.83464567;
    const IN_TO_MM = 25.4; // millimeters per inch
    const IN_TO_CM = 2.54; // centimeters per inch
    const PT_TO_MM = 0.352777778;
    const PT_TO_IN = 0.0138889;

    const UNIT_MM = "mm";
    const UNIT_IN = "in";
    const UNIT_CM = "cm";
    const UNIT_PX = "px";
    const UNIT_NO_UNIT = "no-unit";

    /** @const PostScript Points */
    const UNIT_PT = "pt";

    const FILE_SIZE = 1048576; // value to divide by to calculate file sizes

    private $idealResolution = 300;

    /**
     * @param $imgW
     * @param $imgH
     * @param $dpi
     * @param $desW
     * @param $desH
     * @param string $unit
     * @param array $options Options:
     *      additionalScaleRatio: float // If you resized the original artwork in
     *          an Editor (the one on the one step wizard), then you want to put
     *          the scale ratio from the Editor into this variable. This will alter
     *          the quality calculation to take into account the scale done in the Editor.
     *          Pro tip: Editor scale of 1.0 (100%) means that the artwork uses only
     *          the `initial scale` which is the one applied on the artwork to fit
     *          into the target paper size ($desW, $desH), without whitespace.
     *
     *          IMPORTANT: it's a ratio, not percent! So instead of saying 125%, say 1.25.
     *          Thank you.
     *      withLowerCaseKeys: bool // Say `true` if you want to have the return
     *          hashmap value with lowercase keys.
     * @return array
     */
    public function getImageQuality($imgW, $imgH, $dpi, $desW, $desH, $unit = "mm", array $options = array())
    {
        $options = array_merge(array(
            'additionalScaleRatio' => null,
            'withLowerCaseKeys' => false,
        ), $options);

        $dimensions = $this->getDimensions($unit, $imgW, $imgH, $dpi);
        $percentage = $this->getScore("in", $desW, $desH, $dimensions);

        if ($options['additionalScaleRatio']) {
            $percentage /= $options['additionalScaleRatio'];
        }

        $score = $this->getThreshold($percentage);

        return $options['withLowerCaseKeys']
            ? array(
                "percentage" => $percentage,
                "scale" => $score
            )
            : array(
                "PERCENTAGE" => $percentage,
                "SCALE" => $score
            );

    }

    /**
     * @param $unit - unit of measurement (mm, in, px)
     * @param $imgW - width
     * @param $imgH - height
     * @param $dpi - pixels per inch value
     * @return array - the recommended dimensions, percentage score and threshold scale value
     */
    public function getDimensions($unit, $imgW, $imgH, $dpi)
    {
        // convert input to inches (in)
        switch ($unit) {
            case "mm":
                $widthIn = $imgW * self::MM_TO_IN;
                $heightIn = $imgH * self::MM_TO_IN;
                break;
            case "in":
                $widthIn = $imgW;
                $heightIn = $imgH;
                break;
            case "px":
            default:
                /*
                 * The division by dpi is reverted in the next few lines. It's done
                 * here, to keep life simpler
                 */
                $widthIn = $imgW / $dpi;
                $heightIn = $imgH / $dpi;
                break;
        }

        // convert to pixels
        $widthPx = $this->convertToPixels($widthIn, $dpi, "in");
        $heightPx = $this->convertToPixels($heightIn, $dpi, "in");

        // get recommended values
        $recWidthIn = $widthPx / $this->idealResolution;
        $recHeightIn = $heightPx / $this->idealResolution;

        // create output array
        $recommended = array(
            "MAX_WIDTH" => $recWidthIn,
            "MAX_HEIGHT" => $recHeightIn
        );

        return $recommended;
    }

    /**
     * Get real world dimensions out of dimensions in px and given ppi.
     *
     * @param string $outputUnit In what real world unit you want the output values
     * @param int $imgW Image width in px
     * @param int $imgH Image height in px
     * @param int $ppi PPI of the image
     * @return array ['width' => float, 'height' => float ]
     */
    public function getRealDimensionsFromPixels($outputUnit, $imgW, $imgH, $ppi)
    {
        $inW = $imgW / $ppi;
        $inH = $imgH / $ppi;

        $widthOutputUnit = $this->getConversion($inW, "in", $outputUnit);
        $heightOutputUnit = $this->getConversion($inH, "in", $outputUnit);

        return array(
            'width' => $widthOutputUnit,
            'height' => $heightOutputUnit
        );
    }

    /**
     * @param string $unit
     * @param $imgW
     * @param $imgH
     * @param $recommended - array created ideally from the getDimensions method
     * @return float
     */
    public function getScore($unit = "in", $imgW, $imgH, $recommended)
    {
        // get recommended inches
        $recWidth = $recommended['MAX_WIDTH'];
        $recHeight = $recommended['MAX_HEIGHT'];

        switch ($unit) {
            case "in":
                $widthIn = $imgW;
                $heightIn = $imgH;
                break;
            case "mm":
                $widthIn = $imgW * self::MM_TO_IN;
                $heightIn = $imgH * self::MM_TO_IN;
                break;
            default:
                $widthIn = $imgW;
                $heightIn = $imgH;
        }

        /**
         * Not for future generations about what is being done here.
         * The scale ratios below tell how big is the recommended size (width and height)
         * in relation to the target size ($widthIn, $heightIn).
         *
         * So e.g. scale ratio of 100% for the width tells, that the recommended
         * width is exactly the same as the target paper size width.
         *
         * Another example: scale ratio of 80% for the width tells, that the recommended
         * width is smaller than the the target width, so the artwork will need to
         * be upscaled to fit the target width, which lessens the quality.
         *
         * Another useful info is: what the recommended size is. It's the size of
         * the artwork provided by the user, when we would print it using the
         * $this->idealResolution (which is carefully selected by us, and at the
         * time of writing its value is 200dpi; keep in mind, that we don't print
         * @ 200dpi).
         */
        $scaleRatioPercentWidth = (100 / $widthIn) * $recWidth;
        $scaleRatioPercentHeight = (100 / $heightIn) * $recHeight;

        /**
         * An important thing happens here. We're returning the worse percentage here.
         * This is connected with the fact, that we will always upscale (or downscale) user's artwork
         * to not allow whitespace. And this is achieved by picking up the smaller dimension
         * of the original artwork, and upscaling it until it fits perfectly into
         * the target paper size. Cropping is also involved while doing it, but
         * it's not relevant to quality score calculation. Back to the original problem:
         * we return the worse percentage here, because this is the one for the smaller
         * dimension of the original artwork.
         */
        return $scaleRatioPercentWidth > $scaleRatioPercentHeight
            ? $scaleRatioPercentHeight
            : $scaleRatioPercentWidth;
    }

    /**
     * @param $quality
     * @param string $product
     * @return string
     */
    public function getThreshold($quality, $product = "small")
    {
        switch ($product) {
            case "large":
                switch ($quality) {
                    case ($quality < 20):
                        $scale = self::QUALITY_CHECK_RESULT_POOR;
                        break;
                    case ($quality < 50):
                        $scale = self::QUALITY_CHECK_RESULT_STANDARD;
                        break;
                    case ($quality < 80):
                        $scale = self::QUALITY_CHECK_RESULT_GOOD;
                        break;
                    default:
                        $scale = self::QUALITY_CHECK_RESULT_PERFECT;
                        break;
                }
                break;
            default:
                switch ($quality) {
                    case ($quality < 50):
                        $scale = self::QUALITY_CHECK_RESULT_POOR;
                        break;
                    case ($quality < 70):
                        $scale = self::QUALITY_CHECK_RESULT_STANDARD;
                        break;
                    case ($quality < 100):
                        $scale = self::QUALITY_CHECK_RESULT_GOOD;
                        break;
                    default:
                        $scale = self::QUALITY_CHECK_RESULT_PERFECT;
                        break;
                }
                break;
        }

        return $scale;
    }

    /**
     * @param $pixelW
     * @param $pixelH
     * @return array
     */
    public function getFileSize($pixelW, $pixelH)
    {
        $filesizes = array(
            "RGB" => round(($pixelW * $pixelH * 3) / self::FILE_SIZE, 1),
            "CMYK" => round(($pixelW * $pixelH * 4) / self::FILE_SIZE, 1)
        );

        return $filesizes;
    }

    /**
     * @param float $val
     * @param string $from One of self::UNIT_*
     * @param string $to One of self::UNIT_*
     * @return float
     */
    public static function getConversion($val, $from, $to)
    {
        /*
         * @todo Make this "static" when migrated to php7. In php5, the division operator (/) is a syntax error..
         */
        /* static */
        $conversionsFromToMultiplierTable = [
            self::UNIT_MM => [
                self::UNIT_MM => 1,
                self::UNIT_CM => (1 / self::MM_TO_CM),
                self::UNIT_IN => self::MM_TO_IN,
                self::UNIT_PT => self::MM_TO_PT,
            ],
            self::UNIT_CM => [
                self::UNIT_MM => self::MM_TO_CM,
                self::UNIT_CM => 1,
                self::UNIT_IN => self::MM_TO_IN * self::MM_TO_CM,
            ],
            self::UNIT_IN => [
                self::UNIT_MM => self::IN_TO_MM,
                self::UNIT_CM => (self::IN_TO_MM / self::MM_TO_CM),
                self::UNIT_IN => 1,
            ],
            self::UNIT_PT => [
                self::UNIT_IN => self::PT_TO_IN,
                self::UNIT_MM => self::PT_TO_MM,
            ],
        ];

        $from = strtolower($from);
        $to = strtolower($to);

        if (!isset($conversionsFromToMultiplierTable[$from][$to])) {
            throw new \InvalidArgumentException("Unsupported unit conversion from `{$from}` to `{$to}`.");
        }

        $newVal = $val * $conversionsFromToMultiplierTable[$from][$to];

        return $newVal;
    }

    /**
     * @param $value
     * @param $dpi
     * @param $from
     * @return float|null
     */
    public function convertToPixels($value, $dpi, $from)
    {
        $from = strtolower($from);

        $newVal = null;

        switch ($from) {
            case 'mm':
                $inches = $this->getConversion($value, 'mm', 'in');
                $newVal = $inches * $dpi;
                break;

            case 'in':
                $newVal = $value * $dpi;
                break;
        }

        return $newVal;
    }

    /**
     * @param int $widthPx
     * @param int $heightPx
     * @param int $resolutionHorizontal
     * @param int $resolutionVertical
     * @param string $resolutionUnit
     * @return array Tuple of structure: [ widthMm?: float, heightMm?: float ]
     */
    public function calculatePhysicalDimensions(
        $widthPx,
        $heightPx,
        $resolutionHorizontal,
        $resolutionVertical,
        $resolutionUnitPixelsPer
    ) {
        /*
         * If for some reason the resolutions ended up to be 0, then assume I can't get physical size. At the very least,
         * I can't divide by zero.
         */
        if (!$resolutionHorizontal && !$resolutionVertical) {
            return [null, null];
        }

        /*
         * If we don't recognise the units, fail silently.
         */
        if (!in_array($resolutionUnitPixelsPer, [
            self::UNIT_CM,
            self::UNIT_IN,
            self::UNIT_MM,
            self::UNIT_PT,
        ])) {
            return [null, null];
        }

        return [
            $this->getConversion(
                $widthPx / $resolutionHorizontal,
                $resolutionUnitPixelsPer,
                self::UNIT_MM
            ),

            $this->getConversion(
                $heightPx / $resolutionVertical,
                $resolutionUnitPixelsPer,
                self::UNIT_MM
            ),
        ];
    }

    /**
     * @return int
     */
    public function getIdealResolution()
    {
        return $this->idealResolution;
    }

    /**
     * @param int $idealResolution
     * @return MeasurementConverter
     */
    public function setIdealResolution($idealResolution)
    {
        $this->idealResolution = $idealResolution;

        return $this;
    }
}
