const {InspectorControls} = wp.blockEditor;

import ReviewProductPanel from "@/BlockEditor/ProductReviews/Components/ReviewProductPanel";

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

                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
