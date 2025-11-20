<?php

declare (strict_types=1);
namespace FluentCart\OpenSpout\Reader;

/**
 * Interface IteratorInterface.
 */
interface SheetIteratorInterface extends IteratorInterface
{
    /**
     * Cleans up what was created to iterate over the object.
     */
    #[\ReturnTypeWillChange]
    public function end();
    /**
     * @return null|SheetInterface
     */
    #[\ReturnTypeWillChange]
    public function current();
}
