<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

/**
 ***** DO NOT CALL ANY FUNCTIONS DIRECTLY FROM THIS FILE ******
 *
 * This file will be loaded even before the framework is loaded
 * so the $app is not available here, only declare functions here.
 */

$globalsDevFile = __DIR__ . '/globals_dev.php';

is_readable($globalsDevFile) && include $globalsDevFile;




function fluentCart($module = false)
{
    return \FluentCart\App\App::getInstance($module);
}

/**
 *
 * @return \FluentCart\App\Helpers\FluentCartUtilHelper
 */
function fluentCartUtil()
{
    static $class;

    if (!$class) {
        $class = new \FluentCart\App\Helpers\FluentCartUtilHelper();
    }

    return $class;
}


function fluent_cart_success_log($title, $content, $otherInfo = [])
{
    fluent_cart_add_log($title, $content, 'success', $otherInfo);
}

function fluent_cart_error_log($title, $content, $otherInfo = [])
{
    if (!defined('FLUENT_CART_DEV_MODE') || !FLUENT_CART_DEV_MODE) {
        return;
    }

    fluent_cart_add_log($title, $content, 'error', $otherInfo);
}

function fluent_cart_warning_log($title, $content, $otherInfo = [])
{
    fluent_cart_add_log($title, $content, 'warning', $otherInfo);
}

function fluent_cart_add_log($title, $content, $logStatus = "info", $otherInfo = [])
{
    $user = wp_get_current_user();
    $title = sanitize_text_field($title);

    if (!$content || !is_string($content)) {
        $content = '';
    }

    $default = [
        'title'       => $title,
        'content'     => wp_kses_post($content),
        'status'      => sanitize_text_field($logStatus), // info, error, warning, success
        'log_type'    => 'activity', // api
        'module_type' => '', // Namespace/path/
        'module_id'   => null, // module id
        'module_name' => 'order', // 'order', 'product', 'user', 'coupon', 'subscription', 'payment', 'refund', 'shipment', 'activity
        'user_id'     => get_current_user_id() ?? 0,
        'created_by'  => empty($user) ? 'FCT-BOT' : ($user->display_name ?? 'FCT-BOT'),
    ];

    $allowedModels = [
        'order',
        'product',
        'productVariation',
        'user',
        'coupon',
        'subscription'
    ];

    $allowedModels = apply_filters('fluent_cart/logs/allowed_models', $allowedModels);

    $data = array_merge($default, $otherInfo);
    //sanitize data
    $data['user_id'] = intval($data['user_id']);
    $data['created_by'] = sanitize_text_field($data['created_by']);
    $data['module_id'] = intval($data['module_id']);
    $data['module_name'] = sanitize_text_field($data['module_name']);

    $moduleName = $data['module_name'] ?: '';

    if (empty($data['module_type']) && !empty($data['module_name']) && in_array(strtolower($moduleName), $allowedModels)) {
        $data['module_type'] = 'FluentCart\\App\\Models\\' . ucfirst($data['module_name']);
    }

    $data['module_type'] = sanitize_text_field($data['module_type']);
    $data['log_type'] = sanitize_text_field($data['log_type']);

    $log = \FluentCart\Api\Resource\ActivityResource::create($data);

    if (\FluentCart\Framework\Support\Arr::get($otherInfo, 'trigger_admin_alert_email') === 'yes') {
        $mailingSettings = \FluentCart\App\Services\Email\EmailNotifications::getSettings();
        $toEmail = \FluentCart\Framework\Support\Arr::get($mailingSettings, 'admin_email', '');

        if ($toEmail) {
            $body = \FluentCart\App\App::make('view')->make('emails.general_template', [
                'preHeader'   => '',
                'emailBody'   => $content,
                'header'      => '',
                'emailFooter' => ''
            ]);
            (new \FluentCart\App\Services\Email\Mailer($toEmail, 'FluentCart Log Alert: ' . $title, $body))->send();
        }
    }

    return $log;
}


/**
 *
 * @return \FluentCart\Api\Resource\FrontendResource\FluentMetaResource;
 *
 * param String $meta_key
 * param Boolean $default
 */
function fluent_cart_get_option($meta_key, $default = false, $cache = true)
{
    static $caches = [];

    if (isset($caches[$meta_key]) && $cache) {
        return $caches[$meta_key];
    }

    $exist = \FluentCart\App\Models\Meta::query()
        ->where('meta_key', $meta_key)
        ->where('object_type', 'option')
        ->first();

    if ($exist) {
        $caches[$meta_key] = $exist->meta_value;
        return $exist->meta_value;
    }

    $caches[$meta_key] = $default;

    return $default;
}

/**
 *
 * @return \FluentCart\App\Models\Meta|\FluentCart\Framework\Database\Orm\Builder;
 *
 * param $meta_key, $meta_value
 */
function fluent_cart_update_option($meta_key, $meta_value)
{

    $exist = \FluentCart\App\Models\Meta::query()
        ->where('meta_key', $meta_key)
        ->where('object_type', 'option')
        ->first();

    if ($exist) {
        $exist->meta_value = $meta_value;
        $exist->save();
        fluent_cart_get_option($meta_key, null, false); // reset cache
        return $exist;
    }

    $data = [
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_key'    => $meta_key,
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        'meta_value'  => $meta_value,
        'object_type' => 'option'
    ];

    $result = \FluentCart\App\Models\Meta::query()->create($data);

    fluent_cart_get_option($meta_key, null, false); // reset cache

    return $result;
}

function fluent_cart_api()
{
    return \FluentCart\Api\FluentCartGeneralApi::getInstance();
}


function fluent_cart_get_current_product()
{
    if (isset($GLOBALS['fct_product']) && $GLOBALS['fct_product'] instanceof \FluentCart\App\Models\Product) {
        return $GLOBALS['fct_product'];
    }

    // maybe it's too early
    $post = get_post();
    if ($post instanceof WP_Post && $post->post_type === \FluentCart\App\CPT\FluentProducts::CPT_NAME) {
        return \FluentCart\App\Modules\Data\ProductDataSetup::getProductModel($post->ID);
    }

    return null;
}
