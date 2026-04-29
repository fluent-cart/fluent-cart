import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal.jsx";

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
                                        {selectedProduct?.detail?.featured_media?.url && (
                                            <div className="fct-selected-products__preview">
                                                <img
                                                    src={selectedProduct.detail.featured_media.url}
                                                    alt={selectedProduct.post_title}
                                                    style={{width: '100%', maxHeight: '150px', objectFit: 'cover', borderRadius: '4px', marginTop: '8px'}}
                                                />
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
