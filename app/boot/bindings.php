<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
// Add to your bindings.php file
$app->singleton(\FluentCart\App\Services\Localization\LocalizationManager::class, function($app) {
    return \FluentCart\App\Services\Localization\LocalizationManager::getInstance();
});
$app->alias(\FluentCart\App\Services\Localization\LocalizationManager::class, 'localization');