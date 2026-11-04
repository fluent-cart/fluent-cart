<?php

namespace Tests\Unit\Services\FileSystem;

use Codeception\Test\Unit;
use FluentCart\App\Services\FileSystem\StoragePath;

/**
 * The single place a user supplied storage path is reduced to segments that
 * stay inside a storage directory.
 *
 * Store path and consume path must normalize identically, so both the write
 * boundary (a product download row) and the read/delete boundary (the local
 * driver) resolve the same string the same way.
 */
class StoragePathTest extends Unit
{
    /**
     * Spec: Cases/storage-path-traversal.md [1]
     *   "A plain file name is already a contained path."
     */
    public function test_a_plain_file_name_is_kept_as_is(): void
    {
        $this->assertSame('report.pdf', StoragePath::relative('report.pdf'), 'a plain file name is already relative');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [2]
     *   "Nested segments survive, because object-store keys contain slashes."
     */
    public function test_nested_segments_are_kept(): void
    {
        $this->assertSame(
            '2026/08/report.pdf',
            StoragePath::relative('2026/08/report.pdf'),
            'nested object keys must survive — S3 and R2 store paths with slashes'
        );
    }

    /**
     * Spec: Cases/storage-path-traversal.md [3]
     *   "A leading slash is dropped rather than refused."
     */
    public function test_a_leading_slash_is_dropped_rather_than_rejected(): void
    {
        // `$dirPath . '/' . '/etc/passwd'` always resolved inside the storage
        // directory, so a leading slash is not an escape and must not start
        // rejecting paths that already worked.
        $this->assertSame('etc/passwd', StoragePath::relative('/etc/passwd'), 'a leading slash is relative, not absolute');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [4]
     *   "Redundant separators and `.` segments carry no meaning and collapse."
     */
    public function test_redundant_separators_and_dot_segments_collapse(): void
    {
        $this->assertSame('a/b.pdf', StoragePath::relative('./a//./b.pdf'), 'empty and dot segments carry no meaning');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [5]
     *   "Backslashes normalize to forward slashes before the segments are read."
     */
    public function test_backslashes_are_normalized_to_forward_slashes(): void
    {
        $this->assertSame('a/b.pdf', StoragePath::relative('a\\b.pdf'), 'Windows separators must normalize before the segments are read');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [6]
     *   "A `..` that cancels an earlier segment never leaves the directory."
     */
    public function test_an_interior_parent_segment_is_resolved_when_it_stays_inside(): void
    {
        $this->assertSame('b.pdf', StoragePath::relative('a/../b.pdf'), 'a parent segment that cancels out never leaves the directory');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [7]
     *   "A path that walks above the directory is refused."
     */
    public function test_a_path_escaping_the_directory_is_refused(): void
    {
        $this->assertSame('', StoragePath::relative('../wp-config.php'), 'one level up leaves the storage directory');
        $this->assertSame('', StoragePath::relative('../../../wp-config.php'), 'three levels up reaches ABSPATH');
        $this->assertSame('', StoragePath::relative('a/../../wp-config.php'), 'a parent segment that outruns its prefix still escapes');
        $this->assertSame('', StoragePath::relative('..\\..\\wp-config.php'), 'the backslash form escapes exactly like the forward-slash form');
        $this->assertSame('', StoragePath::relative('/../wp-config.php'), 'a leading slash does not neutralise a parent segment');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [8]
     *   "A path addressing the directory itself rather than a file is refused."
     */
    public function test_an_empty_path_is_refused(): void
    {
        $this->assertSame('', StoragePath::relative(''), 'an empty path addresses the directory itself, not a file');
        $this->assertSame('', StoragePath::relative('/'), 'a bare separator addresses the directory itself, not a file');
        $this->assertSame('', StoragePath::relative('.'), 'a bare dot addresses the directory itself, not a file');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [9]
     *   "A non-string path is refused rather than coerced."
     */
    public function test_a_non_string_path_is_refused(): void
    {
        $this->assertSame('', StoragePath::relative(null), 'a missing path must not resolve to the directory itself');
        $this->assertSame('', StoragePath::relative([]), 'an array must not be coerced into a path');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [7]
     *   "A path that walks above the directory is refused."
     */
    public function test_is_safe_mirrors_relative(): void
    {
        $this->assertTrue(StoragePath::isSafe('report.pdf'), 'a contained path is safe to store');
        $this->assertTrue(StoragePath::isSafe('/2026/08/report.pdf'), 'a leading slash is still contained');
        $this->assertFalse(StoragePath::isSafe('../wp-config.php'), 'an escaping path is not safe to store');
        $this->assertFalse(StoragePath::isSafe(''), 'an empty path is not a file reference');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [10]
     *   "An absolute path is resolved against the real base directory."
     */
    public function test_contain_joins_a_relative_path_onto_the_base(): void
    {
        $base = sys_get_temp_dir();

        // The base is resolved through realpath(), so the result is anchored to
        // the real directory (/var is a symlink to /private/var on macOS).
        $this->assertSame(
            rtrim(realpath($base), '/') . '/report.pdf',
            StoragePath::contain($base, 'report.pdf'),
            'a contained path resolves under the real base directory'
        );
    }

    /**
     * Spec: Cases/storage-path-traversal.md [7]
     *   "A path that walks above the directory is refused."
     */
    public function test_contain_refuses_a_path_that_escapes_the_base(): void
    {
        $base = sys_get_temp_dir();

        $this->assertSame('', StoragePath::contain($base, '../report.pdf'), 'an escaping path has no absolute form inside the base');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [10]
     *   "An absolute path is resolved against the real base directory."
     */
    public function test_contain_refuses_an_empty_base(): void
    {
        $this->assertSame('', StoragePath::contain('', 'report.pdf'), 'without a base directory nothing can be contained');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [11]
     *   "A symlink inside the directory that resolves outside it is refused."
     */
    public function test_contain_refuses_a_symlink_pointing_outside_the_base(): void
    {
        $base = sys_get_temp_dir() . '/fct-storage-path-' . getmypid();
        $outsideFile = sys_get_temp_dir() . '/fct-storage-path-outside-' . getmypid() . '.txt';
        $link = $base . '/escape.txt';

        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        file_put_contents($outsideFile, 'outside');

        if (file_exists($link) || is_link($link)) {
            unlink($link);
        }

        $this->assertTrue(
            symlink($outsideFile, $link),
            'the test needs a symlink under ' . sys_get_temp_dir() . ' to exercise the symlink escape'
        );

        try {
            $this->assertSame(
                '',
                StoragePath::contain($base, 'escape.txt'),
                'a symlink inside the base that resolves outside it must be refused'
            );
        } finally {
            if (is_link($link) || file_exists($link)) {
                unlink($link);
            }
            if (file_exists($outsideFile)) {
                unlink($outsideFile);
            }
            if (is_dir($base)) {
                rmdir($base);
            }
        }
    }
}
