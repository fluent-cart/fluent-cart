<?php

namespace FluentCart\Framework\Database;

use BadMethodCallException;
use FluentCart\Framework\Support\Arr;

class DBManager
{
    protected $resolver;

    public function __construct(ConnectionResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function connection($name = null)
    {
        return $this->resolver->connection($name);
    }

    protected function suggestMethod($connection, $method)
    {
        $methods = get_class_methods($connection);

        return Arr::findSimilar($method, $methods);
    }

    protected function throwBadMethodCallException($connection, $method)
    {
        $message = sprintf(
            "Call to undefined method %s::%s.",
            get_class($connection), $method
        );

        if ($suggested = $this->suggestMethod($connection, $method)) {
            $message .= sprintf(" Did you mean '%s'?", $suggested);
        }

        throw new BadMethodCallException($message);
    }

    public function __call($method, $args)
    {
        $connection = $this->connection();

        if (method_exists($connection, $method)) {
            return $connection->$method(...$args);
        }

        $this->throwBadMethodCallException($connection, $method);
    }
}
