<?php

namespace FluentCart\Framework\Database\Events;

abstract class ConnectionEvent
{
    /**
     * The name of the connection.
     *
     * @var string
     */
    public $connectionName;

    /**
     * The database connection instance.
     *
     * @var \FluentCart\Framework\Database\Query\WPDBConnection  $connection
     */
    public $connection;

    /**
     * Create a new event instance.
     *
     * @var \FluentCart\Framework\Database\Query\WPDBConnection  $connection
     * @return void
     */
    public function __construct($connection)
    {
        $this->connection = $connection;
        $this->connectionName = $connection->getName();
    }
}
