import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps, InspectorControls } = wp.blockEditor;
const { PanelBody, SelectControl } = wp.components;

/* The orders a list may open in, from the same filterable list the rendered
   select is built from — so a store that adds a sort gets it in both. */
const SORT_OPTIONS = window['fluent_cart_review_inner_blocks']?.sortOptions || {
    'created_at-DESC': 'Newest',
};

const FALLBACK_SORT = 'created_at-DESC';

/**
 * The sort dropdown.
 *
 * Drawn, not live: the real select is what the storefront script reads the
 * current sort from, and a working one here would sort a preview that has no
 * rows to sort. Which order it opens on is this block's own setting, and the
 * list lifts it to run the first query.
 */
const ReviewListSortingBlock = {
    attributes: {
        defaultSort: {
            type: 'string',
            default: FALLBACK_SORT,
        },
    },
    edit: ({ attributes, setAttributes }) => {
        const { defaultSort } = attributes;
        const blockProps = useBlockProps({ className: 'fct-reviews-sort' });
        const label = SORT_OPTIONS[defaultSort] || SORT_OPTIONS[FALLBACK_SORT];

        return (
            <>
                <InspectorControls>
                    <PanelBody title={blocktranslate('Sorting')} initialOpen={true}>
                        <SelectControl
                            label={blocktranslate('Default Sort')}
                            value={defaultSort}
                            options={Object.keys(SORT_OPTIONS).map((value) => ({
                                label: SORT_OPTIONS[value],
                                value,
                            }))}
                            onChange={(val) => setAttributes({ defaultSort: val })}
                        />
                    </PanelBody>
                </InspectorControls>

                <div {...blockProps}>
                    <span className="fct-reviews-sort-preview">{label}</span>
                </div>
            </>
        );
    },
    save: () => null,
    supports: {
        html: false,
        spacing: { margin: true, padding: true },
    },
    usesContext: [
        'fluent-cart/review_list_header',
        'fluent-cart/review_list_rows_only',
    ],
};

export default ReviewListSortingBlock;
