import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal";

const { InspectorControls } = wp.blockEditor;
const { __experimentalToggleGroupControl, __experimentalToggleGroupControlOption } = wp.components;
const ToggleGroupControl = __experimentalToggleGroupControl;
const ToggleGroupControlOption = __experimentalToggleGroupControlOption;

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

                            {/* query type */}
                            <EditorPanelRow>
                                <ToggleGroupControl
                                    isBlock
                                    label={blocktranslate('Query type')}
                                    value={attributes.query_type}
                                    onChange={(value) => {
                                        setAttributes({query_type: value});
                                    }}
                                >
                                    <ToggleGroupControlOption value="default" label={blocktranslate('Default')} />
                                    <ToggleGroupControlOption value="custom" label={blocktranslate('Custom')} />
                                </ToggleGroupControl>
                            </EditorPanelRow>

                            {attributes.query_type === 'custom' && (
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
                                        </div>
                                    )}

                                </EditorPanelRow>
                            )}

                        </EditorPanel>
                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
