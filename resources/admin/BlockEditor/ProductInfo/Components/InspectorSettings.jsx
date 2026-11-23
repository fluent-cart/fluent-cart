import ProductSourceControl from "@/BlockEditor/Components/ProductPicker/ProductSourceControl";

const { InspectorControls } = wp.blockEditor;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";


const InspectorSettings = ({ attributes, setAttributes, selectedProduct, setSelectedProduct }) => {

    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Product')}>
                            <EditorPanelRow className="flex-col">
                                <ProductSourceControl
                                    queryType={attributes.query_type}
                                    onQueryTypeChange={(value) => setAttributes({query_type: value})}
                                    productId={attributes.product_id ? String(attributes.product_id) : ''}
                                    selectedProduct={selectedProduct}
                                    setSelectedProduct={setSelectedProduct}
                                    onSelectionConfirmed={(product) => {
                                        // A product that failed to load has no id; confirming it must not clear the saved one.
                                        if (product && !product.ID) {
                                            return;
                                        }
                                        setAttributes({product_id: product ? String(product.ID) : ''});
                                    }}
                                    onRemove={() => {
                                        setAttributes({product_id: ''});
                                        setSelectedProduct(null);
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
