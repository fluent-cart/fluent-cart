<?php

namespace Dev\Test\Inc;

trait RefreshDatabase
{
	protected static $tables = [
		'users', 'usermeta',
		'posts', 'postmeta',
		'terms', 'termmeta',
		'comments', 'commentmeta',
		'links', 'tags', 'taggables',
		'term_taxonomy', 'term_relationships',
	];

	private function getNamespace()
	{
		static $ns;

		if (!$ns) {
			$ns = json_decode(
				file_get_contents(
					realpath(__DIR__.'/../../../composer.json')
				), true
			)['extra']['wpfluent']['namespace']['current'];
		}

		return $ns;
	}

	private function migrator()
	{
		return ($this->getNamespace().'\Database\DBMigrator');
	}

	private function schema()
	{	
		return ($this->getNamespace().'\Framework\Database\Schema');
	}

    public function setUp() : void
    {
        parent::setUp();

        [$migrator, $schema] = [$this->migrator(), $this->schema()];

        $migrator::migrateUp();

        foreach (static::$tables as $table) {
            $schema::truncateTableIfExists($table);
        }
    }

    public function tearDown() : void
    {
        parent::tearDown();

        $this->migrator()::migrateDown();
    }
}
