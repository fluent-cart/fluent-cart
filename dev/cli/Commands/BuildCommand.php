<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ZipArchive;

class BuildCommand extends BaseCommand
{
    protected static $defaultName = 'app:build';

    protected function configure(): void
    {
        $this->setDescription('Builds a zip archive of the plugin excluding specified files and folders.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pluginDir = $this->root;
        $tempDir = $pluginDir . '/buildtemp';
        $zipFile = $pluginDir . '/' . basename($pluginDir) . '.zip';

        $excludeList = [
            '.git', '.gitignore', '.gitmodules', 'README.md',
            'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
            'dev', 'buildtemp', 'node_modules', 'webpack.mix.js', 'vite.config.js',
            'wp-tests-config.php', 'wpf', 'wpflog',
        ];

        // Ask developer if resources folder should be included
        $includeResources = $io->confirm('Include resources folder?', false);
        if (!$includeResources) {
            $excludeList[] = 'resources';
        }

        if (!is_dir($tempDir) && !mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
            $io->writeln(
                "<fg=red>Failed to create temporary directory: <fg=white>{$tempDir}.</></>"
            );
            return static::FAILURE;
        }

        $io->title('Copying files...');

        $this->recursiveCopy($pluginDir, $tempDir, $excludeList);

        $io->title('Creating zip archive...');

        if (!$this->createZipArchive($tempDir, $zipFile)) {
            $io->writeln("<fg=red>Failed to create zip archive.</>");
            $this->deleteDirectory($tempDir);
            return static::FAILURE;
        }

        $io->title('Cleaning up temporary files...');

        $this->deleteDirectory($tempDir);
        $io->writeln(
            "<info>The zip file has been created at: <fg=white>{$zipFile}.</></info>"
        );

        return static::SUCCESS;
    }

    private function recursiveCopy(string $src, string $dst, array $excludeList): void
    {
        $dir = opendir($src);
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            if (in_array($file, $excludeList, true)) continue;

            $srcPath = $src . DIRECTORY_SEPARATOR . $file;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcPath)) {
                $this->recursiveCopy($srcPath, $dstPath, $excludeList);
            } else {
                copy($srcPath, $dstPath);
            }
        }

        closedir($dir);
    }

    private function createZipArchive(string $sourceDir, string $zipFile): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $sourceDir = realpath($sourceDir);
        if ($sourceDir === false) return false;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourceDir) + 1);

                // Skip hidden files/folders
                if (preg_match('/(^|\/)\./', $relativePath)) continue;

                $zip->addFile($filePath, $relativePath);
            }
        }

        return $zip->close();
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
