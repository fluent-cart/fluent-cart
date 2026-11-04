<?php

namespace Tests\Support\Traits;

/**
 * Reads the intro block (`<div class="space_bottom_30">`) of each default email
 * template straight from source, so the greeting markup can be checked without
 * building the order, subscription or review fixtures every template needs.
 */
trait ReadsEmailIntroBlocks
{
    protected function emailViewsDir(): string
    {
        return dirname(__DIR__, 4) . '/app/Views/emails';
    }

    // Each template's first intro block, as [relative path => block source].
    protected function emailIntroBlocks(): array
    {
        $blocks = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->emailViewsDir()));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php' || strpos($path, '/parts/') !== false || basename($path) === 'general_template.php') {
                continue;
            }
            $source = file_get_contents($path);
            $start = strpos($source, '<div class="space_bottom_30">');
            if ($start === false) {
                continue;
            }
            $end = strpos($source, '</div>', $start);
            $blocks[substr($path, strlen($this->emailViewsDir()) + 1)] = substr($source, $start, $end - $start);
        }
        ksort($blocks);

        return $blocks;
    }

    protected function firstIntroParagraph(string $block): array
    {
        preg_match('/<p([^>]*)>(.*?)<\/p>/s', $block, $match);

        return ['attributes' => $match[1] ?? '', 'body' => $match[2] ?? ''];
    }

    protected function opensWithGreeting(string $paragraphBody): bool
    {
        return (bool) preg_match("/'(Hello( %(1\\$)?s)?,|Hey [Tt]here[^']*,)'/u", $paragraphBody);
    }
}
