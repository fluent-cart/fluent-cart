import SelectVariationModal from "@/BlockEditor/Components/ProductPicker/SelectVariationModal";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {InspectorControls} = wp.blockEditor;
const {TextControl, ToggleControl} = wp.components;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";

const InspectorSettings = ({
    attributes,
    setAttributes,
    selectedProduct,
    setSelectedProduct,
    selectedVariations,
    setSelectedVariations,
    sku,
    isInsideProductInfo = false,
}) => {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-sku">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">

                        <EditorPanel title={blocktranslate('SKU Settings')}>
                            <EditorPanelRow className="flex-col">
                                <ToggleControl
                                    label={blocktranslate('Show Label')}
                                    checked={attributes.show_label}
                                    onChange={(val) => setAttributes({show_label: val})}
                                />

                                {attributes.show_label && (
                                    <TextControl
                                        label={blocktranslate('Label Text')}
                                        value={attributes.label}
                                        onChange={(val) => setAttributes({label: val})}
                                    />
                                )}

                                {sku && (
                                    <div className="fct-product-sku-info">
                                        <strong>{blocktranslate('Current SKU')}:</strong> {sku}
                                    </div>
                                )}
                            </EditorPanelRow>
                        </EditorPanel>

                        {!isInsideProductInfo && (
                            <EditorPanel title={blocktranslate('Product')}>
                                <EditorPanelRow className="flex-col">

                                    <p>{blocktranslate("Select a specific product variation for this button.", 'fluent-cart')}</p>

                                    <SelectVariationModal
                                        preSelectedVariations={selectedVariations}
                                        onModalClosed={(selected) => {
                                            if (!selected || Object.keys(selected).length === 0) {
                                                setAttributes({
                                                    variant_id: '',
                                                    product_id: '',
                                                });
                                                setSelectedVariations({});
                                                setSelectedProduct({});
                                                return;
                                            }

                                            const firstVariant = Object.values(selected)[0];
                                            const variantId = String(firstVariant?.id ?? '');
                                            const productId = String(firstVariant?.post_id ?? '');

                                            setAttributes({
                                                variant_id: variantId,
                                                product_id: productId,
                                            });

                                            setSelectedVariations({...selected});
                                        }}
                                        selectedVariations={selectedVariations}
                                        setSelectedVariations={setSelectedVariations}
                                        button={true}
                                        isMultiple={false}
                                    />

                                    {Object.keys(selectedVariations).length > 0 && (
                                        <div className="fct-selected-products">
                                            <span className="fct-selected-products__label">
                                                {blocktranslate('Selected Variation')}
                                            </span>
                                            <div className="fct-selected-products__list">
                                                <div className="fct-product-chip-group">
                                                    {selectedProduct?.post_title && (
                                                        <span className="fct-product-chip fct-product-chip--parent">
                                                            {selectedProduct.post_title}
                                                        </span>
                                                    )}
                                                    {Object.values(selectedVariations).map((variant) => (
                                                        <span
                                                            key={variant.id}
                                                            className="fct-variation-chip"
                                                        >
                                                            {variant.variation_title || variant.title}
                                                        </span>
                                                    ))}
                                                </div>
                                            </div>
                                            <div className="fct-selected-products__preview">
                                                <strong>{blocktranslate('SKU')}:</strong> 
                                                {sku || blocktranslate('N/A')}
                                            </div>
                                        </div>
                                    )}

                                </EditorPanelRow>
                            </EditorPanel>
                        )}

                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
