<?php

namespace Printed\Common\Filesystem;

interface TemporaryFileFactoryInterface
{
    /**
     * @param string|null $tempFilePath
     * @return TemporaryFile
     */
    public function createTemporaryFile($tempFilePath = null);
}