<?php

namespace Printed\Common\Filesystem;

use Symfony\Component\Filesystem\Filesystem;

class TemporaryFileFactory implements TemporaryFileFactoryInterface
{
    /** @var Filesystem */
    private $filesystem;

    /** @var string */
    private $temporaryDirPath;

    /**
     * @param Filesystem $filesystem
     * @param string $temporaryDirPath
     */
    public function __construct(
        Filesystem $filesystem,
        $temporaryDirPath
    ) {
        $this->filesystem = $filesystem;
        $this->temporaryDirPath = $temporaryDirPath;
    }

    /**
     * {@inheritDoc}
     */
    public function createTemporaryFile($tempFilePath = null)
    {
        return new TemporaryFile(
            $this->filesystem,
            $this->temporaryDirPath,
            $tempFilePath
        );
    }
}