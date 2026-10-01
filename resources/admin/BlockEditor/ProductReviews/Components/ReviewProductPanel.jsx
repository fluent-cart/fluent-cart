const {__experimentalToggleGroupControl: ToggleGroupControl, __experimentalToggleGroupControlOption: ToggleGroupControlOption} = wp.components;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

/**
 * The shared Product query panel (Default / Custom + product picker) used
 * by every review block when it renders standalone. Inside the Product
 * Reviews container the caller hides this panel — the parent owns the
 * product there.
 */
const ReviewProductPanel = ({attributes, setAttributes, selectedProduct, setSelectedProduct}) => {
    return (
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
                        onSelectionConfirmed={(product) => {
                                setAttributes({product_id: product?.ID ? String(product.ID) : ''});
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
    );
};

export default ReviewProductPanel;
