<?php

namespace FluentCart\App\Modules\FluentPlayer;

use FluentCart\Framework\Support\Arr;

/**
 * Single seam to the FluentPlayer plugin. Every call is feature-detected so
 * a missing or older FluentPlayer degrades to "no video" instead of a fatal.
 */
class FluentPlayerBridge
{
    const REST_NAMESPACE = 'fluent-player/v2';

    const MEDIA_POST_TYPE = 'fluent_player_media';

    const DEFAULT_AUTHORING_CAP = 'edit_others_posts';

    const DEFAULT_PRESET_SLUG = 'course';

    public static function isActive(): bool
    {
        $active = defined('FLUENT_PLAYER')
            && class_exists('\FluentPlayer\App\Services\MediaRenderer')
            && class_exists('\FluentPlayer\App\Models\Media');

        if (function_exists('apply_filters')) {
            $active = (bool) apply_filters('fluent_cart/fluent_player/is_active', $active);
        }

        return $active;
    }

    /**
     * True when the FluentPlayer plugin files are present but not (yet) active.
     */
    public static function isInstalled(): bool
    {
        if (self::isActive()) {
            return true;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return array_key_exists('fluent-player/fluent-player.php', get_plugins());
    }

    public static function hasPro(): bool
    {
        return self::isActive() && defined('FLUENT_PLAYER_PRO_VERSION');
    }

    public static function authoringCapability(): string
    {
        if (self::isActive() && method_exists('\FluentPlayer\App\Helpers\Helper', 'authoringCapability')) {
            $capability = \FluentPlayer\App\Helpers\Helper::authoringCapability();
            if (is_string($capability) && $capability !== '') {
                return $capability;
            }
        }

        return self::DEFAULT_AUTHORING_CAP;
    }

    public static function defaultPresetSlug(): string
    {
        if (self::isActive() && method_exists('\FluentPlayer\App\Services\PresetService', 'getDefaultSlug')) {
            $slug = \FluentPlayer\App\Services\PresetService::getDefaultSlug();
            if (is_string($slug) && $slug !== '') {
                return $slug;
            }
        }

        return self::DEFAULT_PRESET_SLUG;
    }

    public static function adminAppConfig(): array
    {
        if (!self::isActive()) {
            $installed = self::isInstalled();

            // Mirrors the capability AddonsController::installAndActivate() enforces,
            // so the tab does not offer a button whose request would come back 403:
            // the endpoint always activates, and also downloads when the addon is
            // absent.
            $canInstall = current_user_can('activate_plugins')
                && ($installed || current_user_can('install_plugins'));

            return [
                'active'     => false,
                'installed'  => $installed,
                'canInstall' => $canInstall,
            ];
        }

        return [
            'active'            => true,
            'hasPro'            => self::hasPro(),
            'canAuthor'         => current_user_can(self::authoringCapability()),
            'restUrl'           => rtrim(rest_url(self::REST_NAMESPACE), '/'),
            'editUrlBase'       => admin_url('post.php?action=edit&post='),
            'defaultPresetSlug' => self::defaultPresetSlug(),
        ];
    }

    /**
     * @return object|null Media the current visitor may see (FluentPlayer's
     *                     Media::findVisible: read_post + its can_view_media filter).
     */
    public static function findVisibleMedia(int $mediaId): ?object
    {
        if (!self::isActive() || $mediaId <= 0) {
            return null;
        }

        try {
            $media = \FluentPlayer\App\Models\Media::findVisible($mediaId);
        } catch (\Throwable $e) {
            self::reportFailure('find', $mediaId, $e);
            return null;
        }

        return $media ?: null;
    }

    /**
     * @return array|null ['id', 'title', 'poster', 'provider', 'src'], null when not visible.
     */
    public static function getMediaSummary(int $mediaId): ?array
    {
        $media = self::findVisibleMedia($mediaId);

        return $media ? self::summarize($media) : null;
    }

    /**
     * @param object $media A FluentPlayer media model (or anything exposing ID, post_title, settings).
     */
    public static function summarize($media): array
    {
        $settings = is_array($media->settings) ? $media->settings : [];

        return [
            'id'       => (int) $media->ID,
            'title'    => (string) $media->post_title,
            'poster'   => self::resolvePoster($settings),
            'provider' => (string) Arr::get($settings, 'provider', ''),
            'src'      => (string) Arr::get($settings, 'src', ''),
        ];
    }

    /**
     * Saved posterSrc, else the YouTube thumbnail FluentPlayer itself falls back to.
     */
    public static function resolvePoster(array $settings): string
    {
        $poster = trim((string) Arr::get($settings, 'posterSrc', ''));
        if ($poster !== '') {
            return $poster;
        }

        $src = (string) Arr::get($settings, 'src', '');
        if ($src === '' || !method_exists('\FluentPlayer\App\Helpers\Helper', 'extractYouTubeVideoId')) {
            return '';
        }

        $videoId = \FluentPlayer\App\Helpers\Helper::extractYouTubeVideoId($src);
        if (!is_string($videoId) || $videoId === '') {
            return '';
        }

        if (method_exists('\FluentPlayer\App\Helpers\Helper', 'getYouTubeFallbackPosterUrl')) {
            $poster = \FluentPlayer\App\Helpers\Helper::getYouTubeFallbackPosterUrl($videoId);
            if (is_string($poster) && $poster !== '') {
                return $poster;
            }
        }

        return 'https://i.ytimg.com/vi/' . rawurlencode($videoId) . '/hqdefault.jpg';
    }

    /**
     * Rendering also enqueues FluentPlayer's runtime and localizes the player
     * config. Password-protected media renders FluentPlayer's unlock form.
     */
    public static function renderPlayer(int $mediaId, string $extraClasses = ''): string
    {
        if (!self::findVisibleMedia($mediaId)) {
            return '';
        }

        try {
            $html = \FluentPlayer\App\Services\MediaRenderer::render($mediaId, $extraClasses);
        } catch (\Throwable $e) {
            self::reportFailure('render', $mediaId, $e);
            return '';
        }

        return is_string($html) ? $html : '';
    }

    /**
     * A FluentPlayer failure must never break the product page, but it must not
     * vanish either: it is logged under WP_DEBUG and exposed as an action.
     */
    protected static function reportFailure(string $stage, int $mediaId, \Throwable $e): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[FluentCart] FluentPlayer %s failed for media #%d: %s', $stage, $mediaId, $e->getMessage()));
        }

        do_action('fluent_cart/fluent_player/failed', $stage, $mediaId, $e);
    }
}
