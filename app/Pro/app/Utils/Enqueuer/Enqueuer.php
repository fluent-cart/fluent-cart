<?php

namespace FluentCartPro\App\Utils\Enqueuer;

use FluentCartPro\App\Core\App;

abstract class Enqueuer
{
    private static string $resourceDirectory = 'resources/';

    public static function getResourceDirectory(){
        return static::$resourceDirectory;
    }
    private function enqueueStyle($handle, $src, $dependency = [], $version = null){}
    private function enqueueStaticStyle($handle, $src, $dependency = [], $version = null){}
    private function enqueueScript($handle, $src, $dependency = [], $version = null, $inFooter = false){}
    private function enqueueStaticScript($handle, $src, $dependency = [], $version = null, $inFooter = false){}

    static function getEnqueuePath($path = ''){}

    static function getAssetPath(): string
    {
        return App::getInstance()['url.assets'];
    }

    static function getProductionFilePath($file)
    {
        return (static::getAssetPath() . $file['file']);
    }
}