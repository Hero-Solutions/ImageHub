<?php

namespace App\Utils;

use FastImageSize\FastImageSize;

class FastImageSizeImpl extends FastImageSize
{
    protected function getImageSizeByExtension($file, $extension)
    {
        echo 'Okay' . PHP_EOL;
        $extension = strtolower($extension);
        $this->loadExtension($extension);
        if (isset($this->classMap[$extension])) {
            echo 'OKAY ' . PHP_EOL;
            $impl = new TypeTifImpl($this);
            $impl->getSize($file);
        } else {
            echo 'Oopsy daisy.' . PHP_EOL;
        }
    }
}
