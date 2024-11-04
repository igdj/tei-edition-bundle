<?php

namespace TeiEditionBundle\Utils;

class IViewTiler
{
    var $tile_size = 256;

    public function determineMaxZoom($width, $height)
    {
        $factor = 2;
        $tileScaled = $this->tile_size;

        $maxLevel = 0;
        while ($tileScaled < $width || $tileScaled < $height) {
            $tileScaled *= $factor;
            ++$maxLevel;
        }

        return $maxLevel;
    }
}
