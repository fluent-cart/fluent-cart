const {InspectorControls} = wp.blockEditor;
const {TextControl, SelectControl} = wp.components;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import ReviewProductPanel from "@/BlockEditor/ProductReviews/Components/ReviewProductPanel";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const InspectorSettings = ({attributes, setAttributes, selectedProduct, setSelectedProduct, insideParent}) => {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card fct-inspector-control-wrap--write-a-review-button">
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

                        {/* Two independent choices: where the form appears
                            when the button is clicked, and how it paces the
                            fields once it is open. */}
                        <EditorPanel title={blocktranslate('Button Settings')}>
                            <EditorPanelRow className="flex-col">
                                <SelectControl
                                    label={blocktranslate('Open In')}
                                    help={blocktranslate('Where the form appears when the button is clicked.')}
                                    value={attributes.container}
                                    options={[
                                        {label: blocktranslate('Drawer (slides in from the side)'), value: 'drawer'},
                                        {label: blocktranslate('Modal (centered on the screen)'), value: 'modal'},
                                    ]}
                                    onChange={(val) => setAttributes({container: val})}
                                />

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

                        {/* Per-state button labels — the button reads
                            Write / Edit / Log in depending on the visitor;
                            empty fields keep the translated defaults. */}
                        <EditorPanel title={blocktranslate('Button Text')}>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('New Review')}
                                    help={blocktranslate('Shown when the visitor has not reviewed this product yet')}
                                    value={attributes.addReviewButtonText}
                                    placeholder={blocktranslate('Write a Review')}
                                    onChange={(val) => setAttributes({addReviewButtonText: val})}
                                />
                                <TextControl
                                    label={blocktranslate('Edit Review')}
                                    help={blocktranslate('Shown when the visitor already has a review for this product')}
                                    value={attributes.editReviewButtonText}
                                    placeholder={blocktranslate('Edit your review')}
                                    onChange={(val) => setAttributes({editReviewButtonText: val})}
                                />
                                <TextControl
                                    label={blocktranslate('Logged Out')}
                                    help={blocktranslate('Shown when a visitor must log in before reviewing')}
                                    value={attributes.loginReviewButtonText}
                                    placeholder={blocktranslate('Log in to Review')}
                                    onChange={(val) => setAttributes({loginReviewButtonText: val})}
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
