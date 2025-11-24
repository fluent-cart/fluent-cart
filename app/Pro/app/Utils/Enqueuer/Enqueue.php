<?php

namespace FluentCartPro\App\Utils\Enqueuer;


class Enqueue
{

    private static $enqueuer = Vite::class;

    public static function style($handle, $src, $dependency = [], $version = null)
    {
        (static::$enqueuer)::enqueueStyle($handle, $src, $dependency, $version);
    }

    public static function staticStyle($handle, $src, $dependency, $version)
    {
        (static::$enqueuer)::enqueueStaticStyle($handle, $src, $dependency, $version);
    }

    public static function script($handle, $src, $dependency = [], $version = null, $inFooter = false)
    {
        return (static::$enqueuer)::enqueueScript($handle, $src, $dependency, $version, $inFooter);
    }

    public static function staticScript($handle, $src, $dependency = [], $version = null, $inFooter = false)
    {
        return (static::$enqueuer)::enqueueStaticScript($handle, $src, $dependency, $version, $inFooter);
    }

    public static function getStaticFilePath($path)
    {
        return (static::$enqueuer)::getStaticFilePath($path);
    }

    public static function enqueueAllStyles(array $styles, string $handlePrefix)
    {
        $loopCount = 0;

        foreach ($styles as $index => $style) {

            $dependency = [];
            $version = null;
            $media = 'all';
            $isStatic = false;

            if (is_array($style)) {
                $source = $style['source'] ?? null;
                $dependency = $style['dependencies'] ?? [];
                $version = $style['version'] ?? null;
                $media = $style['media'] ?? false;
                $isStatic = (isset($style['isStatic']) && $style['isStatic'] === true);

            } else {
                $source = $style;
            }


            if ($loopCount === 0) {
                $handle = $handlePrefix;
            } else {
                $handle = $handlePrefix . '_' . $index;
            }
            $loopCount++;

            if ($isStatic) {
                Vite::enqueueStaticStyle(
                    $handle,
                    $source,
                    $dependency,
                    $version,
                    $media
                );
            } else {
                Vite::enqueueStyle(
                    $handle,
                    $source,
                    $dependency,
                    $version,
                    $media
                );
            }
        }
    }

    public static function enqueueAllScripts(array $scripts, string $handlePrefix, $localizeableData = [])
    {
        $loopCount = 0;
        $isLocalized = false;
        foreach ($scripts as $index => $script) {

            $dependency = [];
            $version = null;
            $inFooter = false;
            $isStatic = false;

            if (is_array($script)) {
                $source = $script['source'] ?? null;
                $dependency = $script['dependencies'] ?? [];
                $version = $script['version'] ?? null;
                $inFooter = $script['inFooter'] ?? false;
                $isStatic = ($script['isStatic'] ?? false) === true;

            } else {
                $source = $script;
            }

            if (!$version) {
                $version = FLUENTCART_VERSION;
            }

            if ($loopCount < 1) {
                $handle = $handlePrefix;
            } else {
                $handle = $handlePrefix . '_' . $index;
            }

            if ($isStatic) {
                $vite = Vite::enqueueStaticScript(
                    $handle,
                    $source,
                    $dependency,
                    $version,
                    $inFooter
                );
            } else {

                $vite = Vite::enqueueScript(
                    $handle,
                    $source,
                    $dependency,
                    $version,
                    $inFooter
                );
            }

            // Localize the script only once during the first loop iteration,
            // and only if it's not already localized and the script is not static.
            if (($loopCount === 0 || !$isLocalized) && !$isStatic) {
                $vite->with($localizeableData);
                $isLocalized = true;
            }
            $loopCount++;
        }
    }

}