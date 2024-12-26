<?php
/**
 * Simple Shapefile Reader
 * Only reads DBF files for attribute data
 */
class ShapeFile {
    private $dbf_handle;
    private $record_count;
    private $header_length;
    private $record_length;
    private $fields = array();

    public function __construct($shapefile_path) {
        // Convert .shp to .dbf
        $dbf_path = preg_replace('/\.shp$/', '.dbf', $shapefile_path);
        if (!file_exists($dbf_path)) {
            throw new Exception('DBF file not found: ' . $dbf_path);
        }

        $this->dbf_handle = fopen($dbf_path, 'rb');
        if (!$this->dbf_handle) {
            throw new Exception('Could not open DBF file: ' . $dbf_path);
        }

        // Read DBF header
        $header = fread($this->dbf_handle, 32);
        if (strlen($header) != 32) {
            throw new Exception('Invalid DBF header');
        }

        // Get record count (bytes 4-7)
        $this->record_count = unpack('V', substr($header, 4, 4))[1];
        
        // Get header length (bytes 8-9)
        $this->header_length = unpack('v', substr($header, 8, 2))[1];
        
        // Get record length (bytes 10-11)
        $this->record_length = unpack('v', substr($header, 10, 2))[1];

        // Read field descriptors
        $pos = 32;
        while ($pos < $this->header_length - 1) {
            $field = fread($this->dbf_handle, 32);
            if (strlen($field) != 32 || ord($field[0]) == 0x0d) break;

            $name = trim(substr($field, 0, 11));
            $type = substr($field, 11, 1);
            $length = ord(substr($field, 16, 1));

            $this->fields[] = array(
                'name' => $name,
                'type' => $type,
                'length' => $length
            );

            $pos += 32;
        }

        // Skip to first record
        fseek($this->dbf_handle, $this->header_length);
    }

    public function getRecord($type = SHAPEFILE_RECORD_DBF) {
        if (feof($this->dbf_handle)) {
            return false;
        }

        $record = array('dbf' => array());
        
        // Read DBF record
        $dbf_data = fread($this->dbf_handle, $this->record_length);
        if (strlen($dbf_data) != $this->record_length) {
            return false;
        }

        // Skip deleted records
        if (ord($dbf_data[0]) == 0x2a) {
            return $this->getRecord($type);
        }

        $pos = 1;
        foreach ($this->fields as $field) {
            $value = trim(substr($dbf_data, $pos, $field['length']));
            
            // Convert numeric values
            if ($field['type'] == 'N') {
                $value = $value === '' ? null : floatval($value);
            }
            
            $record['dbf'][$field['name']] = $value;
            $pos += $field['length'];
        }

        return $record;
    }

    public function __destruct() {
        if ($this->dbf_handle) {
            fclose($this->dbf_handle);
        }
    }
}

// Constants for record types
define('SHAPEFILE_RECORD_SHAPE', 1);
define('SHAPEFILE_RECORD_DBF', 2);
define('SHAPEFILE_RECORD_BOTH', 3); 