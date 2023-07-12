<?php

namespace App\Utils;

use FastImageSize\FastImageSize;

class FastImageSizeImpl extends FastImageSize
{
    /**
     * Get dimensions of image if type is unknown
     *
     * @param string $filename Path to file
     */
    protected function getImagesizeUnknownType($filename)
    {
        echo 'Unknown type.' . PHP_EOL;
        // Grab the maximum amount of bytes we might need
        $data = $this->getImage($filename, 0, Type\TypeJpeg::JPEG_MAX_HEADER_SIZE, false);

        if ($data !== false)
        {
            $this->loadAllTypes();
            foreach ($this->type as $imageType)
            {
                $imageType->getSize($filename);

                if (sizeof($this->size) > 1)
                {
                    break;
                }
            }
        }
    }

    protected function getImageSizeByExtension($file, $extension)
    {
        echo 'Okay' . PHP_EOL;
        $extension = strtolower($extension);
        $this->loadExtension($extension);
        if (isset($this->classMap[$extension])) {
            $impl = new TypeTifImpl($this);
            $impl->getSize($file);
        } else {
            echo 'Oopsy daisy.' . PHP_EOL;
        }
    }
}
