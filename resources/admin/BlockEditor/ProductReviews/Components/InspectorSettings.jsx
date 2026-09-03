const {InspectorControls} = wp.blockEditor;

import ReviewProductPanel from "@/BlockEditor/ProductReviews/Components/ReviewProductPanel";

// The container only owns the product query now — display options, star
// color and per-section toggles live on the child blocks (Rating Summary,
// Write a Review, Review List).
const InspectorSettings = ({attributes, setAttributes, selectedProduct, setSelectedProduct, insideParent}) => {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card fct-inspector-control-wrap--product-reviews">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">

                        {/* Inside Product Info or a product loop that container owns the product */}
                        {!insideParent && (
                            <ReviewProductPanel
                                attributes={attributes}
                                setAttributes={setAttributes}
                                selectedProduct={selectedProduct}
                                setSelectedProduct={setSelectedProduct}
                            />
                        )}

                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
