const {InspectorControls} = wp.blockEditor;
const {SelectControl} = wp.components;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import ReviewProductPanel from "@/BlockEditor/ProductReviews/Components/ReviewProductPanel";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const InspectorSettings = ({attributes, setAttributes, selectedProduct, setSelectedProduct, insideParent}) => {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card fct-inspector-control-wrap--review-form">
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

                        <EditorPanel title={blocktranslate('Form Settings')}>
                            <EditorPanelRow className="flex-col">
                                <SelectControl
                                    label={blocktranslate('Field Layout')}
                                    help={blocktranslate('Steps walks the reviewer through rating, details and photos. Inline shows every field at once.')}
                                    value={attributes.layout}
                                    options={[
                                        {label: blocktranslate('Inline (all fields at once)'), value: 'inline'},
                                        {label: blocktranslate('Steps (one at a time)'), value: 'steps'},
                                    ]}
                                    onChange={(val) => setAttributes({layout: val})}
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
