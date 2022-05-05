<?php

namespace Printed\Common\PdfTools\Cpdf;

use Printed\Common\Filesystem\TemporaryFile;
use Printed\Common\Filesystem\TemporaryFileFactoryInterface;
use Symfony\Component\HttpFoundation\File\File;

class CpdfPdfSplitterHelper
{
    /** @var CpdfPdfSplitter */
    private $cpdfPdfSplitter;

    /** @var TemporaryFileFactoryInterface */
    private $temporaryFileFactory;

    public function __construct(
        CpdfPdfSplitter $cpdfPdfSplitter,
        TemporaryFileFactoryInterface $temporaryFileFactory
    ) {
        $this->cpdfPdfSplitter = $cpdfPdfSplitter;
        $this->temporaryFileFactory = $temporaryFileFactory;
    }

    /**
     * @param File $pdfFile
     * @param array $options
     * @return TemporaryFile[]
     */
    public function splitPdfIntoTemporaryFiles(File $pdfFile, array $options = [])
    {
        $options = array_merge([
            'splitPdfOptions' => [],
        ], $options);

        /*
         * Split
         */
        $pdfPagesFiles = $this->cpdfPdfSplitter->split($pdfFile, $options['splitPdfOptions']);
        return $this->convertToTemporaryFiles($pdfPagesFiles);
    }

    /**
     * @param File $pdfFile
     * @param string $outputFileDirectory
     * @param string $outputFileName
     * @param int $chunks
     * @param int|null $timeoutSeconds
     *
     * @return TemporaryFile[]
     */
    public function chunkIntoTemporaryFiles(
        File $pdfFile,
        $outputFileDirectory,
        $outputFileName,
        $chunks,
        $timeoutSeconds
    ) {
        /*
         * Split into chunks
         */
        $pdfPagesFiles = $this->cpdfPdfSplitter->chunks(
            $pdfFile,
            $outputFileDirectory,
            $outputFileName,
            $chunks,
            $timeoutSeconds
        );
        return $this->convertToTemporaryFiles($pdfPagesFiles);
    }

    /**
     * @param File[] $files
     * @return TemporaryFile[]
     */
    private function convertToTemporaryFiles(array $files)
    {
        /*
         * Promote to TemporaryFile[]
         */
        $temporaryFiles = [];
        foreach ($files as $file) {
            $temporaryFiles[] = $this->temporaryFileFactory->createTemporaryFile(
                $file->getPathname()
            );
        }

        return $temporaryFiles;
    }
}