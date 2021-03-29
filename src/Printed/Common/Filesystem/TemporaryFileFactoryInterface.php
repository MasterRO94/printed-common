<?php

namespace Printed\Common\Filesystem;

interface TemporaryFileFactoryInterface
{
    public function createTemporaryFile(string $tempFilePath = null): TemporaryFile;
}