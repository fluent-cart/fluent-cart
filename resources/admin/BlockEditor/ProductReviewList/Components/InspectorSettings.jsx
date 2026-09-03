const {InspectorControls} = wp.blockEditor;
const {ToggleControl, SelectControl, RangeControl, ColorPicker} = wp.components;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import ReviewProductPanel from "@/BlockEditor/ProductReviews/Components/ReviewProductPanel";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const InspectorSettings = ({attributes, setAttributes, selectedProduct, setSelectedProduct, insideParent}) => {
    const sortValue = `${attributes.defaultSortBy}-${attributes.defaultSortOrder}`;

    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card fct-inspector-control-wrap--review-list">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">

                        {/* Inside the Product Reviews container the parent owns the product */}
                        {!insideParent && (
                            <ReviewProductPanel
                                attributes={attributes}
                                setAttributes={setAttributes}
                                selectedProduct={selectedProduct}
                                setSelectedProduct={setSelectedProduct}
                            />
                        )}

                        <EditorPanel title={blocktranslate('Content')}>
                            <EditorPanelRow className="flex-col">
                                <ToggleControl
                                    label={blocktranslate('Sort Controls')}
                                    help={blocktranslate('Allow sorting and rating filter')}
                                    checked={attributes.showSortControls}
                                    onChange={(val) => setAttributes({showSortControls: val})}
                                />
                                <ToggleControl
                                    label={blocktranslate('Verified Purchase Badge')}
                                    checked={attributes.showVerifiedBadge}
                                    onChange={(val) => setAttributes({showVerifiedBadge: val})}
                                />
                                <ToggleControl
                                    label={blocktranslate('Reviewer Name')}
                                    checked={attributes.showReviewerName}
                                    onChange={(val) => setAttributes({showReviewerName: val})}
                                />
                                <ToggleControl
                                    label={blocktranslate('Review Date')}
                                    checked={attributes.showReviewDate}
                                    onChange={(val) => setAttributes({showReviewDate: val})}
                                />
                                <ToggleControl
                                    label={blocktranslate('View Reply')}
                                    help={blocktranslate('Show the reply button on review items')}
                                    checked={attributes.showViewReply}
                                    onChange={(val) => setAttributes({showViewReply: val})}
                                />
                            </EditorPanelRow>
                        </EditorPanel>

                        <EditorPanel title={blocktranslate('Display')}>
                            <EditorPanelRow className="flex-col">
                                <SelectControl
                                    label={blocktranslate('Default Sort')}
                                    value={sortValue}
                                    options={[
                                        {label: blocktranslate('Newest'), value: 'created_at-DESC'},
                                        {label: blocktranslate('Oldest'), value: 'created_at-ASC'},
                                        {label: blocktranslate('Highest Rating'), value: 'rating-DESC'},
                                        {label: blocktranslate('Lowest Rating'), value: 'rating-ASC'},
                                    ]}
                                    onChange={(val) => {
                                        const [sortBy, sortOrder] = val.split('-');
                                        setAttributes({defaultSortBy: sortBy, defaultSortOrder: sortOrder});
                                    }}
                                />
                                <RangeControl
                                    label={blocktranslate('Reviews Per Page')}
                                    help={attributes.perPage === 0 ? blocktranslate('Using global setting') : ''}
                                    value={attributes.perPage}
                                    onChange={(val) => setAttributes({perPage: val})}
                                    min={0}
                                    max={50}
                                    step={1}
                                />
                            </EditorPanelRow>
                        </EditorPanel>

                        <EditorPanel title={blocktranslate('Star Color')}>
                            <EditorPanelRow>
                                <ColorPicker
                                    color={attributes.starColor}
                                    onChange={(val) => setAttributes({starColor: val})}
                                    enableAlpha={false}
                                />
                            </EditorPanelRow>
                        </EditorPanel>

                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
