<?php
    
namespace Dev\Cli\Commands;

use Symfony\Component\Console\Command\Command;

abstract class BaseCommand extends Command
{
    protected $root;
    
    protected $wpRoot;
    
    protected $config;

    public function __construct()
    {
        parent::__construct();

        $this->root = realpath(__DIR__ . '/../../..');

        $this->wpRoot = realpath(__DIR__ . '/../../../../../..');

        $this->config = require $this->root . '/config/app.php';
    }

    protected function maybeLoadWordPress()
    {
        if (!defined('ABSPATH')) {
            require getcwd() . "/../../../wp-load.php";
        }
    }
}
