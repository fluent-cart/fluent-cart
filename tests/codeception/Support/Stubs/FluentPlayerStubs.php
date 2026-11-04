<?php
/**
 * Minimal stand-ins for the FluentPlayer classes FluentCart's bridge feature-detects.
 *
 * The disposable test install never loads FluentPlayer, so the renderer tests
 * declare these once (guarded) and drive them through the static registries.
 * Each stub mirrors only the signature the bridge calls.
 */

namespace FluentPlayer\App\Models {
    if (!class_exists(Media::class, false)) {
        class Media
        {
            /** @var array<int, object> media id => visible media object */
            public static $visible = [];

            /** @var int[] media ids whose lookup throws */
            public static $throwFor = [];

            public static function findVisible($id)
            {
                if (in_array((int) $id, self::$throwFor, true)) {
                    throw new \RuntimeException('stub: media lookup failed for #' . (int) $id);
                }

                return self::$visible[(int) $id] ?? null;
            }

            public static function reset(): void
            {
                self::$visible = [];
                self::$throwFor = [];
            }
        }
    }
}

namespace FluentPlayer\App\Services {
    if (!class_exists(MediaRenderer::class, false)) {
        class MediaRenderer
        {
            /** @var array<int, array{0:int,1:string}> */
            public static $rendered = [];

            /** @var int[] media ids whose render throws */
            public static $throwFor = [];

            public static function render($mediaId, $extraClasses = '', $overrides = [])
            {
                if (in_array((int) $mediaId, self::$throwFor, true)) {
                    throw new \RuntimeException('stub: render failed for #' . (int) $mediaId);
                }

                self::$rendered[] = [(int) $mediaId, (string) $extraClasses];

                return '<div class="fluent-player fp-stub ' . $extraClasses . '" data-var_name="fluent_player_' . (int) $mediaId . '_' . count(self::$rendered) . '"></div>';
            }

            public static function reset(): void
            {
                self::$rendered = [];
                self::$throwFor = [];
            }
        }
    }

    if (!class_exists(PresetService::class, false)) {
        class PresetService
        {
            public static function getDefaultSlug()
            {
                return 'course';
            }
        }
    }
}

namespace FluentPlayer\App\Helpers {
    if (!class_exists(Helper::class, false)) {
        class Helper
        {
            public static function authoringCapability()
            {
                return 'edit_others_posts';
            }

            public static function extractYouTubeVideoId($urlOrId)
            {
                if (preg_match('#(?:youtu\\.be/|v=)([A-Za-z0-9_-]{11})#', (string) $urlOrId, $m)) {
                    return $m[1];
                }

                return '';
            }

            public static function getYouTubeFallbackPosterUrl($videoId)
            {
                return $videoId ? 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg' : '';
            }
        }
    }
}

namespace {
    if (!defined('FLUENT_PLAYER')) {
        define('FLUENT_PLAYER', true);
    }
}
