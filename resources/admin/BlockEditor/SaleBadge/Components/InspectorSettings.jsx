import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal.jsx";

const {InspectorControls} = wp.blockEditor;
const {TextControl, ToggleControl, SelectControl, PanelBody} = wp.components;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const InspectorSettings = ({
    attributes,
    setAttributes,
    selectedProduct,
    setSelectedProduct,
    isInsideProductInfo,
    isInsideVisualContainer,
    saleInfo,
}) => {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--sale-badge">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">

                        {/* Badge Content Settings */}
                        <EditorPanel title={blocktranslate('Badge Settings')}>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Badge Text')}
                                    value={attributes.badge_text}
                                    onChange={(val) => setAttributes({badge_text: val})}
                                    help={blocktranslate('Text shown when not using percentage mode.')}
                                />

                                <ToggleControl
                                    label={blocktranslate('Show Discount Percentage')}
                                    checked={attributes.show_percentage}
                                    onChange={(val) => setAttributes({show_percentage: val})}
                                />

                                {attributes.show_percentage && (
                                    <TextControl
                                        label={blocktranslate('Percentage Format')}
                                        value={attributes.percentage_text}
                                        onChange={(val) => setAttributes({percentage_text: val})}
                                        help={blocktranslate('Use {percent} as placeholder. E.g., "-{percent}% OFF"')}
                                    />
                                )}

                                <SelectControl
                                    label={blocktranslate('Price Source')}
                                    value={attributes.price_source}
                                    options={[
                                        {label: blocktranslate('Default Variant'), value: 'default_variant'},
                                        {label: blocktranslate('Best Discount (All Variants)'), value: 'best_discount'},
                                    ]}
                                    onChange={(val) => setAttributes({price_source: val})}
                                    help={blocktranslate('Where to check the sale price from.')}
                                />

                                {saleInfo && (
                                    <div className={`fct-sale-badge-status ${saleInfo.isOnSale ? 'fct-sale-badge-status--on-sale' : 'fct-sale-badge-status--not-on-sale'}`}>
                                        {saleInfo.isOnSale
                                            ? `${blocktranslate('On Sale')} — ${saleInfo.discountPercent}% ${blocktranslate('discount')}`
                                            : blocktranslate('Product is not on sale')
                                        }
                                    </div>
                                )}
                            </EditorPanelRow>
                        </EditorPanel>

                        {/* Position & Style — only inside image/card containers */}
                        {isInsideVisualContainer && (
                            <EditorPanel title={blocktranslate('Position & Style')}>
                                <EditorPanelRow className="flex-col">
                                    <SelectControl
                                        label={blocktranslate('Badge Style')}
                                        value={attributes.badge_style}
                                        options={[
                                            {label: blocktranslate('Badge'), value: 'badge'},
                                            {label: blocktranslate('Ribbon'), value: 'ribbon'},
                                            {label: blocktranslate('Tag'), value: 'tag'},
                                        ]}
                                        onChange={(val) => setAttributes({badge_style: val})}
                                    />

                                    <SelectControl
                                        label={blocktranslate('Position')}
                                        value={attributes.badge_position}
                                        options={[
                                            {label: blocktranslate('Top Left'), value: 'top-left'},
                                            {label: blocktranslate('Top Right'), value: 'top-right'},
                                            {label: blocktranslate('Bottom Left'), value: 'bottom-left'},
                                            {label: blocktranslate('Bottom Right'), value: 'bottom-right'},
                                        ]}
                                        onChange={(val) => setAttributes({badge_position: val})}
                                    />
                                </EditorPanelRow>
                            </EditorPanel>
                        )}

                        {/* Product Picker — only when standalone */}
                        {!isInsideProductInfo && (
                            <EditorPanel title={blocktranslate('Product')}>
                                <EditorPanelRow className="flex-col">
                                    <SelectProductModal
                                        onModalClosed={(product) => {
                                            setAttributes({product_id: product?.ID ? String(product.ID) : ''});
                                            setSelectedProduct(product);
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
                            </EditorPanel>
                        )}

                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
