<?php

namespace Tests\Integration\Services\FileSystem;

use FluentCart\App\Services\FileSystem\Drivers\Local\LocalDriver;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Path containment for the local storage driver.
 *
 * `file_path` is attacker-controlled on both the delete surface
 * (files/delete) and the download surface (a product download row written
 * through products/{id}/sync-downloadable-files). Neither sanitize_text_field()
 * nor wp_normalize_path() resolves `..`, so before containment existed a
 * relative path walked straight out of the upload directory: delete removed any
 * file the web user could unlink, download streamed any file it could read —
 * wp-config.php included.
 *
 * Every path here must stay inside the driver directory or be refused.
 */
class LocalDriverPathContainmentTest extends WPTestCase
{
    private string $storageDir;

    private string $outsideFile;

    private string $insideFile;

    public function setUp(): void
    {
        parent::setUp();

        $this->storageDir = wp_get_upload_dir()['basedir'] . '/fluent-cart';

        if (!is_dir($this->storageDir)) {
            wp_mkdir_p($this->storageDir);
        }

        // One level above the storage directory — the Phase 1 geometry from the
        // report; `../../../` reaches ABSPATH the same way.
        $this->outsideFile = wp_get_upload_dir()['basedir'] . '/fct-containment-outside.txt';
        file_put_contents($this->outsideFile, 'must survive');

        $this->insideFile = $this->storageDir . '/fct-containment-inside.txt';
        file_put_contents($this->insideFile, 'legitimate download');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tearDown(): void
    {
        foreach ([$this->outsideFile, $this->insideFile] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * Spec: Cases/storage-path-traversal.md [12]
     *   "Deleting a file one level above the storage directory is refused."
     */
    public function test_delete_refuses_a_relative_path_that_escapes_the_storage_directory(): void
    {
        $result = (new LocalDriver())->deleteFile('../fct-containment-outside.txt');

        $this->assertWPError($result, 'a path escaping the storage directory must not be accepted for deletion');
        $this->assertFileExists($this->outsideFile, 'the file outside the storage directory must still be on disk');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [13]
     *   "Deleting a file at ABSPATH — the wp-config.php geometry — is refused."
     */
    public function test_delete_refuses_a_path_that_walks_up_to_abspath(): void
    {
        $abspathFile = ABSPATH . 'fct-containment-abspath.txt';
        file_put_contents($abspathFile, 'wp-config geometry');

        try {
            $result = (new LocalDriver())->deleteFile('../../../fct-containment-abspath.txt');

            $this->assertWPError($result, 'a path reaching ABSPATH must not be accepted for deletion');
            $this->assertFileExists($abspathFile, 'the ABSPATH file must still be on disk');
        } finally {
            if (file_exists($abspathFile)) {
                unlink($abspathFile);
            }
        }
    }

    /**
     * Spec: Cases/storage-path-traversal.md [14]
     *   "A backslash-encoded escape is refused like the forward-slash form."
     */
    public function test_delete_refuses_a_backslash_encoded_escape(): void
    {
        // wp_normalize_path() turns `..\` into `../`, so the backslash form has
        // to be refused for the same reason the forward-slash form is.
        $result = (new LocalDriver())->deleteFile('..\\fct-containment-outside.txt');

        $this->assertWPError($result, 'a backslash-encoded escape must not be accepted for deletion');
        $this->assertFileExists($this->outsideFile, 'the file outside the storage directory must still be on disk');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [15]
     *   "Deleting a file inside the storage directory still works."
     */
    public function test_delete_still_removes_a_file_inside_the_storage_directory(): void
    {
        $result = (new LocalDriver())->deleteFile('fct-containment-inside.txt');

        $this->assertFalse(is_wp_error($result), 'deleting a file inside the storage directory must keep working');
        $this->assertFileDoesNotExist($this->insideFile, 'the file inside the storage directory must be gone');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [16]
     *   "Composing a path that escapes the storage directory yields nothing."
     */
    public function test_get_file_path_refuses_an_escaping_path(): void
    {
        $path = (new LocalDriver())->getFilePath('../../../wp-config.php');

        $this->assertSame('', $path, 'getFilePath() must not compose a path outside the storage directory');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [17]
     *   "Composing a path inside the storage directory still resolves it."
     */
    public function test_get_file_path_resolves_a_contained_path(): void
    {
        $path = (new LocalDriver())->getFilePath('fct-containment-inside.txt');

        $this->assertSame(
            wp_normalize_path($this->insideFile),
            wp_normalize_path($path),
            'getFilePath() must still resolve a file inside the storage directory'
        );
    }

    /**
     * Spec: Cases/storage-path-traversal.md [18]
     *   "A leading slash still resolves relative to the storage directory."
     */
    public function test_get_file_path_treats_a_leading_slash_as_relative_to_the_storage_directory(): void
    {
        // `dirPath . '/' . '/uploads/x'` already resolved inside the directory,
        // so a leading slash must keep resolving there rather than start over
        // at the filesystem root.
        $path = (new LocalDriver())->getFilePath('/fct-containment-inside.txt');

        $this->assertSame(
            wp_normalize_path($this->insideFile),
            wp_normalize_path($path),
            'a leading slash must stay relative to the storage directory'
        );
    }

    /**
     * Spec: Cases/storage-path-traversal.md [19]
     *   "Downloading a file outside the storage directory is refused."
     */
    public function test_download_refuses_a_path_that_escapes_the_storage_directory(): void
    {
        $result = (new LocalDriver())->downloadFile('../../../wp-config.php', 'wp-config.php');

        $this->assertWPError($result, 'downloadFile() must refuse to stream a file outside the storage directory');
    }
}
