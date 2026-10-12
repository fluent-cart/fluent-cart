import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import InspectorSettings from "@/BlockEditor/ProductReviewList/Components/InspectorSettings";
import ListPreview from "@/BlockEditor/ProductReviews/Components/ListPreview";
import {
    isMultiProductQuery,
    useInsideProductReviews,
    useMultiProductReviewCount,
    useReviewBlockProduct,
    useReviewContainerQuery,
} from "@/BlockEditor/ProductReviews/Components/reviewBlockShared";
import {ReviewList} from "@/BlockEditor/Icons";

const {useBlockProps, InnerBlocks} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect} = wp.element;
const {useSelect} = wp.data;

/**
 * The header blocks, which the canvas preview has to know about: it draws a
 * count line and a chips/sort row of its own, and with header blocks placed
 * that is a second copy of controls the blocks already show.
 *
 * Mirrors InnerBlocks::headerBlockSlugs() on the server, which decides the
 * same split for the rendered page.
 */
const REVIEW_HEADER_BLOCKS = [
    'fluent-cart/review-list-count',
    'fluent-cart/review-list-filter',
    'fluent-cart/review-list-sorting',
];

// What PHP localized for this block: its slug, name, title and description.
// registerBlockType below is a module-scope call, so losing this is not a
// degraded block — the file throws on load and the Review List never
// registers at all.
const blockEditorData = window.fluent_cart_product_review_list_data;

/**
 * Whether the laid-out view modes are available. Grid, slider and masonry are
 * Pro; the canvas has to agree with the page, which resolves the same question
 * in ProductReviewService::resolveViewMode().
 */
const reviewListIsPro = () => !!(window.fluent_cart_product_review_list_data || {}).is_pro;

/**
 * The row the block starts with — the review row as it has always looked,
 * expressed in blocks: avatar beside a meta column whose first line carries
 * the name, stars, badge and variation, with the date under it, then title,
 * content, photos and the reply trigger.
 *
 * Inserted whole, so adding Review List gives a working row rather than an
 * empty shell to assemble. The groups carry the storefront's own class names,
 * so the composed row wears the styles the rendered one already has.
 */
const REVIEW_ROW_TEMPLATE = [
    // The list header, drawn once above the rows. The list block recognises
    // these three by name and pulls them out of the per-review loop — left in
    // it they would be repeated once per review, three counts and three sort
    // dropdowns in a three-review list.
    //
    // The inner group carries .fct-reviews-controls, the storefront's own
    // class: it is what puts the chips and the dropdown on their own full
    // width line under the count, which is where the rendered header has
    // always had them.
    ['core/group', {
        className: 'fct-reviews-header-blocks',
        layout: {type: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', verticalAlignment: 'center'},
        style: {spacing: {blockGap: '12px'}},
        metadata: {name: 'Review List Header'},
    }, [
        ['fluent-cart/review-list-count'],
        ['core/group', {
            className: 'fct-reviews-controls',
            layout: {type: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', verticalAlignment: 'center'},
            style: {spacing: {blockGap: '10px'}},
            metadata: {name: 'Review Controls'},
        }, [
            ['fluent-cart/review-list-filter'],
            ['fluent-cart/review-list-sorting'],
        ]],
    ]],
    // Three wrappers, which is the floor for this shape: a row holding the
    // avatar beside a column, and inside that column a row for the name line
    // with the date beneath it. Each is named, so List View reads "Review
    // Item Header" rather than a stack of anonymous Rows and Groups —
    // Review Item Header (Review Author, Review Meta), then the content
    // blocks, then Review Item Footer (Review Reply, Review Votes).
    //
    // Vertical flex for the column, never 'constrained': that layout centres
    // its children, which is what pushed the date out to the middle of the row
    // instead of sitting under the name.
    //
    // Each group carries its own blockGap. Without it a group falls back to the
    // theme's --wp--style--block-gap, commonly 24px, which opened a gulf
    // between the avatar and the name and again between the name and the date.
    // These are the rendered row's own numbers, read from reviews.scss:
    // header-left gap 10px centred, name line 4px/8px. Guessing them is how
    // the avatar ended up sitting low with a gulf beside it.
    // The review itself. It exists so the canvas can draw the row card around
    // the review and not around the header above it; on the page it is a plain
    // div inside the .fct-review-item the list already wraps each row in.
    //
    // blockGap 0 on purpose: the fields carry the rendered row's own margins,
    // and a group with no gap of its own falls back to the theme's, which is
    // commonly 24px and would space the title, content and photos apart.
    ['fluent-cart/review-item', {
        style: {spacing: {blockGap: '0px'}},
        metadata: {name: 'Review Item'},
    }, [
    ['core/group', {
        className: 'fct-review-item-header-left',
        layout: {type: 'flex', flexWrap: 'nowrap', verticalAlignment: 'center'},
        // The 10px under the header, which the rendered row gets from
        // .fct-review-item-header. That class is not reused here: it also
        // carries justify-content:space-between, which would push the avatar
        // away from the name it belongs beside. The margin is the part that
        // was wanted, so only the margin is taken.
        style: {spacing: {blockGap: '10px', margin: {bottom: '10px'}}},
        metadata: {name: 'Review Item Header'},
    }, [
        ['fluent-cart/review-item-avatar', {metadata: {name: 'Review Author'}}],
        ['core/group', {
            className: 'fct-review-item-meta-text',
            layout: {type: 'flex', orientation: 'vertical'},
            style: {spacing: {blockGap: '2px'}},
            metadata: {name: 'Review Meta'},
        }, [
            ['core/group', {
                className: 'fct-review-item-name-line',
                layout: {type: 'flex', flexWrap: 'wrap'},
                style: {spacing: {blockGap: {top: '4px', left: '8px'}}},
                metadata: {name: 'Review Name Line'},
            }, [
                ['fluent-cart/review-item-author-name'],
                ['fluent-cart/review-item-rating'],
                ['fluent-cart/review-item-verified-badge'],
                ['fluent-cart/review-item-variation-title'],
            ]],
            ['fluent-cart/review-item-date'],
        ]],
    ]],
    ['fluent-cart/review-item-title'],
    ['fluent-cart/review-item-content'],
    ['fluent-cart/review-item-photos'],
    // Reply left, votes right, on one line: the footer group is what PRO's
    // script looks for when it appends the Helpful buttons, and its
    // space-between is what puts them on the right.
    ['core/group', {
        className: 'fct-review-footer',
        layout: {type: 'flex', flexWrap: 'wrap', justifyContent: 'space-between'},
        style: {spacing: {blockGap: '10px'}},
        metadata: {name: 'Review Item Footer'},
    }, [
        ['fluent-cart/review-item-reply'],
        ['fluent-cart/review-item-votes'],
    ]],
    ]],
    // Below the rows, and rendered once — the list pulls it out of the
    // per-review loop the same way it does the header.
    ['fluent-cart/review-list-pagination'],
];

/**
 * Everything the list accepts as a child: the header blocks, the per-review
 * field blocks, the pager, and the two core containers the template arranges
 * them with. Not just the fields, despite where it started.
 */
/**
 * What the list itself takes.
 *
 * The field blocks are not here: they read the review the Review Item is
 * drawing, so beside one they have nothing to read and render nothing on the
 * page while the canvas still shows them against the stand-in. They are
 * offered inside the Review Item instead, which is where they work.
 */
const REVIEW_ALLOWED_BLOCKS = [
    'fluent-cart/review-item',
    'fluent-cart/review-list-count',
    'fluent-cart/review-list-filter',
    'fluent-cart/review-list-sorting',
    'fluent-cart/review-list-pagination',
    'core/group',
    'core/columns',
];

/**
 * The same list once a row is already there.
 *
 * One list draws one set of rows: the storefront script refills a single rows
 * element and sends back a single id, so a second Review Item is drawn by
 * nobody and discarded in silence. The inserter stops offering one rather
 * than letting an editor lay out something that will never appear.
 */
const REVIEW_ALLOWED_BLOCKS_WITH_ROW = REVIEW_ALLOWED_BLOCKS.filter(
    (name) => name !== 'fluent-cart/review-item'
);

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    // The same map the server declares in provideContext(): in which name the
    // data will be received => which attr. Both halves are needed — this one
    // is what hands the canvas preview its context, the server's is what hands
    // the rendered page its own.
    providesContext: {
        'fluent-cart/review_view_mode': 'viewMode',
        'fluent-cart/review_grid_columns': 'gridColumns',
        'fluent-cart/review_slider_settings': 'sliderSettings',
    },
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: ReviewList,
    },
    category: "fluent-cart",
    attributes: {
        query_type: {
            type: 'string',
            default: 'default',
        },
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        product_ids: {
            type: 'array',
            default: [],
        },
        viewMode: {
            type: 'string',
            default: 'list',
        },
        gridColumns: {
            type: 'number',
            default: 2,
        },
        hasMedia: {
            type: 'boolean',
            default: false,
        },
        sliderSettings: {
            type: 'object',
            default: {
                autoplay: 'no',
                autoplayDelay: 3000,
                arrows: 'yes',
                arrowsSize: 'md',
                arrowsPosition: 'overlap',
                pagination: 'no',
                paginationType: 'bullets',
                infinite: 'no',
            },
        },
        showSortControls: {
            type: 'boolean',
            default: true,
        },
        defaultSortBy: {
            type: 'string',
            default: 'created_at',
        },
        defaultSortOrder: {
            type: 'string',
            default: 'DESC',
        },
        perPage: {
            type: 'number',
            default: 0,
        },
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({
            className: 'fct-review-list-block-editor',
        });

        const insideParent = useInsideProductReviews(clientId);
        // Inside Product Reviews the container's query wins, as it does on the page.
        const containerQuery = useReviewContainerQuery(clientId);
        const effectiveQueryType = containerQuery
            ? containerQuery.queryType
            : (insideParent ? 'default' : attributes.query_type);
        const effectiveProductIds = containerQuery ? containerQuery.productIds : (attributes.product_ids || []);
        const isMultiProduct = isMultiProductQuery(effectiveQueryType);

        const {selectedProduct, setSelectedProduct, reviewSummary, effectiveProductId} =
            useReviewBlockProduct(attributes, insideParent, !isMultiProduct);
        const multiProductCount = useMultiProductReviewCount(
            isMultiProduct ? effectiveQueryType : '',
            effectiveProductIds
        );

        // Inside the container the parent owns the product: pin the child to
        // the default query so resolveProduct() follows the parent's setup
        // and a stale custom pick can never win.
        useEffect(() => {
            if (insideParent && attributes.query_type !== 'default') {
                setAttributes({query_type: 'default'});
            }
        }, [insideParent, attributes.query_type]);

        // Whether this list already has the one row it can draw.
        const hasReviewItem = useSelect((select) => {
            const {getClientIdsOfDescendants, getBlockName} = select('core/block-editor');

            return getClientIdsOfDescendants(clientId)
                .some((id) => getBlockName(id) === 'fluent-cart/review-item');
        }, [clientId]);

        // Any depth, because the template nests them in a group — asking only
        // about direct children would miss every header the block inserts.
        const hasHeaderBlocks = useSelect((select) => {
            const {getClientIdsOfDescendants, getBlockName} = select('core/block-editor');

            return getClientIdsOfDescendants(clientId)
                .some((id) => REVIEW_HEADER_BLOCKS.includes(getBlockName(id)));
        }, [clientId]);

        const otherInfo = selectedProduct?.detail?.other_info || {};
        const reviewCount = isMultiProduct
            ? multiProductCount.total
            : (parseInt(reviewSummary?.total ?? otherInfo.review_count, 10) || 0);
        const hasProductData = isMultiProduct
            ? multiProductCount.status === 'ready'
            : (!!effectiveProductId && !!selectedProduct?.ID);

        let multiProductCaption = '';
        if (isMultiProduct) {
            if (effectiveQueryType === 'multiple' && !effectiveProductIds.length) {
                multiProductCaption = blocktranslate('Select products to show their reviews. Nothing is shown until you do.');
            } else if (multiProductCount.status === 'unavailable') {
                multiProductCaption = blocktranslate('Reviews are switched off in the store settings, so nothing is shown.');
            } else if (multiProductCount.status === 'error') {
                multiProductCaption = blocktranslate('Could not load the review count.');
            } else if (effectiveQueryType === 'all') {
                multiProductCaption = blocktranslate('Showing reviews from all products');
            } else {
                multiProductCaption = blocktranslate('Showing reviews from the selected products');
            }
        }

        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    insideParent={insideParent}
                />

                <div className="fct-reviews-block-skeleton">
                    <div className="fct-reviews-block-skeleton-columns is-single">
                        {/* ListPreview is shared with the Product Reviews
                            container, which still has a sort-controls toggle.
                            This block does not: the Filter and Sorting blocks
                            are the switch now, so the preview always offers
                            them.

                            The blocks go where the list's own header and items
                            would: with header blocks placed they draw the count,
                            chips and sort themselves, so the preview stands down
                            and only supplies what is still runtime data. The row
                            card is drawn around the Review Item group rather than
                            around all the children, which would box the header
                            inside a review. */}
                        <ListPreview
                            viewMode={reviewListIsPro() ? (attributes.viewMode || 'list') : 'list'}
                            gridColumns={attributes.gridColumns || 2}
                            sliderSettings={attributes.sliderSettings || {}}
                            hasProductData={hasProductData}
                            reviewCount={reviewCount}
                            /* Only a list nobody has composed draws the
                               preview header. Once there is a Review Item the
                               blocks are the answer, and a header absent from
                               them is one an editor removed — the same rule
                               the renderer follows on the page. */
                            showHeader={!hasHeaderBlocks && !hasReviewItem}
                            showSortControls={true}
                            showReviewerName={true}
                            showReviewDate={true}
                            rows={(
                                <InnerBlocks
                                    allowedBlocks={hasReviewItem ? REVIEW_ALLOWED_BLOCKS_WITH_ROW : REVIEW_ALLOWED_BLOCKS}
                                    template={REVIEW_ROW_TEMPLATE}
                                    templateInsertUpdatesSelection={false}
                                    renderAppender={InnerBlocks.ButtonBlockAppender}
                                />
                            )}
                        />
                    </div>
                    {isMultiProduct ? (
                        <div className="fct-reviews-block-skeleton-caption" role="status">
                            {multiProductCaption}
                        </div>
                    ) : (!hasProductData && !insideParent && (
                        <div className="fct-reviews-block-skeleton-caption">
                            {blocktranslate('Reviews will be displayed for the current product')}
                        </div>
                    ))}
                </div>
            </div>
        );
    },
    // The row arrangement is persisted; the server re-renders it once per
    // review. A list with no inner blocks saves nothing and is unchanged.
    save: function () {
        return <InnerBlocks.Content/>;
    },
    supports: {
        html: false,
        align: true,
        typography: {
            fontSize: true,
            lineHeight: true,
            __experimentalFontFamily: true,
            __experimentalFontWeight: true,
            __experimentalDefaultControls: {
                fontSize: true,
            },
        },
        color: {
            text: true,
            background: true,
        },
        spacing: {
            margin: true,
            padding: true,
        },
        __experimentalBorder: {
            color: true,
            radius: true,
            style: true,
            width: true,
        },
    },
});
