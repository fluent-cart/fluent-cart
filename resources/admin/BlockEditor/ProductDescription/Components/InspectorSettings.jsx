import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal.jsx";

const { InspectorControls } = wp.blockEditor;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const InspectorSettings = ({ attributes, setAttributes, selectedProduct, setSelectedProduct }) => {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-description">
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
                                        <div className="fct-selected-products__preview">
                                            <strong>{blocktranslate('Description')}:</strong>{' '}
                                            {(() => {
                                                if (!selectedProduct?.post_content) return blocktranslate('(empty)');
                                                const stripped = selectedProduct.post_content.replace(/<[^>]*>/g, '');
                                                return stripped.length > 120 ? stripped.substring(0, 120) + '...' : stripped;
                                            })()}
                                        </div>
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
