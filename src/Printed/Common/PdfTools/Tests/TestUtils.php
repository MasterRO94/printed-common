<?php

namespace Printed\Common\PdfTools\Tests;

class TestUtils
{
    /**
     * @return string
     */
    public static function getProjectDir()
    {
        return realpath(__DIR__ . '/../../../../..');
    }

    /**
     * @return string
     */
    public static function getPathToCpdfBinary()
    {
        $binaryName = getenv('ALPINE') ? 'cpdf-alpine' : 'cpdf';

        return sprintf('%s/vendor/bin/%s', self::getProjectDir(), $binaryName);
    }
}
