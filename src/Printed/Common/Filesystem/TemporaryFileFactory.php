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
        string $temporaryDirPath
    ) {
        $this->filesystem = $filesystem;
        $this->temporaryDirPath = $temporaryDirPath;
    }

    /**
     * {@inheritDoc}
     */
    public function createTemporaryFile(string $tempFilePath = null): TemporaryFile
    {
        return new TemporaryFile(
            $this->filesystem,
            $this->temporaryDirPath,
            $tempFilePath
        );
    }
}