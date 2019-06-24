<?php

namespace Printed\Common\PdfTools\Cpdf;

use Printed\Common\PdfTools\Cpdf\Exception\CpdfException;

class CpdfBinaryConfiguration
{
    /** @var string */
    private $path;

    /** @var string */
    private $filename;

    /**
     * @param string $binaryPath
     */
    private function __construct($binaryPath)
    {
        $pathInfo = pathinfo($binaryPath);

        $this->path = $pathInfo['dirname'];
        $this->filename = $pathInfo['basename'];
    }

    /**
     * @param string $binaryPath
     *
     * @return CpdfBinaryConfiguration
     *
     * @throws CpdfException
     */
    public static function create($binaryPath)
    {
        if (!file_exists($binaryPath)) {
            throw new CpdfException(sprintf('CPDF binary path not found (path: %s).', $binaryPath));
        }

        if (!is_executable($binaryPath)) {
            throw new CpdfException('CPDF binary path is not executable.');
        }

        return new static($binaryPath);
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return string
     */
    public function getFilename()
    {
        return $this->filename;
    }
}
