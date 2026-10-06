<?php

namespace App\Services\Clickers;

use App\Models\Clicker;

interface ClickerBuilder
{
    /**
     * Build the print files for a design and store them on the private disk.
     *
     * @return array{threemf: string, stl_zip: string} storage paths of the 3MF and of the STL zip
     *
     * @throws ClickerBuildException
     */
    public function build(Clicker $clicker): array;
}
