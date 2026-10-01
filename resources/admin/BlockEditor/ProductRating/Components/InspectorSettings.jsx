const {InspectorControls} = wp.blockEditor;
const {__experimentalNumberControl: NumberControl} = wp.components;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import ReviewProductPanel from "@/BlockEditor/ProductReviews/Components/ReviewProductPanel";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const InspectorSettings = ({attributes, setAttributes, selectedProduct, setSelectedProduct, insideParent}) => {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card fct-inspector-control-wrap--product-rating">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">

                        {/* Inside a product-owning container that container owns the product */}
                        {!insideParent && (
                            <ReviewProductPanel
                                attributes={attributes}
                                setAttributes={setAttributes}
                                selectedProduct={selectedProduct}
                                setSelectedProduct={setSelectedProduct}
                            />
                        )}

                        <EditorPanel title={blocktranslate('Visibility')}>
                            <EditorPanelRow className="flex-col">
                                <NumberControl
                                    label={blocktranslate('Minimum Reviews')}
                                    help={blocktranslate('Hide the rating until the product has at least this many reviews. 0 always shows it, including five empty stars on a product with none.')}
                                    value={attributes.minReviewCount}
                                    min={0}
                                    step={1}
                                    onChange={(val) => {
                                        // The control hands back a string, and an
                                        // emptied field hands back nothing at all.
                                        const n = parseInt(val, 10);
                                        setAttributes({minReviewCount: isNaN(n) || n < 0 ? 0 : n});
                                    }}
                                />
                                <NumberControl
                                    label={blocktranslate('Minimum Average Rating')}
                                    help={blocktranslate('Hide the rating unless the product averages at least this many stars. 0 shows every rating. Half stars are allowed.')}
                                    value={attributes.minAverageRating}
                                    min={0}
                                    max={5}
                                    step={0.5}
                                    onChange={(val) => {
                                        const n = parseFloat(val);
                                        if (isNaN(n) || n < 0) {
                                            setAttributes({minAverageRating: 0});
                                            return;
                                        }
                                        setAttributes({minAverageRating: Math.min(5, n)});
                                    }}
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
