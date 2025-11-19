<?php

namespace FluentCart\App\Http\Routes;

use FluentCart\App\App;
use FluentCart\App\Hooks\CLI\Commands;

class FakerRoutes
{
    public static function handle($page): bool
    {
        switch ($page) {
            case 'migrate-fresh':
                self::handleMigrateFresh();
                return true;

            case 'migrate':
                $command = new Commands();
                $command->migrate();
                echo "Migration Complete";
                return true;

            case 'seed':
                self::handleSeed();
                return true;

            default:
                return false;
        }
    }

    private static function handleMigrateFresh()
    {
        $command = new Commands();
        $args = [];

        if (App::request()->get('seed')) {
            $args['seed'] = true;
        }

        $command->migrate_fresh([], $args, false);
    }

    private static function handleSeed()
    {
        $command = new Commands();
        $args = [];

        if ($count = App::request()->get('count')) {
            if (is_numeric($count)) {
                $args['count'] = $count;
            }
        }

        if (App::request()->get('entities', 'all') === 'all') {
            $command->seed_all([], $args, 1000, false);
        } else {
            $entities = App::request()->get('entities', '');
            $entities = explode(',', $entities);

            foreach ($entities as $entity) {
                $args[$entity] = true;
            }

            $args['product_types'] = App::request()->get('product_types');
            $command->seed([], $args, 1000, false);
        }
    }
}