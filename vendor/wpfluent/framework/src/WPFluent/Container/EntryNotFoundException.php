<?php

namespace FluentCart\Framework\Container;

use Exception;
use FluentCart\Framework\Container\Contracts\Psr\NotFoundExceptionInterface;

class EntryNotFoundException extends Exception implements NotFoundExceptionInterface
{
    //
}
