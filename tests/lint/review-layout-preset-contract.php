<?php
/**
 * Compare the shared review preset contract with both renderers.
 *
 * Run in the WordPress test site with:
 *   wp eval-file tests/lint/review-layout-preset-contract.php
 *
 * The Elementor plugin is optional; when active, its adapter is checked too.
 * A non-zero exit means a preset's Gutenberg template and semantic settings
 * have drifted, or Elementor has stopped forwarding one of those settings.
 */

use FluentCart\App\Services\Reviews\LayoutPresets;

if (!class_exists(LayoutPresets::class)) {
    fwrite(STDERR, "review-layout-preset-contract: FluentCart is not loaded\n");
    exit(2);
}

$walk = static function ($nodes, &$names, &$attrs) use (&$walk) {
    foreach ((array) $nodes as $node) {
        if (!is_array($node) || !isset($node[0])) {
            continue;
        }
        $names[] = $node[0];
        $attrs[$node[0]] = isset($node[1]) ? (array) $node[1] : [];
        if (isset($node[2]) && is_array($node[2])) {
            $walk($node[2], $names, $attrs);
        }
    }
};

/**
 * The Review Item node itself, so its own children can be read in order.
 * The flattening walk above keys by block name and loses both.
 */
$findItem = static function ($nodes) use (&$findItem) {
    foreach ((array) $nodes as $node) {
        if (!is_array($node) || !isset($node[0])) {
            continue;
        }
        if ($node[0] === 'fluent-cart/review-item') {
            return $node;
        }
        if (isset($node[2]) && is_array($node[2])) {
            $found = $findItem($node[2]);
            if ($found) {
                return $found;
            }
        }
    }

    return null;
};

/** Does this subtree contain a group carrying $class? */
$hasGroup = static function ($nodes, $class) use (&$hasGroup) {
    foreach ((array) $nodes as $node) {
        if (!is_array($node) || !isset($node[0])) {
            continue;
        }
        $nodeAttrs = (array) ($node[1] ?? []);
        if (strpos((string) ($nodeAttrs['className'] ?? ''), $class) !== false) {
            return true;
        }
        if (isset($node[2]) && is_array($node[2]) && $hasGroup($node[2], $class)) {
            return true;
        }
    }

    return false;
};

$templateFeatures = static function ($id) use ($walk, $findItem, $hasGroup) {
    $template = LayoutPresets::all()[$id]['template'];
    $names = [];
    $attrs = [];
    $walk($template, $names, $attrs);
    $list = $attrs['fluent-cart/product-review-list'] ?? [];
    $json = wp_json_encode($template);
    $summary = 'none';
    if (strpos($json, '320px') !== false) {
        $summary = in_array('fluent-cart/product-review-summary', $names, true) ? 'side' : 'cta';
    } elseif (in_array('core/spacer', $names, true)) {
        $summary = 'top';
    }
    $slider = (array) ($list['sliderSettings'] ?? []);

    // Arrangement, read off the Review Item's own children in order.
    $item = $findItem($template);
    $children = ($item[2] ?? []);
    $childNames = [];
    foreach ((array) $children as $child) {
        $childNames[] = is_array($child) && isset($child[0]) ? $child[0] : '';
    }
    $headAt = null;
    foreach ((array) $children as $index => $child) {
        if ($hasGroup([$child], 'fct-review-item-header-left')) {
            $headAt = $index;
            break;
        }
    }
    $photoAttrs = (array) ($attrs['fluent-cart/review-item-photos'] ?? []);

    return [
        // Read off where the byline sits, not off the photographs. row()
        // places the header after the words whenever the picture leads --
        // and Testimonials leads with a picture it does not show, so keying
        // this to the photos block reported it as byline-first while the
        // template built the opposite. That gap is what left the Elementor
        // stylesheet reordering the card with `order`, which is a divergence
        // between what is read and what is seen.
        'photos_first' => $headAt === null || $headAt > 0,
        // A bare Review Rating among the item's own children, rather than
        // inside the name line.
        'rating_first' => in_array('fluent-cart/review-item-rating', $childNames, true),
        'badge_last' => $hasGroup($children, 'fct-review-item-signoff'),
        'show_meta' => $headAt !== null,
        'item_class' => (string) (((array) ($item[1] ?? []))['className'] ?? ''),
        'media_flush' => !empty($photoAttrs['mediaFlush']),
        'media_backdrop' => !empty($photoAttrs['mediaBackdrop']),
        'view_mode' => $list['viewMode'] ?? 'list',
        'columns' => $list['gridColumns'] ?? 1,
        'per_page' => $list['perPage'] ?? null,
        'has_media' => !empty($list['hasMedia']),
        'pagination' => $attrs['fluent-cart/review-list-pagination']['paginationType'] ?? 'none',
        'summary' => $summary,
        'count' => in_array('fluent-cart/review-list-count', $names, true),
        'filters' => in_array('fluent-cart/review-list-filter', $names, true),
        'sorting' => in_array('fluent-cart/review-list-sorting', $names, true),
        'avatar' => in_array('fluent-cart/review-item-avatar', $names, true),
        'date' => in_array('fluent-cart/review-item-date', $names, true),
        'title' => in_array('fluent-cart/review-item-title', $names, true),
        'content' => in_array('fluent-cart/review-item-content', $names, true),
        'photos' => in_array('fluent-cart/review-item-photos', $names, true),
        'footer' => in_array('fluent-cart/review-item-votes', $names, true),
        // Whether the store's reply can appear at all. row() puts it in the
        // same footer group as the votes, so the template answers this by
        // carrying the block -- and a builder that draws the footer but
        // suppresses the reply disagrees with a layout it claims to match.
        'reply' => in_array('fluent-cart/review-item-reply', $names, true),
        'variation' => in_array('fluent-cart/review-item-variation-title', $names, true),
        'badge' => in_array('fluent-cart/review-item-verified-badge', $names, true),
        'sort_by' => $list['defaultSortBy'] ?? 'created_at',
        'slider_arr' => ($slider['arrows'] ?? 'no') === 'yes',
        'slider_pag' => ($slider['pagination'] ?? 'no') === 'yes',
        'autoplay' => ($slider['autoplay'] ?? 'no') === 'yes',
        // The settings that shape a slider rather than switch one on. Left
        // unchecked, a preset could ask for small arrows beneath the track and
        // the other builder would draw medium ones over it, with every
        // feature flag agreeing.
        'arrows_size' => $slider['arrowsSize'] ?? 'md',
        'arrows_pos' => $slider['arrowsPosition'] ?? 'overlap',
        'autoplay_delay' => (int) ($slider['autoplayDelay'] ?? 3000),
    ];
};

$builderFeatures = static function ($id) {
    $config = LayoutPresets::builderSettings($id);
    $section = (array) ($config['section'] ?? []);
    $header = (array) ($config['header'] ?? []);
    $list = (array) ($config['list'] ?? []);
    $item = (array) ($config['item'] ?? []);
    $sort = (array) ($list['sort'] ?? []);
    $slider = (array) ($list['slider'] ?? []);

    return [
        'view_mode' => $list['view_mode'] ?? 'list',
        'columns' => $list['columns'] ?? 1,
        'per_page' => $list['per_page'] ?? null,
        'has_media' => !empty($list['has_media']),
        'pagination' => $list['pagination'] ?? 'none',
        'summary' => $section['summary'] ?? 'side',
        'count' => !empty($header['count']),
        'filters' => !empty($header['filters']),
        'sorting' => !empty($header['sorting']),
        'avatar' => $item['show_avatar'] ?? true,
        'date' => $item['show_date'] ?? true,
        'title' => $item['show_title'] ?? true,
        'content' => $item['show_content'] ?? true,
        'photos' => $item['show_photos'] ?? true,
        'footer' => $item['show_footer'] ?? true,
        'reply' => $item['show_footer'] ?? true,
        'variation' => $item['show_variation'] ?? true,
        'badge' => $item['show_badge'] ?? true,
        'photos_first' => !empty($item['photos_first']),
        'rating_first' => !empty($item['rating_first']),
        'badge_last' => !empty($item['badge_last']),
        'show_meta' => $item['show_meta'] ?? true,
        'item_class' => (string) ($item['class'] ?? ''),
        'media_flush' => !empty(((array) ($item['media'] ?? []))['flush']),
        'media_backdrop' => !empty(((array) ($item['media'] ?? []))['backdrop']),
        'sort_by' => $sort['by'] ?? 'created_at',
        'slider_arr' => !empty($slider['arrows']),
        'slider_pag' => !empty($slider['pagination']),
        'autoplay' => !empty($slider['autoplay']),
        'arrows_size' => $slider['arrows_size'] ?? 'md',
        'arrows_pos' => $slider['arrows_position'] ?? 'overlap',
        'autoplay_delay' => (int) ($slider['autoplay_delay'] ?? 3000),
    ];
};

$normalise = static function ($value) {
    return is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
};

$mismatches = [];

// Declared by every preset. No renderer reads it, which is exactly why it can
// be dropped without anything failing -- a bulk edit once removed it from ten
// presets and every other check stayed green.
foreach (array_keys(LayoutPresets::all()) as $id) {
    $style = (string) (((array) (LayoutPresets::builderSettings($id)['item'] ?? []))['style'] ?? '');
    if ($style === '') {
        $mismatches[] = [$id, 'builder.item.style', 'declared', 'missing'];
    }
}

foreach (array_keys(LayoutPresets::all()) as $id) {
    $template = $templateFeatures($id);
    $builder = $builderFeatures($id);
    foreach ($template as $key => $value) {
        if ($normalise($value) !== $normalise($builder[$key] ?? null)) {
            $mismatches[] = [$id, 'builder.' . $key, $normalise($value), $normalise($builder[$key] ?? null)];
        }
    }
}

$elementorClass = 'FluentCartElementorBlocks\\App\\Modules\\Integrations\\Elementor\\Support\\ReviewLayoutPresets';
if (class_exists($elementorClass)) {
    foreach (array_keys(LayoutPresets::all()) as $id) {
        $expected = $builderFeatures($id);
        $options = $elementorClass::rendererOptions($id);
        $actual = [
            'view_mode' => $options['viewMode'] ?? 'list',
            'columns' => $options['gridColumns'] ?? 1,
            'per_page' => $options['perPage'] ?? null,
            'has_media' => !empty($options['hasMedia']),
            'pagination' => !empty($options['showPagination']) ? ($options['paginationType'] ?? 'none') : 'none',
            'summary' => empty($options['showSummary']) ? 'none' : ($options['summaryMode'] ?? 'side'),
            'count' => !empty($options['showCount']),
            'filters' => !empty($options['showFilterChips']),
            'sorting' => !empty($options['showSortControls']),
            'avatar' => $options['showAvatar'] ?? true,
            'date' => $options['showReviewDate'] ?? true,
            'title' => $options['showTitle'] ?? true,
            'content' => $options['showContent'] ?? true,
            'photos' => $options['showPhotos'] ?? true,
            'footer' => $options['showFooter'] ?? true,
            // Read strictly, with no permissive default: an adapter that
            // leaves this unsaid hands the question back to the widget, and a
            // widget with a preset chosen has had its layout controls switched
            // off -- Elementor blanks a control it has switched off, so the
            // widget answers no. Unsaid is the failure, so unsaid must fail.
            'reply' => !empty($options['showViewReply']) && ($options['showFooter'] ?? true),
            'variation' => $options['showVariation'] ?? true,
            'badge' => $options['showVerifiedBadge'] ?? true,
            'photos_first' => !empty($options['photosFirst']),
            'rating_first' => !empty($options['ratingFirst']),
            'badge_last' => !empty($options['badgeLast']),
            'show_meta' => $options['showMeta'] ?? true,
            'item_class' => (string) ($options['itemClass'] ?? ''),
            'media_flush' => !empty($options['mediaFlush']),
            'media_backdrop' => !empty($options['mediaBackdrop']),
            'sort_by' => $options['defaultSortBy'] ?? 'created_at',
            'slider_arr' => (($options['sliderSettings']['arrows'] ?? 'no') === 'yes'),
            'slider_pag' => (($options['sliderSettings']['pagination'] ?? 'no') === 'yes'),
            'autoplay' => (($options['sliderSettings']['autoplay'] ?? 'no') === 'yes'),
            'arrows_size' => $options['sliderSettings']['arrowsSize'] ?? 'md',
            'arrows_pos' => $options['sliderSettings']['arrowsPosition'] ?? 'overlap',
            'autoplay_delay' => (int) ($options['sliderSettings']['autoplayDelay'] ?? 3000),
        ];
        foreach ($expected as $key => $value) {
            if ($normalise($value) !== $normalise($actual[$key] ?? null)) {
                $mismatches[] = [$id, 'elementor.' . $key, $normalise($value), $normalise($actual[$key] ?? null)];
            }
        }
    }
}

if (!$mismatches) {
    echo "review-layout-preset-contract: OK\n";
    exit(0);
}

foreach ($mismatches as $mismatch) {
    printf("%s %s: Gutenberg=%s, actual=%s\n", $mismatch[0], $mismatch[1], $mismatch[2], $mismatch[3]);
}
fwrite(STDERR, 'review-layout-preset-contract: ' . count($mismatches) . " mismatch(es)\n");
exit(1);
