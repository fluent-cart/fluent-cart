<?php

namespace FluentCart\OpenSpout\Reader\Common\Creator;

use FluentCart\OpenSpout\Common\Entity\Cell;
use FluentCart\OpenSpout\Common\Entity\Row;
/**
 * Interface EntityFactoryInterface.
 */
interface InternalEntityFactoryInterface
{
    /**
     * @param Cell[] $cells
     *
     * @return Row
     */
    public function createRow(array $cells = []);
    /**
     * @param mixed $cellValue
     *
     * @return Cell
     */
    public function createCell($cellValue);
}
