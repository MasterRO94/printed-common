<?php

namespace Printed\Common\PdfTools\Cpdf;

use Printed\Common\PdfTools\Cpdf\Exception\CpdfException;

class CpdfBinaryValidator
{
    /**
     * @param string $binaryPath
     *
     * @throws CpdfException
     */
    public static function assertBinaryPath($binaryPath)
    {
        if (!file_exists($binaryPath)) {
            throw new CpdfException(sprintf('CPDF binary path not found (path: %s).', $binaryPath));
        }

        if (!is_executable($binaryPath)) {
            throw new CpdfException('CPDF binary path is not executable.');
        }
    }
}
