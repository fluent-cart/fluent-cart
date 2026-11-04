<?php

namespace Tests\Functional\Rest\Files;

use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;

/**
 * files/delete must not accept a path that leaves the storage directory.
 *
 * The route reads `file_path` straight from the request body. Before
 * containment existed, `../` walked out of wp-content/uploads/fluent-cart and
 * wp_delete_file() unlinked whatever it landed on — an arbitrary file deletion
 * reachable by anyone who can call the endpoint.
 */
class DeleteFilePathTraversalCest
{
    private string $outsideFile = '';

    private string $insideFile = '';

    public function _before(FunctionalTester $I): void
    {
        $storageDir = wp_get_upload_dir()['basedir'] . '/fluent-cart';

        if (!is_dir($storageDir)) {
            wp_mkdir_p($storageDir);
        }

        $this->outsideFile = wp_get_upload_dir()['basedir'] . '/fct-delete-traversal-outside.txt';
        file_put_contents($this->outsideFile, 'must survive');

        $this->insideFile = $storageDir . '/fct-delete-traversal-inside.txt';
        file_put_contents($this->insideFile, 'safe to delete');
    }

    public function _after(FunctionalTester $I): void
    {
        foreach ([$this->outsideFile, $this->insideFile] as $path) {
            if ($path && file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Spec: Cases/storage-path-traversal.md [20]
     *   "Admin cannot delete a file outside the storage directory with a relative path."
     */
    public function adminCannotDeleteFileOutsideStorageDirectoryWithRelativePath(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->delete('/fluent-cart/v2/files/delete', [
            'driver'    => 'local',
            'file_path' => '../fct-delete-traversal-outside.txt',
        ]);

        Assert::assertSame(422, $status, 'a path escaping the storage directory must be refused');
        Assert::assertFileExists($this->outsideFile, 'the file outside the storage directory must still be on disk');
    }

    /**
     * Spec: Cases/storage-path-traversal.md [21]
     *   "Admin cannot delete a file at ABSPATH with a relative path."
     */
    public function adminCannotDeleteFileAtAbspathWithRelativePath(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $abspathFile = ABSPATH . 'fct-delete-traversal-abspath.txt';
        file_put_contents($abspathFile, 'wp-config geometry');

        try {
            [$status, $body] = $I->delete('/fluent-cart/v2/files/delete', [
                'driver'    => 'local',
                'file_path' => '../../../fct-delete-traversal-abspath.txt',
            ]);

            Assert::assertSame(422, $status, 'a path reaching ABSPATH must be refused');
            Assert::assertFileExists($abspathFile, 'the ABSPATH file must still be on disk');
        } finally {
            if (file_exists($abspathFile)) {
                unlink($abspathFile);
            }
        }
    }

    /**
     * Spec: Cases/storage-path-traversal.md [22]
     *   "Admin can still delete a file inside the storage directory."
     */
    public function adminCanStillDeleteFileInsideStorageDirectory(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->delete('/fluent-cart/v2/files/delete', [
            'driver'    => 'local',
            'file_path' => 'fct-delete-traversal-inside.txt',
        ]);

        Assert::assertSame(200, $status, 'deleting a file inside the storage directory must keep working');
        Assert::assertFileDoesNotExist($this->insideFile, 'the file inside the storage directory must be gone');
    }
}
