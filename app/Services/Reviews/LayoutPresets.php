<?php

namespace FluentCart\App\Services\Reviews;

/**
 * The layouts a review section can be built from.
 *
 * A preset has two representations: a Gutenberg template and a builder-neutral
 * semantic contract. Gutenberg uses the template; other builders use
 * builderSettings() and create their own native markup.
 *
 * Declared here rather than in the block editor's JavaScript so every builder
 * reads the same IDs, labels, Pro flags and semantic intent.
 *
 * Each template is an inner-blocks template — [name, attributes, innerBlocks] —
 * the shape wp.blocks.createBlocksFromInnerBlocksTemplate() takes.
 */
class LayoutPresets
{
    /**
     * An attribute map with nothing in it.
     *
     * An object, not an empty array: these templates travel to the editor as
     * JSON, and PHP would write an empty array as [] where the editor expects
     * {}. The two behave the same once parsed, but the wire format should say
     * what it means.
     */
    protected static function noAttributes()
    {
        return (object) [];
    }

    /**
     * The review card.
     *
     * The groups are not decoration. A field block is block-level, so a flat
     * row stacks the avatar, the name, the stars and the badge down the card,
     * each spanning its full width — which is what turns a Verified Purchase
     * chip into a green bar. The header group puts the avatar beside a meta
     * column, and the name line puts the name, stars, badge and variation on
     * one line.
     */
    public static function row(array $fields = []): array
    {
        $fields = array_merge([
            'photos'          => [],
            'photosFirst'     => false,
            'showAvatar'      => true,
            'showDate'        => true,
            'showTitle'       => true,
            'showFooter'      => true,
            'showPhotos'      => true,
            'showContent'     => true,
            'showMeta'        => true,
            'photosAfterMeta' => false,
            'badgeLast'       => false,
            'ratingFirst'     => false,
            'showBadge'       => true,
            'showVariation'   => true,
            'cardClass'       => '',
        ], $fields);

        $badge = ['fluent-cart/review-item-verified-badge'];
        $stars = ['fluent-cart/review-item-rating'];

        $nameLineChildren = [['fluent-cart/review-item-author-name']];

        if (!$fields['ratingFirst']) {
            $nameLineChildren[] = $stars;
        }

        if ($fields['showBadge'] && !$fields['badgeLast']) {
            $nameLineChildren[] = $badge;
        }

        if ($fields['showVariation']) {
            $nameLineChildren[] = ['fluent-cart/review-item-variation-title'];
        }

        $nameLine = ['core/group', [
            'className' => 'fct-review-item-name-line',
            'layout'    => ['type' => 'flex', 'flexWrap' => 'wrap'],
            'style'     => ['spacing' => ['blockGap' => ['top' => '4px', 'left' => '8px']]],
        ], $nameLineChildren];

        $metaTextChildren = [$nameLine];

        if ($fields['showDate']) {
            $metaTextChildren[] = ['fluent-cart/review-item-date'];
        }

        $headChildren = [];

        if ($fields['showAvatar']) {
            $headChildren[] = ['fluent-cart/review-item-avatar'];
        }

        $headChildren[] = ['core/group', [
            'className' => 'fct-review-item-meta-text',
            'layout'    => ['type' => 'flex', 'orientation' => 'vertical'],
            'style'     => ['spacing' => ['blockGap' => '2px']],
        ], $metaTextChildren];

        $head = ['core/group', [
            'className' => 'fct-review-item-header-left',
            'layout'    => ['type' => 'flex', 'flexWrap' => 'nowrap', 'verticalAlignment' => 'center'],
            'style'     => ['spacing' => ['blockGap' => '10px']],
        ], $headChildren];

        $media = ['fluent-cart/review-item-photos', $fields['photos'] ?: static::noAttributes()];

        $body = [];

        if ($fields['showTitle']) {
            $body[] = ['fluent-cart/review-item-title'];
        }

        if ($fields['showContent']) {
            $body[] = ['fluent-cart/review-item-content'];
        }

        // Reply left, votes right. PRO's script looks for this group when it
        // appends the Helpful buttons, and its space-between is what puts them
        // on the right.
        $footer = ['core/group', [
            'className' => 'fct-review-footer',
            'layout'    => ['type' => 'flex', 'flexWrap' => 'wrap', 'justifyContent' => 'space-between'],
            'style'     => ['spacing' => ['blockGap' => '10px']],
        ], [
            ['fluent-cart/review-item-reply'],
            ['fluent-cart/review-item-votes'],
        ]];

        $tail = $fields['showFooter'] ? [$footer] : [];
        $picture = $fields['showPhotos'] ? [$media] : [];
        // Who wrote it, and how they rated it. Off, the card is whatever is
        // left — which for a photo strip is the photograph alone.
        $meta = $fields['showMeta'] ? [$head] : [];

        // Signed off at the end rather than beside the name: on a card the eye
        // reads downwards, the badge is the last word, not part of the greeting.
        //
        // Wrapped, not bare. The badge paints a background and a field block is
        // block-level, so on its own it stretches the green pill across the
        // whole card -- the same reason the name line and the footer are groups
        // rather than loose blocks. A flex parent sizes it to its text.
        $sign = ($fields['badgeLast'] && $fields['showBadge']) ? [['core/group', [
            'className' => 'fct-review-item-signoff',
            'layout'    => ['type' => 'flex', 'flexWrap' => 'wrap'],
            'style'     => ['spacing' => ['blockGap' => ['top' => '4px', 'left' => '8px']]],
        ], [$badge]]] : [];

        // Stars above the words rather than beside the name: on a card the
        // rating is the headline, and a wall of cards is read by its stars long
        // before any of its sentences.
        $lead = $fields['ratingFirst'] ? [$stars] : [];

        // A class on the card, not a preset name read at render time: the same
        // way this file already names the header row, the footer and the
        // signoff. Left off entirely when there is none, so the presets that do
        // not set one keep matching their own templates.
        $card = $fields['cardClass'] ? ['className' => $fields['cardClass']] : static::noAttributes();

        // Three arrangements. Photographs first is a card the picture sells and
        // the words explain. Meta first with the photograph under it is the one
        // a testimonial wants: who is speaking, then their face of the product,
        // then what they said. Otherwise the words lead and the picture
        // supports.
        if ($fields['photosAfterMeta']) {
            return ['fluent-cart/review-item', $card, array_merge($meta, $picture, $lead, $body, $sign, $tail)];
        }

        return ['fluent-cart/review-item', $card, $fields['photosFirst']
            ? array_merge($picture, $lead, $body, $meta, $sign, $tail)
            : array_merge($meta, $lead, $body, $picture, $sign, $tail)];
    }

    /**
     * What sits above the rows. Each control is its own block, so a layout that
     * does not want one leaves it out — which is the only way to say "no filter
     * bar", a filter bar being a block rather than a setting.
     *
     * The chips and the sort share a line: separately they are two block-level
     * elements and stack, stranding the sort on a line of its own.
     */
    public static function listHeader(array $head = []): array
    {
        $head = array_merge(['count' => true, 'filter' => false, 'sorting' => false], $head);

        $controls = [];

        if ($head['filter']) {
            $controls[] = ['fluent-cart/review-list-filter'];
        }

        if ($head['sorting']) {
            $controls[] = ['fluent-cart/review-list-sorting'];
        }

        $blocks = [];

        if ($head['count']) {
            $blocks[] = ['fluent-cart/review-list-count'];
        }

        if ($controls) {
            $blocks[] = ['core/group', [
                'className' => 'fct-reviews-controls',
                'layout'    => [
                    'type'              => 'flex',
                    'flexWrap'          => 'wrap',
                    'justifyContent'    => 'space-between',
                    'verticalAlignment' => 'center',
                ],
            ], $controls];
        }

        return $blocks;
    }

    /**
     * The whole section.
     *
     * 'side' puts the score in a 320px column beside the reviews, which is what
     * the block scaffolds. 'top' puts it across the full width above them — the
     * arrangement a shopper meets on the large marketplaces. 'cta' keeps the
     * column but puts only the invitation to write a review in it.
     */
    public static function section(array $args = []): array
    {
        $args = array_merge([
            'list'       => [],
            'head'       => [],
            'pagination' => false,
            'fields'     => [],
            'summaryOn'  => 'side',
        ], $args);

        $summary = ['fluent-cart/product-review-summary-group', static::noAttributes(), [
            ['fluent-cart/product-review-summary', static::noAttributes()],
            ['fluent-cart/write-a-review-button', static::noAttributes()],
        ]];

        $listChildren = array_merge(
            static::listHeader($args['head']),
            [static::row($args['fields'])]
        );

        if ($args['pagination']) {
            $listChildren[] = ['fluent-cart/review-list-pagination', ['paginationType' => $args['pagination']]];
        }

        $reviews = ['fluent-cart/product-review-list', $args['list'] ?: static::noAttributes(), $listChildren];

        if ($args['summaryOn'] === 'top') {
            return [
                $summary,
                ['core/spacer', ['height' => '24px']],
                $reviews,
            ];
        }

        // A strip is meant to sit above a review list rather than replace one,
        // and that list is where a shopper reads the average and the breakdown
        // — repeating the score card beside a band of photographs gives the
        // section two things competing for the eye and leaves the card running
        // twice the height of the band.
        if ($args['summaryOn'] === 'cta') {
            return [
                ['core/columns', ['style' => ['spacing' => ['blockGap' => '24px']]], [
                    ['core/column', ['width' => '320px'], [
                        ['fluent-cart/product-review-summary-group', static::noAttributes(), [
                            ['fluent-cart/write-a-review-button', static::noAttributes()],
                        ]],
                    ]],
                    ['core/column', static::noAttributes(), [$reviews]],
                ]],
            ];
        }

        return [
            ['core/columns', ['style' => ['spacing' => ['blockGap' => '24px']]], [
                ['core/column', ['width' => '320px'], [$summary]],
                ['core/column', static::noAttributes(), [$reviews]],
            ]],
        ];
    }

    /**
     * Builder-neutral intent for a preset.
     *
     * Gutenberg consumes the block template returned by all(). Other builders
     * should consume this contract and create their own native markup. The
     * values describe the review section, rather than naming Gutenberg blocks.
     *
     * item.style and item.class are not the same thing and neither is
     * redundant. The class is core's stylesheet: a builder drawing its rows
     * with ProductReviewRenderer puts it on the card and gets the card core
     * draws. The style is the intent behind it -- 'card', 'testimonial',
     * 'photo-only' -- for a builder that writes its own markup and has its own
     * stylesheet, which a core class name would tell nothing. Nothing in this
     * repository reads style today, because both builders here render through
     * core; it is the field that lets a third stop doing that.
     *
     * @return array<string, mixed>
     */
    public static function builderSettings(string $preset): array
    {
        $settings = [
            'classic' => [
                'section' => ['summary' => 'side'],
                'header'  => ['count' => true, 'filters' => true, 'sorting' => true],
                'list'    => ['view_mode' => 'list', 'columns' => 1, 'per_page' => 10, 'pagination' => 'numbers'],
                'item'    => ['style' => 'classic'],
            ],
            'minimal' => [
                'section' => ['summary' => 'side'],
                'header'  => ['count' => false, 'filters' => false, 'sorting' => false],
                'list'    => ['view_mode' => 'list', 'columns' => 1, 'per_page' => 5, 'pagination' => 'numbers'],
                'item'    => ['style' => 'minimal', 'show_date' => false],
            ],
            'compact' => [
                'section' => ['summary' => 'side'],
                'header'  => ['count' => false, 'filters' => false, 'sorting' => false],
                'list'    => ['view_mode' => 'list', 'columns' => 1, 'per_page' => 3, 'pagination' => 'none'],
                'item'    => [
                    'style' => 'compact',
                    'show_avatar' => false,
                    'show_date'   => false,
                    'show_title'  => false,
                    'show_footer' => false,
                    'show_photos' => false,
                ],
            ],
            'grid' => [
                'section' => ['summary' => 'side'],
                'header'  => ['count' => true, 'filters' => true, 'sorting' => true],
                'list'    => ['view_mode' => 'grid', 'columns' => 2, 'per_page' => 10, 'pagination' => 'fraction'],
                'item'    => ['style' => 'card'],
            ],
            'masonry' => [
                'section' => ['summary' => 'side'],
                'header'  => ['count' => true, 'filters' => true, 'sorting' => true],
                'list'    => ['view_mode' => 'masonry', 'columns' => 3, 'per_page' => 12, 'pagination' => 'numbers'],
                'item'    => ['style' => 'card'],
            ],
            'summary-top' => [
                'section' => ['summary' => 'top'],
                'header'  => ['count' => true, 'filters' => true, 'sorting' => true],
                'list'    => ['view_mode' => 'list', 'columns' => 1, 'per_page' => 10, 'pagination' => 'numbers'],
                'item'    => ['style' => 'classic'],
            ],
            'photo-grid' => [
                'section' => ['summary' => 'side'],
                'header'  => ['count' => true, 'filters' => true, 'sorting' => false],
                'list'    => [
                    'view_mode' => 'grid', 'columns' => 3, 'per_page' => 6,
                    'pagination' => 'fraction', 'has_media' => true,
                ],
                'item' => [
                    'style' => 'photo-grid', 'class' => 'fct-review-photo-grid',
                    'photos_first' => true, 'show_date' => false,
                    'media' => ['visible' => 1, 'full_width' => true, 'flush' => true],
                ],
            ],
            'photo-wall' => [
                'section' => ['summary' => 'side'],
                'header'  => ['count' => true, 'filters' => false, 'sorting' => false],
                'list'    => [
                    'view_mode' => 'grid', 'columns' => 4, 'per_page' => 8,
                    'pagination' => 'numbers', 'has_media' => true,
                ],
                'item' => [
                    'style' => 'photo-wall',
                    'photos_first' => true, 'rating_first' => true,
                    'show_title' => false,
                    'show_content' => false, 'show_footer' => false, 'show_badge' => false,
                    'show_variation' => false,
                    'media' => ['visible' => 1, 'full_width' => true, 'backdrop' => true],
                ],
            ],
            'photo-strip' => [
                'section' => ['summary' => 'cta'],
                'header'  => ['count' => true, 'filters' => false, 'sorting' => false],
                'list'    => [
                    'view_mode' => 'slider', 'columns' => 6, 'per_page' => 0,
                    'pagination' => 'none', 'has_media' => true,
                    'slider' => [
                        'arrows' => true, 'arrows_size' => 'sm', 'arrows_position' => 'outside',
                        'pagination' => false, 'infinite' => false,
                    ],
                ],
                'item' => [
                    'style' => 'photo-only', 'class' => 'fct-review-photo-only',
                    'photos_first' => true, 'show_avatar' => false,
                    'show_date' => false, 'show_title' => false, 'show_content' => false,
                    'show_footer' => false, 'show_badge' => false, 'show_variation' => false,
                    'show_meta' => false,
                    'media' => ['visible' => 1, 'full_width' => true, 'flush' => true],
                ],
            ],
            'carousel' => [
                'section' => ['summary' => 'side'],
                'header'  => ['count' => true, 'filters' => false, 'sorting' => false],
                'list'    => [
                    'view_mode' => 'slider', 'columns' => 2, 'per_page' => 0,
                    'pagination' => 'none',
                    'slider' => [
                        'arrows' => true, 'arrows_size' => 'md', 'arrows_position' => 'overlap',
                        'pagination' => true, 'pagination_type' => 'bullets', 'infinite' => false,
                    ],
                ],
                'item' => ['style' => 'card'],
            ],
            'testimonials' => [
                'section' => ['summary' => 'side'],
                'header'  => ['count' => false, 'filters' => false, 'sorting' => false],
                'list'    => [
                    'view_mode' => 'slider', 'columns' => 3, 'per_page' => 0,
                    'pagination' => 'none', 'sort' => ['by' => 'rating', 'order' => 'DESC'],
                    'slider' => [
                        'arrows' => false, 'arrows_size' => 'md', 'arrows_position' => 'bottom',
                        'pagination' => true, 'pagination_type' => 'bullets',
                        'autoplay' => true, 'autoplay_delay' => 5000, 'infinite' => true,
                    ],
                ],
                'item' => [
                    'style' => 'testimonial', 'class' => 'fct-review-testimonial',
                    // With the photographs off this is what puts the byline
                    // under the quote instead of above it: row() places the
                    // header after the words whenever the picture leads,
                    // whether or not there is a picture to lead with.
                    'photos_first' => true,
                    'show_photos' => false, 'show_date' => false,
                    'show_footer' => false, 'show_variation' => false, 'badge_last' => true,
                ],
            ],
        ];

        return $settings[$preset] ?? [];
    }

    /**
     * Every layout, and what the picker says about it.
     *
     * Only Classic is free. The rest are Pro — the picker marks them and core
     * refuses their view modes on render, so a locked layout cannot be reached
     * by editing an attribute by hand.
     */
    public static function all(): array
    {
        return [
            // ── The everyday ones ─────────────────────────────────────────
            'classic' => [
                'category' => 'list',
                'pro'      => false,
                'label'    => __('Classic', 'fluent-cart'),
                'help'     => __('The complete section: count, filter chips, sorting and numbered pages. What most stores want.', 'fluent-cart'),
                'template' => static::section([
                    'list'       => ['viewMode' => 'list', 'showSortControls' => true, 'perPage' => 10],
                    'head'       => ['count' => true, 'filter' => true, 'sorting' => true],
                    'pagination' => 'numbers',
                ]),
            ],
            'minimal' => [
                'category' => 'list',
                'pro'      => true,
                'label'    => __('Minimal List', 'fluent-cart'),
                'help'     => __('One review per row and nothing above them, for a page that already has plenty going on.', 'fluent-cart'),
                'template' => static::section([
                    'list'       => ['viewMode' => 'list', 'showSortControls' => false, 'perPage' => 5],
                    'head'       => ['count' => false],
                    'pagination' => 'numbers',
                    'fields'     => ['showDate' => false],
                ]),
            ],
            'compact' => [
                'category' => 'list',
                'pro'      => true,
                'label'    => __('Compact', 'fluent-cart'),
                'help'     => __('Stars, a couple of lines and a Read more. For somewhere a review is a mention rather than the point.', 'fluent-cart'),
                'template' => static::section([
                    'list'   => ['viewMode' => 'list', 'showSortControls' => false, 'perPage' => 3],
                    'head'   => ['count' => false],
                    'fields' => [
                        'showAvatar' => false,
                        'showDate'   => false,
                        'showTitle'  => false,
                        'showFooter' => false,
                        'showPhotos' => false,
                    ],
                ]),
            ],

            // ── Grids ─────────────────────────────────────────────────────
            'grid' => [
                'category' => 'grid',
                'pro'      => true,
                'label'    => __('Card Grid', 'fluent-cart'),
                'help'     => __('Two reviews to a row, paged as "Page 2 of 7" — one line however many pages there are.', 'fluent-cart'),
                'template' => static::section([
                    'list'       => ['viewMode' => 'grid', 'gridColumns' => 2, 'showSortControls' => true, 'perPage' => 10],
                    'head'       => ['count' => true, 'filter' => true, 'sorting' => true],
                    'pagination' => 'fraction',
                ]),
            ],
            'masonry' => [
                'category' => 'grid',
                'pro'      => true,
                'label'    => __('Masonry', 'fluent-cart'),
                'help'     => __('Three to a row, each card as tall as its own review rather than stretched to match its neighbours.', 'fluent-cart'),
                'template' => static::section([
                    'list'       => ['viewMode' => 'masonry', 'gridColumns' => 3, 'showSortControls' => true, 'perPage' => 12],
                    'head'       => ['count' => true, 'filter' => true, 'sorting' => true],
                    'pagination' => 'numbers',
                ]),
            ],

            // ── Summary first, the way the marketplaces do it ─────────────
            'summary-top' => [
                'category' => 'list',
                'pro'      => true,
                'label'    => __('Summary on Top', 'fluent-cart'),
                'help'     => __('The average and star breakdown across the top, reviews underneath. The arrangement shoppers already know.', 'fluent-cart'),
                'template' => static::section([
                    'summaryOn'  => 'top',
                    'list'       => ['viewMode' => 'list', 'showSortControls' => true, 'perPage' => 10],
                    'head'       => ['count' => true, 'filter' => true, 'sorting' => true],
                    'pagination' => 'numbers',
                ]),
            ],

            // ── Photographs first ─────────────────────────────────────────
            'photo-grid' => [
                'category' => 'photo',
                'pro'      => true,
                'label'    => __('Photo Grid', 'fluent-cart'),
                'help'     => __('Three to a row, the photograph edge to edge across the top of the card and the review under it. Reviews with photographs only.', 'fluent-cart'),
                'template' => static::section([
                    'list'       => ['viewMode' => 'grid', 'gridColumns' => 3, 'hasMedia' => true, 'showSortControls' => false, 'perPage' => 6],
                    'head'       => ['count' => true, 'filter' => true],
                    'pagination' => 'fraction',
                    'fields'     => [
                        'photosFirst' => true,
                        'showDate'    => false,
                        'cardClass'   => 'fct-review-photo-grid',
                        'photos'      => ['visibleCount' => 1, 'mediaFullWidth' => true, 'mediaHeight' => 0, 'mediaFlush' => true],
                    ],
                ]),
            ],
            'photo-wall' => [
                'category' => 'photo',
                'pro'      => true,
                'label'    => __('Photo Wall', 'fluent-cart'),
                'help'     => __('The photograph fills its card, with the stars, the name and the date over the foot of it. Reviews with photographs only.', 'fluent-cart'),
                'template' => static::section([
                    'list'       => ['viewMode' => 'grid', 'gridColumns' => 4, 'hasMedia' => true, 'showSortControls' => false, 'perPage' => 8],
                    'head'       => ['count' => true],
                    'pagination' => 'numbers',
                    'fields'     => [
                        'photosFirst'   => true,
                        'ratingFirst'   => true,
                        'showTitle'     => false,
                        'showContent'   => false,
                        'showDate'      => true,
                        'showFooter'    => false,
                        'showBadge'     => false,
                        'showVariation' => false,
                        'photos'        => [
                            'visibleCount'   => 1,
                            'mediaFullWidth' => true,
                            'mediaHeight'    => 0,
                            'mediaBackdrop'  => true,
                        ],
                    ],
                ]),
            ],
            'photo-strip' => [
                'category' => 'photo',
                'pro'      => true,
                'label'    => __('Photo Strip', 'fluent-cart'),
                'help'     => __('A band of customer photographs and nothing else, each opening in the lightbox. Meant to sit above a review list, not replace it.', 'fluent-cart'),
                'template' => static::section([
                    'list' => [
                        'viewMode'         => 'slider',
                        'gridColumns'      => 6,
                        'hasMedia'         => true,
                        'showSortControls' => false,
                        'perPage'          => 0,
                        'sliderSettings'   => [
                            'arrows' => 'yes', 'arrowsSize' => 'sm', 'arrowsPosition' => 'outside',
                            'pagination' => 'no', 'paginationType' => 'bullets',
                            'autoplay' => 'no', 'autoplayDelay' => 3000, 'infinite' => 'no',
                        ],
                    ],
                    'summaryOn' => 'cta',
                    'head'      => ['count' => true],
                    'fields'    => [
                        'photosFirst' => true,
                        'showAvatar'  => false,
                        'showDate'    => false,
                        'showTitle'   => false,
                        'showContent' => false,
                        'showFooter'  => false,
                        'showMeta'    => false,
                        'photos'      => ['visibleCount' => 1, 'mediaFullWidth' => true, 'mediaHeight' => 0, 'mediaFlush' => true],
                        'cardClass'   => 'fct-review-photo-only',
                    ],
                ]),
            ],

            // ── Sliders ───────────────────────────────────────────────────
            'carousel' => [
                'category' => 'carousel',
                'pro'      => true,
                'label'    => __('Carousel', 'fluent-cart'),
                'help'     => __('Two at a time, arrows on the reviews and dots underneath. No pager — the slider is its own.', 'fluent-cart'),
                'template' => static::section([
                    'list' => [
                        'viewMode'         => 'slider',
                        'gridColumns'      => 2,
                        'showSortControls' => false,
                        'perPage'          => 0,
                        'sliderSettings'   => [
                            'arrows' => 'yes', 'arrowsSize' => 'md', 'arrowsPosition' => 'overlap',
                            'pagination' => 'yes', 'paginationType' => 'bullets',
                            'autoplay' => 'no', 'autoplayDelay' => 3000, 'infinite' => 'no',
                        ],
                    ],
                    'head' => ['count' => true],
                ]),
            ],
            'testimonials' => [
                'category' => 'carousel',
                'pro'      => true,
                'label'    => __('Testimonials', 'fluent-cart'),
                'help'     => __('The quote first, centred under a quotation mark, signed off underneath with the face, the name and the stars. Three at a time, moving on their own, highest rated first.', 'fluent-cart'),
                'template' => static::section([
                    'list' => [
                        'viewMode'         => 'slider',
                        'gridColumns'      => 3,
                        'hasMedia'         => false,
                        'showSortControls' => false,
                        'perPage'          => 0,
                        'defaultSortBy'    => 'rating',
                        'defaultSortOrder' => 'DESC',
                        'sliderSettings'   => [
                            'arrows' => 'no', 'arrowsSize' => 'md', 'arrowsPosition' => 'bottom',
                            'pagination' => 'yes', 'paginationType' => 'bullets',
                            'autoplay' => 'yes', 'autoplayDelay' => 5000, 'infinite' => 'yes',
                        ],
                    ],
                    'head'   => ['count' => false],
                    'fields' => [
                        'photosFirst'   => true,
                        'showPhotos'    => false,
                        'showTitle'     => true,
                        'showAvatar'    => true,
                        'showDate'      => false,
                        'showFooter'    => false,
                        'showVariation' => false,
                        'badgeLast'     => true,
                        'cardClass'     => 'fct-review-testimonial',
                    ],
                ]),
            ],
        ];
    }
}
