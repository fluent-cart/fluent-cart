import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal";

const { InspectorControls } = wp.blockEditor;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const InspectorSettings = ({ attributes, setAttributes, selectedProduct, setSelectedProduct, isInsideProductInfo }) => {

    if (isInsideProductInfo) return null;
    
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Product')}>
                            <EditorPanelRow className="flex-col">

                                <SelectProductModal
                                    onModalClosed={(selectedProduct) => {
                                        setAttributes({product_id: selectedProduct?.ID ? String(selectedProduct.ID) : ''});
                                        setSelectedProduct(selectedProduct);
                                    }}
                                    selectedProduct={selectedProduct}
                                    setSelectedProduct={setSelectedProduct}
                                    isMultiple={false}
                                />

                                {selectedProduct?.post_title && (
                                    <div className="fct-selected-products">
                                        <span className="fct-selected-products__label">
                                            {blocktranslate('Selected Product')}
                                        </span>
                                        <div className="fct-selected-products__list">
                                            <div className="fct-product-chip-group">
                                                <span className="fct-product-chip fct-product-chip--parent">
                                                    {selectedProduct.post_title}
                                                </span>
                                            </div>
                                        </div>
                                        {selectedProduct?.detail?.stock_availability && (
                                            <div className="fct-selected-products__preview">
                                                <strong>{blocktranslate('Stock')}:</strong>{' '}
                                                {selectedProduct.detail.stock_availability === 'in-stock' && blocktranslate('In Stock')}
                                                {selectedProduct.detail.stock_availability === 'out-of-stock' && blocktranslate('Out of Stock')}
                                                {selectedProduct.detail.stock_availability === 'backorder' && blocktranslate('Available on Backorder')}
                                                {selectedProduct.detail.stock_availability === 'low-stock' && blocktranslate('Low Stock')}
                                            </div>
                                        )}
                                    </div>
                                )}

                            </EditorPanelRow>
                        </EditorPanel>
                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
