<?php

namespace Printed\PdfTools;

class BinaryPathConfiguration
{
    /** @var string */
    private $ghostscript;

    /** @var string */
    private $convert;

    public function __construct(
        $ghostscript,
        $convert
    ) {
        $this->ghostscript = $ghostscript;
        $this->convert = $convert;
    }

    /**
     * @return mixed
     */
    public function getGhostscript()
    {
        return $this->ghostscript;
    }

    /**
     * @return mixed
     */
    public function getConvert()
    {
        return $this->convert;
    }
}