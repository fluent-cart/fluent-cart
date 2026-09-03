const {InspectorControls} = wp.blockEditor;
const {TextControl} = wp.components;

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

                        {/* Per-state button labels — the button reads
                            Write / Edit / Log in depending on the visitor;
                            empty fields keep the translated defaults */}
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
