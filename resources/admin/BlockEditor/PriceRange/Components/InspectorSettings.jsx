import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal.jsx";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";

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
                                        {selectedProduct?.detail && (
                                            <div className="fct-selected-products__preview">
                                                <strong>{blocktranslate('Price')}:</strong>{' '}
                                                <span dangerouslySetInnerHTML={{__html: selectedProduct.detail.formatted_min_price}} />
                                                {selectedProduct.detail.min_price !== selectedProduct.detail.max_price && (
                                                    <>
                                                        {' - '}
                                                        <span dangerouslySetInnerHTML={{__html: selectedProduct.detail.formatted_max_price}} />
                                                    </>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                            </EditorPanelRow>
                        </EditorPanel>

                        <EditorPanel title={blocktranslate('Settings')}>
                            <EditorPanelRow>
                                <div className="fct-block-editor-control-item">
                                    <div className="fct-inspector-control-row">
                                        <span className="label">{blocktranslate('Price Format')}</span>
                                        <div className="actions">
                                            <CustomSelect
                                                customKeys={{
                                                    key: 'value',
                                                    label: 'label'
                                                }}
                                                defaultValue={attributes.price_format}
                                                options={[
                                                    {label: blocktranslate('Starts From'), value: 'starts_from'},
                                                    {label: blocktranslate('Range'), value: 'range'},
                                                ]}
                                                onChange={function (value) {
                                                    setAttributes({price_format: value});
                                                }}
                                            />
                                        </div>
                                    </div>
                                </div>
                            </EditorPanelRow>
                        </EditorPanel>
                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
