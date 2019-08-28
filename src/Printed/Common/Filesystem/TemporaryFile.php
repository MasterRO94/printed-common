<?php

namespace Printed\Common\Filesystem;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

class TemporaryFile extends File
{
    /** @var Filesystem  */
    protected $filesystem;

    /** @var resource */
    protected $filePointer;

    /** @var string */
    protected $temporaryDirPath;

    /** @var bool */
    private $deleteFileOnDestruct;

    /**
     * @param Filesystem $filesystem
     * @param string $temporaryDirPath
     * @param string|null $tempFilePath If provided, this file will become "managed" by this class.
     *      Otherwise a new random file will be generated in /tmp folder
     */
    public function __construct(
        Filesystem $filesystem,
        $temporaryDirPath,
        $tempFilePath = null
    ) {
        if (!$tempFilePath) {
            $tempFilePath = tempnam($temporaryDirPath, rand());

            if (!$tempFilePath) {
                throw new \RuntimeException('Could not create a temporary file in the tmp folder: ' . $temporaryDirPath);
            }
        }

        parent::__construct($tempFilePath);

        $this->filePointer = @fopen($tempFilePath, 'r+');
        if (!$this->filePointer) {
            throw new \RuntimeException(sprintf('Could not open a temp file: %s', $tempFilePath));
        }

        $this->filesystem = $filesystem;
        $this->temporaryDirPath = $temporaryDirPath;
        $this->deleteFileOnDestruct = true;
    }

    public function __destruct()
    {
        fclose($this->filePointer);

        if ($this->deleteFileOnDestruct) {
            $this->filesystem->remove($this->getFullPath());
        }
    }

    /**
     * @return string Full path to the file on the filesystem
     */
    public function getFullPath()
    {
        return $this->getPathname();
    }

    /**
     * @return resource
     */
    public function getFilePointer()
    {
        return $this->filePointer;
    }

    /**
     * @param string $content
     */
    public function writeContent($content)
    {
        fwrite($this->getFilePointer(), $content);
    }

    /**
     * @param string $tempSubDirectory This MUST have a forward slash at the beginning!
     *
     * @return TemporaryFile
     */
    public function moveToTempSubDirectoryAndKeep($tempSubDirectory)
    {
        $targetDirectory = sprintf('%s%s', $this->temporaryDirPath, $tempSubDirectory);

        return $this->move($targetDirectory);
    }

    /**
     * {@inheritdoc}
     */
    public function move($directory, $name = null, array $options = [])
    {
        $options = array_merge([

            /*
             * This is defaulted to `true`, so it behaves like the parent method. This is also
             * what you want in general.
             */
            'keepMovedFile' => true,

        ], $options);

        $file = parent::move($directory, $name);
        $temporaryFile = $this->createNewInstanceOfSelfAfterFileMove($file->getPathname());

        if ($options['keepMovedFile']) {
            $this->keepFile();
            $temporaryFile->keepFile();
        }

        return $temporaryFile;
    }

    /**
     * Do not remove the temp file. This has a really limited applicability.
     */
    public function keepFile()
    {
        $this->deleteFileOnDestruct = false;
    }

    /**
     * Create new instance of the same file, but sitting in different location.
     *
     * @param string $newFilePath
     *
     * @return TemporaryFile
     */
    protected function createNewInstanceOfSelfAfterFileMove($newFilePath)
    {
        return new self($this->filesystem, $this->temporaryDirPath, $newFilePath);
    }
}
