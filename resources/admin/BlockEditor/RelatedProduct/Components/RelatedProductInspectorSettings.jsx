import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import ProductDropdownPicker from "@/BlockEditor/Components/ProductPicker/ProductDropdownPicker.jsx";

const {InspectorControls} = wp.blockEditor;
const {PanelBody, CheckboxControl, SelectControl, RangeControl, ToggleControl, __experimentalToolsPanel: ToolsPanel, __experimentalToolsPanelItem: ToolsPanelItem, __experimentalToggleGroupControl: ToggleGroupControl, __experimentalToggleGroupControlOption: ToggleGroupControlOption} = wp.components;

const RelatedProductInspectorSettings = ({ attributes, setAttributes, selectedProduct, setSelectedProduct }) => {

    const handleProductSelect = (product) => {
        setAttributes({product_id: product.ID || ''});
        setSelectedProduct(product);
    };

    return (
        <>
            <InspectorControls>
                {attributes.query_type === 'custom' && (
                    <PanelBody
                        title={ selectedProduct?.post_title ? blocktranslate("Linked Product") : blocktranslate("Product Settings") }
                        initialOpen={ true }
                        className="fct-related-product-inspector-controls"
                    >
                        <ProductDropdownPicker
                            selectedProduct={selectedProduct}
                            onSelect={handleProductSelect}
                        />
                    </PanelBody>
                )}

                <PanelBody
                    title={blocktranslate("Related by")}
                    initialOpen={true}
                >
                    <CheckboxControl
                        label={blocktranslate("Categories")}
                        checked={attributes.related_by_categories}
                        onChange={(value) => setAttributes({related_by_categories: value})}
                    />
                    <CheckboxControl
                        label={blocktranslate("Brands")}
                        checked={attributes.related_by_brands}
                        onChange={(value) => setAttributes({related_by_brands: value})}
                    />
                </PanelBody>

                <ToolsPanel
                    label={blocktranslate("Settings")}
                    resetAll={() => setAttributes({ query_type: 'custom', order_by: 'title_asc', columns: 4, posts_per_page: 6 })}
                >
                    <ToolsPanelItem
                        hasValue={() => attributes.query_type !== 'default'}
                        label={blocktranslate("Query type")}
                        onDeselect={() => setAttributes({ query_type: 'default' })}
                        isShownByDefault
                    >
                        <ToggleGroupControl
                            label={blocktranslate("Query type")}
                            value={attributes.query_type}
                            onChange={(value) => setAttributes({ query_type: value })}
                            help={attributes.query_type === 'custom'
                                ? blocktranslate("Select a specific product to display related items.")
                                : blocktranslate("Automatically use the current page's product context.")
                            }
                            isBlock
                        >
                            <ToggleGroupControlOption value="default" label={blocktranslate("Default")} />
                            <ToggleGroupControlOption value="custom" label={blocktranslate("Custom")} />
                        </ToggleGroupControl>
                    </ToolsPanelItem>

                    <ToolsPanelItem
                        hasValue={() => attributes.order_by !== 'title_asc'}
                        label={blocktranslate("Order by")}
                        onDeselect={() => setAttributes({ order_by: 'title_asc' })}
                        isShownByDefault
                    >
                        <SelectControl
                            label={blocktranslate("Order by")}
                            value={attributes.order_by}
                            options={[
                                { label: blocktranslate("A → Z"), value: "title_asc" },
                                { label: blocktranslate("Z → A"), value: "title_desc" },
                                { label: blocktranslate("Newest to oldest"), value: "date_desc" },
                                { label: blocktranslate("Oldest to newest"), value: "date_asc" },
                                { label: blocktranslate("Price, high to low"), value: "price_desc" },
                                { label: blocktranslate("Price, low to high"), value: "price_asc" },
                                { label: blocktranslate("Random"), value: "rand" },
                            ]}
                            onChange={(value) => setAttributes({ order_by: value })}
                        />
                    </ToolsPanelItem>

                    <ToolsPanelItem
                        hasValue={() => attributes.columns !== 4}
                        label={blocktranslate("Columns")}
                        onDeselect={() => setAttributes({ columns: 4 })}
                        isShownByDefault
                    >
                        <RangeControl
                            label={blocktranslate("Columns")}
                            value={attributes.columns}
                            onChange={(value) => setAttributes({ columns: value })}
                            min={1}
                            max={6}
                        />
                    </ToolsPanelItem>

                    <ToolsPanelItem
                        hasValue={() => attributes.posts_per_page !== 6}
                        label={blocktranslate("Products per page")}
                        onDeselect={() => setAttributes({ posts_per_page: 6 })}
                        isShownByDefault
                    >
                        <RangeControl
                            label={blocktranslate("Products per page")}
                            value={attributes.posts_per_page}
                            onChange={(value) => setAttributes({ posts_per_page: value })}
                            min={1}
                            max={12}
                        />
                    </ToolsPanelItem>
                </ToolsPanel>

                <PanelBody
                    title={blocktranslate("Display Elements")}
                    initialOpen={false}
                >
                    <ToggleControl
                        label={blocktranslate("Image")}
                        checked={attributes.show_image}
                        onChange={(value) => setAttributes({ show_image: value })}
                    />
                    <ToggleControl
                        label={blocktranslate("Title")}
                        checked={attributes.show_title}
                        onChange={(value) => setAttributes({ show_title: value })}
                    />
                    <ToggleControl
                        label={blocktranslate("Price")}
                        checked={attributes.show_price}
                        onChange={(value) => setAttributes({ show_price: value })}
                    />
                    <ToggleControl
                        label={blocktranslate("Button")}
                        checked={attributes.show_button}
                        onChange={(value) => setAttributes({ show_button: value })}
                    />
                </PanelBody>
            </InspectorControls>
        </>
    );
};

export default RelatedProductInspectorSettings;
