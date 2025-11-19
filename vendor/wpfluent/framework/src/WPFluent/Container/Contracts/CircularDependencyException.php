<?php

namespace FluentCart\Framework\Container\Contracts;

use Exception;
use FluentCart\Framework\Container\Contracts\Psr\ContainerExceptionInterface;

class CircularDependencyException extends Exception implements ContainerExceptionInterface
{
    //
}
