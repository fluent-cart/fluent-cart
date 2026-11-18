<?php

namespace FluentCart\App\Modules\Templating\Bricks;

use FluentCart\App\Http\Controllers\ModuleSettingsController;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\App\Services\PluginInstaller\AddonManager;
use FluentCart\Framework\Support\Arr;

/**
 * Builder canvas only: warns that the element moves to the Bricks add-on in the next release.
 * init() covers both the first canvas load and Bricks' re-render, which skips render filters.
 */
trait MovingToBricksAddonNotice
{
    public function init()
    {
        if ($this->is_frontend) {
            parent::init();
            return;
        }

        ob_start();
        parent::init();
        echo self::addMovingToBricksAddonNotice((string) ob_get_clean(), $this->get_label()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the element's own escaped output plus an escaped notice
    }

    // Inside the root tag, so Bricks still selects the element by its root.
    public static function addMovingToBricksAddonNotice($elementHtml, $elementLabel)
    {
        if (!preg_match('/^\s*<[a-z][^>]*>/i', $elementHtml, $rootOpeningTag)) {
            return $elementHtml;
        }

        $noticeHtml = sprintf(
            '<div class="fct-bricks-moving-to-addon-notice" role="note" style="%s"><strong style="display:block;font-weight:700">%s</strong>%s%s</div>',
            'grid-column:1/-1;flex-basis:100%;width:100%;box-sizing:border-box;margin:0 0 10px;padding:8px 12px;border-left:4px solid #b45309;background:#fffbeb;color:#78350f;font:400 13px/1.5 sans-serif;text-align:left;text-transform:none;letter-spacing:normal',
            esc_html(sprintf(
                /* translators: 1: Bricks element name, e.g. "Price Range". Title of a notice shown only in the Bricks builder. */
                __('%1$s is moving to the Bricks add-on', 'fluent-cart'),
                wp_strip_all_tags((string) $elementLabel)
            )),
            /* translators: Body of a notice shown only in the Bricks builder; "FluentCart Bricks Blocks" is the add-on's name. */
            esc_html__('From the next FluentCart release, this element comes with the FluentCart Bricks Blocks add-on. Install and activate the add-on before you update, so pages that use it keep working. Visitors do not see this notice.', 'fluent-cart'),
            self::bricksAddonLinksHtml()
        );

        // Bricks' placeholder is a flex row; inside it the notice would squeeze the icon and title.
        if (strpos($rootOpeningTag[0], 'bricks-element-placeholder') !== false) {
            return '<div>' . $noticeHtml . $elementHtml . '</div>';
        }

        $rootOpeningTagLength = strlen($rootOpeningTag[0]);

        return substr($elementHtml, 0, $rootOpeningTagLength) . $noticeHtml . substr($elementHtml, $rootOpeningTagLength);
    }

    // Install in place for users who can; the download page for everyone.
    private static function bricksAddonLinksHtml()
    {
        static $addons = null;
        if ($addons === null) {
            $addons = (new ModuleSettingsController())->getRegisteredPluginAddons();
        }
        $downloadUrl = (string) Arr::get($addons, 'fluent-cart-bricks-blocks.repo_link', '');

        $linkStyle = 'color:#78350f;text-decoration:underline;font-weight:600;margin-right:16px';
        // The Bricks canvas cancels every link click, so open the tab ourselves before it does.
        $openInNewTab = "event.stopPropagation();window.open(this.href,'_blank','noopener');return false;";
        $links = [];

        // The addons screen needs FluentCart admin access, and its button needs activate_plugins
        // when the add-on is installed or install_plugins when it is not; pickBuilderAddons() checks the latter.
        $canRunBricksAddonButton = (bool) AddonManager::pickBuilderAddons($addons, [['key' => 'fluent-cart-bricks-blocks', 'name' => 'Bricks']]);
        if ($canRunBricksAddonButton && PermissionManager::userCan('is_super_admin')) {
            $links[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener" style="%s" onclick="%s">%s</a>',
                // The addons page scrolls to and highlights the card whose plugin slug follows the second #.
                esc_url(admin_url('admin.php?page=fluent-cart#/settings/addons#fluent-cart-bricks-blocks')),
                $linkStyle,
                esc_attr($openInNewTab),
                esc_html__('Install from Features & Addons', 'fluent-cart')
            );
        }

        if ($downloadUrl) {
            $links[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener" style="%s" onclick="%s">%s</a>',
                esc_url($downloadUrl),
                $linkStyle,
                esc_attr($openInNewTab),
                esc_html__('Download the add-on', 'fluent-cart')
            );
        }

        return $links ? '<span style="display:block;margin-top:6px">' . implode('', $links) . '</span>' : '';
    }
}
