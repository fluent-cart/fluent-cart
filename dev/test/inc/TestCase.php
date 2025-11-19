<?php

namespace Dev\Test\Inc;

use WP_REST_Server;
use InvalidArgumentException;
use PHPUnit_Framework_TestCase;

class TestCase extends PHPUnit_Framework_TestCase
{
	use Concerns;
	use RefreshDatabase {
	    RefreshDatabase::setUp as refreshDatabaseSetup;
	    RefreshDatabase::tearDown as refreshDatabaseTearDown;
	}

	protected $plugin = null;
	
	protected $router = null;
	
	protected $server = null;
	
	protected $factory = null;

	public function setUp() : void
	{
		global $wp_rest_server;

        $this->server = $wp_rest_server = new WP_REST_Server;

        $this->plugin = include realpath(__DIR__ . '/../../../plugin.php');

        $this->router = $this->plugin->router;

        $this->factory = new Factory;

        App::make('config')->set('app.env', 'testing');

        $this->refreshDatabaseSetup();

        do_action('rest_api_init');
	}

	public function tearDown() : void
	{
		global $wp_rest_server;
        
        $wp_rest_server = null;

		$this->refreshDatabaseTearDown();
	}
}
