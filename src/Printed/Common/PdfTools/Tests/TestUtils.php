<?php

namespace Printed\Common\PdfTools\Tests;

class TestUtils
{
    public static function getProjectDir()
    {
        return realpath(__DIR__ . '/../../../../..');
    }
}
