<?php

namespace App\Utils;

use FastImageSize\FastImageSize;
use FastImageSize\Type\TypeTif;

class FastImageSizeImpl extends FastImageSize
{
    protected function getImageSizeByExtension($file, $extension)
    {
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
