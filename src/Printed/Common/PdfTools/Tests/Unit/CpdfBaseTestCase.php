<?php

namespace Printed\Common\PdfTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Printed\Common\PdfTools\Tests\TestUtils;

class CpdfBaseTestCase extends TestCase
{
    /**
     * @return string
     */
    protected function getPathToCpdfBinary()
    {
        $binaryName = getenv('ALPINE') ? 'cpdf-alpine' : 'cpdf';

        return sprintf('%s/vendor/bin/%s', TestUtils::getProjectDir(), $binaryName);
    }
}
