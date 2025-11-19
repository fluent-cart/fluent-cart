<?php

namespace Dev\Test\Tests\Rest;

use Dev\Test\Inc\TestCase;

class TestRestWorks extends TestCase
{
	public function test_rest_works()
	{
		$this->router->get('test', function() {});
		
		$response = $this->get('test');

		$this->assertTrue($response->isOkay());
	}
}
