<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MakeMigrationCommand extends BaseCommand
{
    protected static $defaultName = 'make:migration';

    protected static $defaultDescription = 'Generates a MySQL column definitions SQL file for a given table';

    protected function configure()
    {
        $this
            ->addArgument('table', InputArgument::REQUIRED, 'The name of the table for the migration file');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $tableName = $input->getArgument('table');

        $fields = $this->getFieldDefinitions();

        // Migrations folder inside the plugin
        $migrationsDir = $this->root . '/database/Migrations';
        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0777, true);
        }

        // File path with table name
        $fileName = $migrationsDir . "/{$tableName}.sql";
        file_put_contents($fileName, $fields);

        // Convert to path relative to wp-content/plugins
        $fullPath = realpath($fileName);

        $relativePath = basename($this->root) . DIRECTORY_SEPARATOR . str_replace(
            $this->root . '/', '', $fullPath
        );

        $io->writeln(
            "<info>Migration fields file created: {$relativePath}</info>"
        );

        return static::SUCCESS;
    }

    /**
     * Returns the SQL field definitions with comments.
     *
     * @return string
     */
    protected function getFieldDefinitions()
    {
        return <<<SQL
-- ID / Auto-increment
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, -- Primary key, unique identifier for each row (0 to 18,446,744,073,709,551,615)

-- String types
title VARCHAR(100) NOT NULL,           -- Short title, up to 100 characters
slug CHAR(50) NOT NULL,                -- URL-friendly unique identifier, fixed 50 characters
binary_data VARBINARY(255) NULL,       -- Optional binary data, up to 255 bytes

-- Text types
description TEXT NULL,                 -- Longer description, up to 65,535 characters
notes TINYTEXT NULL,                   -- Short notes, up to 255 characters
content MEDIUMTEXT NULL,               -- Medium content, up to 16,777,215 characters
long_content LONGTEXT NULL,            -- Very long content, up to 4,294,967,295 characters
status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft', -- Status of the record, limited to these 3 values
tags SET('php','mysql','html','css') NULL, -- Optional tags, multiple selections allowed

-- Numeric types
views INT UNSIGNED NOT NULL DEFAULT 0,        -- Number of views, 0 to 4,294,967,295
likes SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- Number of likes, 0 to 65,535
rating TINYINT UNSIGNED NULL,                 -- Optional rating, 0 to 255
big_counter BIGINT UNSIGNED NOT NULL DEFAULT 0, -- Large counter, 0 to 18,446,744,073,709,551,615
price DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- Monetary value, up to 99999999.99
tax NUMERIC(5,2) NULL,                        -- Optional tax, up to 999.99
score FLOAT NULL,                             -- Optional floating-point score
percentage DOUBLE NULL,                       -- Optional double-precision percentage

-- Date / Time types
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Record creation timestamp
updated_at TIMESTAMP NULL,                       -- Last update timestamp, optional
deleted_at TIMESTAMP NULL,                       -- Soft delete timestamp, optional
event_date DATE NULL,                            -- Date of event (YYYY-MM-DD)
event_time TIME NULL,                            -- Time of event (HH:MM:SS)
event_year YEAR NULL,                            -- Year of event (YYYY)
appointment DATETIME NULL,                       -- Full appointment datetime (YYYY-MM-DD HH:MM:SS)

-- JSON type
metadata JSON NULL,                              -- Optional JSON data, requires MySQL 5.7+

-- Spatial types
location POINT NULL,                             -- Optional geographic point (latitude/longitude)
area POLYGON NULL                                -- Optional geographic polygon

-- Indexing examples

-- UNIQUE KEY: ensures no duplicate values in the column
-- Useful for fields that must remain unique, like slugs, usernames, or emails.
-- Attempts to insert a duplicate will fail.
UNIQUE KEY unique_slug (slug),                  
-- Example usage: SELECT * FROM table WHERE slug = 'my-post';

-- STANDARD / REGULAR KEY (INDEX): speeds up search queries for large tables
-- Creates an index for faster lookups on frequently searched columns.
-- Does not enforce uniqueness.
KEY idx_title (title),                           
-- Example usage: SELECT * FROM table WHERE title LIKE 'Hello%';

-- FULLTEXT INDEX: optimized for searching large text fields
-- Supports natural language full-text search on TEXT / LONGTEXT / VARCHAR columns.
-- Only available for MyISAM (older versions) and InnoDB (MySQL 5.6+).
-- Useful for searching blog content, descriptions, or notes.
FULLTEXT KEY ft_content (content, long_content), 
-- Example usage: SELECT * FROM table WHERE MATCH(content, long_content) AGAINST('keyword');

-- SPATIAL INDEX: optimized for geographic data types (POINT, POLYGON, LINESTRING)
-- Requires InnoDB or MyISAM storage engine. Only works on spatial columns.
-- Enables fast proximity or intersection queries for mapping / GIS features.
SPATIAL KEY sp_location (location),
-- Example usage: SELECT * FROM table WHERE ST_Distance_Sphere(location, POINT(lon, lat)) < 5000;

-- COMPOSITE INDEX (optional example): indexes multiple columns together
-- Useful when queries filter on more than one column at a time.
-- The order of columns matters for query optimization.
KEY idx_status_created_at (status, created_at),
-- Example usage: SELECT * FROM table WHERE status = 'published' ORDER BY created_at DESC;

-- Reference: https://dev.mysql.com/doc/refman/8.0/en/data-types.html

SQL;
    }
}
