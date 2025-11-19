<?php

namespace FluentCart\App\Hooks\CLI;

class ClipboardManager
{
    static function copyToClipBoard(string $text): bool
    {
        try {
            $os = php_uname('s');
            if (strpos($os, 'Linux') !== false) {
                exec("echo '" . $text . "' | xclip -selection clipboard");
            } elseif (strpos($os, 'Darwin') !== false) {
                $text = str_replace("'", "'\''", $text);
                exec("echo '" . $text . "' | pbcopy");
            } elseif (strpos($os, 'Windows') !== false) {
                exec("echo " . $text . " | clip");
            }


            if (class_exists('WP_CLI')) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo \WP_CLI::colorize( PHP_EOL."%GConfig Keys Copied To Clipboard%n".PHP_EOL );
            }else{
                echo esc_html__('Config Keys Copied To Clipboard', 'fluent-cart');
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
