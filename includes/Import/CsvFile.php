<?php
namespace SGW_Import\Import;

use SGW_Import\Model\ImportFileModel;
use SGW_Import\Upload\Assets;

class CsvFile
{
    /** @var int */
    public $id;
    
    /** @var string[] */
    public $columns = [];

    /** @var ImportFileModel */
    public $importFileModel;

    /** @var Assets */
    private $assets;

    /** @var resource|null */
    private $fh = null;

    /** @var Row */
    private $row;

    public function __construct(int $id)
    {
        $this->id = $id;
        $this->importFileModel = new ImportFileModel();
        $this->importFileModel->readOrThrow($id);
        $this->assets = new Assets();
        $this->row = new Row();
        $this->open();
        $this->reset();
    }

    public function open()
    {
        $filePath = $this->assets->filePath($this->importFileModel->fileName);
        $this->fh = fopen($filePath, 'r');
        if ($this->fh === false) {
            throw new \Exception(sprintf("Could not open '%s'", $this->importFileModel->fileName));
        }
    }

    public function close()
    {
        fclose($this->fh);
    }

    public function reset()
    {
        fseek($this->fh, 0);
        $this->row->rowIndex = 0;
        $this->readHeaderInfo();
    }

    private function readHeaderInfo()
    {
        $this->columns = [];
        // the $escape argument has to be given explicitly, its default value
        // changes in PHP 9 (deprecation since PHP 8.4)
        $fields = fgetcsv($this->fh, 0, ',', '"', '\\');
        foreach ($fields as $field) {
            $this->columns[] = $field;
        }
    }

    public function read()
    {
        $row = fgetcsv($this->fh, 0, ',', '"', '\\');
        if ($row == false) {
            return null;
        }
        $this->row->data = $row;
        $this->row->rowIndex++;
        return $this->row;
    }

    public function readRow($rowIndex)
    {
        $c = $rowIndex;
        while (--$c > 0) {
            fgets($this->fh);
        }
        $row = $this->read();
        $row->rowIndex = $rowIndex;
        return $row;
    }
}
