<?php

namespace Printed\Common\PdfTools\Tests;

use Symfony\Component\HttpFoundation\File\File;

class TestUtils
{
    /**
     * @return string
     */
    public static function getProjectDir()
    {
        static $projectDir = null;
        if ($projectDir) {
            return $projectDir;
        }

        return $projectDir = realpath(__DIR__ . '/../../../../..');
    }

    /**
     * @return string
     */
    public static function getPathToCpdfBinary()
    {
        $binaryName = getenv('ALPINE') ? 'cpdf-alpine' : 'cpdf';

        return sprintf('%s/vendor/bin/%s', self::getProjectDir(), $binaryName);
    }

    /**
     * Get a test file from "printed/common-test-files"
     *
     * @param string $testFileName
     * @return File
     */
    public static function getPrintedCommonTestFile($testFileName)
    {
        $projectDir = self::getProjectDir();

        /*
         * I'm not amazed with the hardcoded composer vendor subdir here. But you know, it worked at the time.
         */
        return new File("{$projectDir}/vendor/printed/common-test-files/pdf/{$testFileName}");
    }
}
