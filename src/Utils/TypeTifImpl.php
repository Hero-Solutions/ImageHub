<?php

namespace App\Utils;

use FastImageSize\Type\TypeTif;

class TypeTifImpl extends TypeTif
{
    public function getSize($filename)
    {
        // Do not force length of header
        $data = $this->fastImageSize->getImage($filename, 0, self::TIF_HEADER_SIZE, false);

        $this->size = array();

        $signature = substr($data, 0, self::SHORT_SIZE);

        if (!in_array($signature, array(self::TIF_SIGNATURE_INTEL, self::TIF_SIGNATURE_MOTOROLA)))
        {
            return;
        }

        // Set byte type
        $this->setByteType($signature);

        // Get offset of IFD
        list(, $offset) = unpack($this->typeLong, substr($data, self::LONG_SIZE, self::LONG_SIZE));

        // Get size of IFD
        $ifdSizeInfo = substr($data, $offset, self::SHORT_SIZE);
        if (empty($ifdSizeInfo))
        {
            echo 'IFD size info is empty!' . PHP_EOL;
            return;
        }
        list(, $sizeIfd) = unpack($this->typeShort, $ifdSizeInfo);

        // Skip 2 bytes that define the IFD size
        $offset += self::SHORT_SIZE;

        // Ensure size can't exceed data length
        $sizeIfd = min($sizeIfd, floor((strlen($data) - $offset) / self::TIF_IFD_ENTRY_SIZE));

        // Filter through IFD
        for ($i = 0; $i < $sizeIfd; $i++)
        {
            // Get IFD tag
            $type = unpack($this->typeShort, substr($data, $offset, self::SHORT_SIZE));

            // Get field type of tag
            $fieldType = unpack($this->typeShort . 'type', substr($data, $offset + self::SHORT_SIZE, self::SHORT_SIZE));

            // Get IFD entry
            $ifdValue = substr($data, $offset + 2 * self::LONG_SIZE, self::LONG_SIZE);

            // Set size of field
            $this->setSizeInfo($type[1], $fieldType['type'], $ifdValue);

            $offset += self::TIF_IFD_ENTRY_SIZE;
        }

        $this->fastImageSize->setSize($this->size);
    }
}