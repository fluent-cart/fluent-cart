<?php

namespace Dev\Test\Tests;

use Dev\Test\Inc\App;
use Dev\Test\Inc\TestCase;
use Dev\Test\Inc\UsersAndPostsSeeder;
// use PluginNamespace\App\Models\User;

class TestSample extends TestCase
{
	use UsersAndPostsSeeder;

	public function setUp(): void
	{
		parent::setUp();
		//$this->seedUsersAndPosts();
	}

	public function test_works()
	{
		$this->assertTrue(true);
		// $this->assertCount(10, User::get());
	}
}
