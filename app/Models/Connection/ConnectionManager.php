<?php

namespace FluentCart\App\Models\Connection;

use FluentCart\App\Models\Model;
use FluentCart\Framework\Database\DBManager;
use FluentCart\Database\Overrides\DbConnection;
use FluentCart\Framework\Database\ConnectionResolver;

class ConnectionManager
{
    public static function connect(&$app)
    {

        $resolver = new ConnectionResolver([
            'mysql' => new DbConnection(
                $GLOBALS['wpdb'],
                $app->config->get('database')
            ),
            'sqlite' => new DbConnection(
                $GLOBALS['wpdb']
            ),
        ]);

        $resolver->setDefaultConnection('mysql');

        Model::setConnectionResolver($resolver);

        Model::setEventDispatcher($app['events']);

        $app->singleton('db', function ($app) use ($resolver) {
            return new DBManager($resolver);
        });
    }
}