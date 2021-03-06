<?php

namespace VoTong\LaravelPDFMerger;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

class PDFMerger
{
    /**
     * Access the file system on an oop base
     *
     * @var Storage
     */
    protected $fileSystem = Storage::class;

    /**
     * @var string
     */
    protected $temporaryFolder = 'tmp/';

    /**
     * Hold all the files which will be merged
     *
     * @var Collection
     */
    protected $files = Collection::class;

    /**
     * Holds every tmp file so they can be removed during the deconstruction
     *
     * @var Collection
     */
    protected $temporaryFiles = Collection::class;

    /**
     * The actual PDF Service
     *
     * @var FPDI
     */
    protected $fpdi = Fpdi::class;

    /**
     * The final file name
     *
     * @var string
     */
    protected $fileName = 'merged.pdf';

    /**
     * Construct and initialize a new instance
     */
    public function __construct()
    {
        $this->fpdi = new Fpdi();
        $this->fileSystem = Storage::disk('local');
        $this->temporaryFiles = collect([]);
        $this->files = collect([]);
    }

    /**
     * The class deconstruct method
     */
    public function __destruct()
    {
        $this->fileSystem->delete($this->temporaryFiles);
    }

    /**
     * Initialize a new internal instance of FPDI in order to prevent any problems with shared resources
     * Please visit https://www.setasign.com/products/fpdi/manual/#p-159 for more information on this issue
     *
     * @return self
     */
    public function init()
    {
        return $this;
    }

    /**
     * Stream the merged PDF content
     *
     * @return string
     */
    public function inline()
    {
        return $this->fpdi->Output($this->fileName, 'I');
    }

    /**
     * Download the merged PDF content
     *
     * @return string
     */
    public function download($fileName = null)
    {
        return $this->fpdi->Output($fileName ?: $this->fileName, 'D');
    }

    /**
     * Save the merged PDF content to the filesystem
     *
     * @return string
     */
    public function save($filePath = null)
    {
        return $this->fileSystem->put($filePath ?: $this->fileName, $this->string());
    }

    /**
     * Get the merged PDF content as binary string
     *
     * @return string
     */
    public function string()
    {
        return $this->fpdi->Output($this->fileName, 'S');
    }

    /**
     * Get the merged PDF content as binary string
     * Alias of string() method
     *
     * @return string
     */
    public function output()
    {
        return $this->string();
    }

    /**
     * Set the generated PDF fileName
     * @param string $fileName
     *
     * @return string
     */
    public function setFileName($fileName)
    {
        $this->fileName = $fileName;

        return $this;
    }

    /**
     * Add a PDF for inclusion in the merge with a binary string. Pages should be formatted: 1,3,6, 12-16.
     * @param string $string
     * @param mixed $pages
     * @param mixed $orientation
     *
     * @return void
     */
    public function addPDFString($string, $pages = 'all', $orientation = null)
    {
        $filePath = $this->temporaryFolder . Str::random(16) . '.pdf';
        $this->fileSystem->put($filePath, $string);
        $this->temporaryFiles->push($filePath);

        return $this->addPathToPDF($this->fileSystem->getAdapter()->getPathPrefix() . $filePath, $pages, $orientation);
    }

    /**
     * Add a PDF for inclusion in the merge with a valid file path. Pages should be formatted: 1,3,6, 12-16.
     * @param string $filePath
     * @param string $pages
     * @param string $orientation
     *
     * @return self
     *
     * @throws \Exception if the given pages aren't correct
     */
    public function addPathToPDF($filePath, $pages = 'all', $orientation = null)
    {
        if (file_exists($filePath)) {
            $filePath = $this->convertPDFVersion($filePath);
            if (!is_array($pages) && strtolower($pages) != 'all') {
                throw new \Exception($filePath . "'s pages could not be validated");
            }
            $this->files->push([
                'name' => $filePath,
                'pages' => $pages,
                'orientation' => $orientation
            ]);
        } else {
            throw new \Exception("Could not locate PDF on '$filePath'");
        }

        return $this;
    }

    /**
     * Merges your provided PDFs and outputs to specified location.
     * @param string $orientation
     *
     * @return void
     *
     * @throws \Exception if there are now PDFs to merge
     */
    public function duplexMerge($orientation = 'P')
    {
        $this->merge($orientation, true);

        return $this;
    }

    public function merge($orientation = null, $duplex = false)
    {
        if ($this->files->count() == 0) {
            throw new \Exception("No PDFs to merge.");
        }

        $fpdi = $this->fpdi;
        $files = $this->files;

        foreach ($files as $index => $file) {
            $file['orientation'] = $file['orientation'] ?? $orientation;
            $count = $fpdi->setSourceFile($file['name']);
            if ($file['pages'] == 'all') {
                $pages = $count;
                for ($i = 1; $i <= $count; $i++) {
                    $template = $fpdi->importPage($i);
                    $size = $fpdi->getTemplateSize($template);
                    $fpdi->AddPage($file['orientation'] ?? ($size['width'] > $size['height'] ? 'L' : 'P'), [$size['width'], $size['height']]);
                    $fpdi->useTemplate($template);
                }
            } else {
                $pages = count($file['pages']);
                foreach ($file['pages'] as $page) {
                    if (!$template = $fpdi->importPage($page)) {
                        throw new \Exception("Could not load page '$page' in PDF '" . $file['name'] . "'. Check that the page exists.");
                    }
                    $size = $fpdi->getTemplateSize($template);
                    $fpdi->AddPage($file['orientation'] ?? ($size['width'] > $size['height'] ? 'L' : 'P'), [$size['width'], $size['height']]);
                    $fpdi->useTemplate($template);
                }
            }

            if ($duplex && $pages % 2 && $index < (count($files) - 1)) {
                $fpdi->AddPage($file['orientation'] ?? ($size['width'] > $size['height'] ? 'L' : 'P'), [$size['width'], $size['height']]);
            }
        }

        return $this;
    }

    /**
     * Converts PDF if version is above 1.4
     * @param string $filePath
     *
     * @return string
     */
    protected function convertPDFVersion($filePath)
    {
        $pdf = fopen($filePath, "r");
        $firstLine = fgets($pdf);
        fclose($pdf);
        //extract version number
        preg_match_all('!\d+!', $firstLine, $matches);
        $pdfversion = implode('.', $matches[0]);
        if ($pdfversion > "1.4") {
            $newFilePath = $this->temporaryFolder . Str::random(16) . '.pdf';
            //execute shell script that converts PDF to correct version and saves it to tmp folder
            shell_exec('gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile="' . $newFilePath . '" "' . $filePath . '"');
            $this->temporaryFiles->push($newFilePath);
            $filePath = $newFilePath;
        }

        //return correct file path
        return $filePath;
    }
}
