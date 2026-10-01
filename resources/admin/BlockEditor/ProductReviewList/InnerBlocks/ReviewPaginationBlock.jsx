import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import { ReviewPagerNext, ReviewPagerPrevious } from "@/BlockEditor/Icons";
import { resolveViewMode } from "./viewMode";

const { useBlockProps, InspectorControls, BlockControls, JustifyContentControl } = wp.blockEditor;
const { PanelBody, RangeControl, SelectControl, Button } = wp.components;

const ALIGNMENT_OPTIONS = ['left', 'center', 'right'];

/* The store's own Reviews Per Page. 0 on the block means "ask the store", and
   the server resolves it to this — so the control shows this number rather
   than a 0 that tells an editor nothing about how long the list will be. */
const STORE_PER_PAGE = parseInt(window['fluent_cart_review_inner_blocks']?.storePerPage, 10) || 10;

/* The modifier ReviewListRenderer puts on each alternative pager, so the
   preview wears the same class the page does and needs no styles of its own. */
const PAGER_PREVIEW_MODIFIER = {
    fraction: ' is-fraction',
    bullets: ' is-bullets',
};

/**
 * The pager below the rows.
 *
 * Drawn, not live: the real one is re-rendered by the storefront script on
 * every page change, and a working pager here would page through a preview
 * with no rows in it. How many pages there are is runtime data, so this shows
 * a sample two.
 *
 * Reviews Per Page is edited here because this is where an editor looks for
 * it, but it is written to the Review List above: it decides the query, not
 * the pager, and the container the script reads it from is the list's.
 */
const ReviewPaginationBlock = {
    attributes: {
        justify: {
            type: 'string',
            default: '',
        },
        paginationType: {
            type: 'string',
            default: 'numbers',
        },
        perPage: {
            type: 'number',
            default: 0,
        },
    },
    edit: ({ attributes, setAttributes, context }) => {
        const { justify, paginationType, perPage } = attributes;
        // Resolved, not read raw. A list saved as slider on a site whose Pro
        // has lapsed renders as a plain list, and a plain list needs its pager.
        const isSlider = resolveViewMode(context?.['fluent-cart/review_view_mode']) === 'slider';

        const blockProps = useBlockProps({
            className: `fct-reviews-pagination${ALIGNMENT_OPTIONS.includes(justify) ? ` is-justified-${justify}` : ''}`,
        });

        return (
            <>
                {!isSlider && (
                    <BlockControls group="block">
                        <JustifyContentControl
                            allowedControls={ALIGNMENT_OPTIONS}
                            value={justify || undefined}
                            onChange={(val) => setAttributes({ justify: val || '' })}
                        />
                    </BlockControls>
                )}

                <InspectorControls>
                    <PanelBody title={blocktranslate('Pagination')} initialOpen={true}>
                        <SelectControl
                            label={blocktranslate('Pagination Type')}
                            value={paginationType}
                            options={[
                                {label: blocktranslate('Numbers'), value: 'numbers'},
                                {label: blocktranslate('Fraction'), value: 'fraction'},
                                {label: blocktranslate('Bullets'), value: 'bullets'},
                            ]}
                            onChange={(val) => setAttributes({paginationType: val})}
                        />
                        <RangeControl
                            label={blocktranslate('Reviews Per Page')}
                            help={perPage === 0 ? blocktranslate('Using the store setting') : ''}
                            value={perPage || STORE_PER_PAGE}
                            onChange={(val) => setAttributes({ perPage: val })}
                            min={1}
                            max={50}
                            step={1}
                        />
                        {perPage > 0 && (
                            <Button
                                variant="tertiary"
                                onClick={() => setAttributes({ perPage: 0 })}
                            >
                                {blocktranslate('Use the store setting')}
                            </Button>
                        )}
                    </PanelBody>
                </InspectorControls>

                {!isSlider && (
                    <div {...blockProps}>
                        <div className={`fct-reviews-pagination-inner${PAGER_PREVIEW_MODIFIER[paginationType] || ''}`}>
                            <span className="fct-reviews-page-btn fct-reviews-page-nav is-disabled"><ReviewPagerPrevious /></span>
                            {/* One string, not a sentence assembled from parts:
                                word order is not the same in every language. */}
                            {paginationType === 'fraction' && (
                                <span className="fct-reviews-page-fraction">
                                    {blocktranslate('Page 1 of 2')}
                                </span>
                            )}
                            {paginationType === 'bullets' && (
                                <>
                                    <span className="fct-reviews-page-bullet active" />
                                    <span className="fct-reviews-page-bullet" />
                                </>
                            )}
                            {paginationType !== 'fraction' && paginationType !== 'bullets' && (
                                <>
                                    <span className="fct-reviews-page-btn active">1</span>
                                    <span className="fct-reviews-page-btn">2</span>
                                </>
                            )}
                            <span className="fct-reviews-page-btn fct-reviews-page-nav"><ReviewPagerNext /></span>
                        </div>
                    </div>
                )}
            </>
        );
    },
    save: () => null,
    supports: {
        html: false,
        spacing: { margin: true, padding: true },
    },
    usesContext: [
        'fluent-cart/review_view_mode',
        'fluent-cart/review_list_pagination',
        'fluent-cart/review_list_rows_only',
    ],
};

export default ReviewPaginationBlock;
