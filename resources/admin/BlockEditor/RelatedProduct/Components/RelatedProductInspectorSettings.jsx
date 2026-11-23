import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import ProductSourceControl from "@/BlockEditor/Components/ProductPicker/ProductSourceControl";
import SegmentedToggle from "@/BlockEditor/Components/SegmentedToggle";
import CheckboxGroup from "@/BlockEditor/Components/CheckboxGroup";
import NumberStepper from "@/BlockEditor/Components/NumberStepper";
import {lastRowHint} from "@/BlockEditor/RelatedProduct/lastRowHint";

const {InspectorControls} = wp.blockEditor;
const {PanelBody, SelectControl, ToggleControl} = wp.components;

const COLUMN_CHOICES = [1, 2, 3, 4, 5, 6];
const MAX_PRODUCTS = 12;

const RelatedProductInspectorSettings = ({ attributes, setAttributes, selectedProduct, setSelectedProduct, showDisplayElements }) => {

    // The list is empty until related products load and in Default mode; any list shorter than
    // posts_per_page means the grid is not that long, so there is no short last row to fill.
    const fetchedCount = Array.isArray(attributes.related_products) ? attributes.related_products.length : null;
    const rowHint = fetchedCount !== null && fetchedCount < Number(attributes.posts_per_page)
        ? null
        : lastRowHint(attributes.posts_per_page, attributes.columns, MAX_PRODUCTS);
    const rowHintText = rowHint
        /* translators: 1: cards in the last row, 2: cards per row */
        ? blocktranslate("Last row has %1$s of %2$s cards.", rowHint.lastRow, rowHint.columns)
        : '';

    // No related products may outlive their product.
    const clearProduct = () => {
        setAttributes({product_id: '', related_product_ids: [], related_products: []});
        setSelectedProduct({});
    };

    return (
        <>
            <InspectorControls>
                <PanelBody
                    title={blocktranslate("Product")}
                    initialOpen={ true }
                    className="fct-related-product-inspector-controls"
                >
                    <ProductSourceControl
                        label={blocktranslate("Related to")}
                        queryType={attributes.query_type}
                        onQueryTypeChange={(value) => setAttributes({query_type: value})}
                        defaultNote={blocktranslate("Automatically use the current page's product context.")}
                        productId={attributes.product_id ? String(attributes.product_id) : ''}
                        selectedProduct={selectedProduct}
                        setSelectedProduct={setSelectedProduct}
                        onSelectionConfirmed={(product) => {
                            // A product that failed to load has no id; confirming it must not clear the saved one.
                            if (product && !product.ID) {
                                return;
                            }
                            // Confirming with nothing ticked removes the product, as Remove does.
                            if (!product) {
                                clearProduct();
                                return;
                            }
                            setAttributes({product_id: String(product.ID)});
                        }}
                        onRemove={clearProduct}
                    />

                    <div className="fct-sidebar-section">
                        <CheckboxGroup
                            label={blocktranslate("Match products that share")}
                            options={[
                                {value: 'categories', label: blocktranslate("Categories"), checked: !!attributes.related_by_categories},
                                {value: 'brands', label: blocktranslate("Brands"), checked: !!attributes.related_by_brands},
                            ]}
                            onToggle={(value, checked) => setAttributes(
                                value === 'categories' ? {related_by_categories: checked} : {related_by_brands: checked}
                            )}
                        />
                    </div>
                </PanelBody>

                <PanelBody
                    title={blocktranslate("Display")}
                    initialOpen={ true }
                >
                    <div className="fct-sidebar-stack">
                        <SelectControl
                            label={blocktranslate("Order by")}
                            labelPosition="top"
                            className="fct-sidebar-select"
                            value={attributes.order_by}
                            options={[
                                { label: blocktranslate("Name: A to Z"), value: "title_asc" },
                                { label: blocktranslate("Name: Z to A"), value: "title_desc" },
                                { label: blocktranslate("Newest to oldest"), value: "date_desc" },
                                { label: blocktranslate("Oldest to newest"), value: "date_asc" },
                                { label: blocktranslate("Price, high to low"), value: "price_desc" },
                                { label: blocktranslate("Price, low to high"), value: "price_asc" },
                                { label: blocktranslate("Random"), value: "rand" },
                            ]}
                            onChange={(value) => setAttributes({ order_by: value })}
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                        />

                        <SegmentedToggle
                            label={blocktranslate("Columns")}
                            options={COLUMN_CHOICES.map((count) => ({value: count, label: String(count)}))}
                            value={Number(attributes.columns)}
                            onChange={(value) => setAttributes({ columns: value })}
                            help={blocktranslate("Layout cards show fewer per row where each would be under 220px wide.")}
                        />

                        <div className="fct-sidebar-stack fct-sidebar-stack--tight">
                            <NumberStepper
                                label={blocktranslate("Number of products")}
                                value={Number(attributes.posts_per_page)}
                                min={1}
                                max={MAX_PRODUCTS}
                                onChange={(value) => setAttributes({ posts_per_page: value })}
                            />
                            {/* Always present, so a screen reader announces the note when it appears. */}
                            <span className="screen-reader-text" role="status">{rowHintText}</span>
                            {rowHint && (
                                <div className="fct-related-hint">
                                    <span aria-hidden="true">{rowHintText}</span>
                                    {rowHint.fillTo && (
                                        <button
                                            type="button"
                                            className="fct-related-hint__fill"
                                            onClick={() => setAttributes({ posts_per_page: rowHint.fillTo })}
                                        >
                                            {/* translators: %s is the number of products */}
                                            {blocktranslate("Show %s to fill the row", rowHint.fillTo)}
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </PanelBody>

                {showDisplayElements && <PanelBody
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
                </PanelBody>}
            </InspectorControls>
        </>
    );
};

export default RelatedProductInspectorSettings;
