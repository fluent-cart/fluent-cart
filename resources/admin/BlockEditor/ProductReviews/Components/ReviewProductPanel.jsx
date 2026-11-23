const {SelectControl} = wp.components;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import ProductSourceControl from "@/BlockEditor/Components/ProductPicker/ProductSourceControl";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import ReviewProductsPicker from "@/BlockEditor/ProductReviews/Components/ReviewProductsPicker";
import {
    attributesForSelection,
    attributesForSource,
    productSourceOf,
    SELECTED_PRODUCTS_SOURCE,
    selectedProductIdsOf,
} from "@/BlockEditor/ProductReviews/Components/reviewProductSelection";

/**
 * The shared Product query panel (Default / Custom + product picker) used
 * by every review block when it renders standalone. Inside the Product
 * Reviews container the caller hides this panel — the parent owns the
 * product there.
 *
 * allowMultiProduct swaps Custom for Selected products (one or more) and adds
 * All products; only the Product Reviews and Review List blocks can render them.
 * hasSingleProductChildren explains when the container hides its summary blocks.
 */
const ReviewProductPanel = ({attributes, setAttributes, selectedProduct, setSelectedProduct, allowMultiProduct = false, hasSingleProductChildren = false}) => {
    const productSource = productSourceOf(attributes.query_type);

    return (
        <EditorPanel title={blocktranslate('Product')}>

            {/* query type */}
            <EditorPanelRow>
                {allowMultiProduct ? (
                    <SelectControl
                        className="fct-review-source-select"
                        label={blocktranslate('Show reviews from')}
                        value={productSource}
                        options={[
                            {value: 'default', label: blocktranslate('Current product')},
                            {value: SELECTED_PRODUCTS_SOURCE, label: blocktranslate('Selected products')},
                            {value: 'all', label: blocktranslate('All products')},
                        ]}
                        onChange={(source) => {
                            setAttributes(attributesForSource(source, attributes));
                        }}
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                ) : (
                    <ProductSourceControl
                        queryType={attributes.query_type}
                        productId={attributes.product_id || ''}
                        onQueryTypeChange={(value) => setAttributes({query_type: value})}
                        selectedProduct={selectedProduct}
                        setSelectedProduct={setSelectedProduct}
                        onSelectionConfirmed={(product) => {
                            setAttributes({product_id: product?.ID ? String(product.ID) : ''});
                        }}
                        onRemove={() => {
                            setAttributes({product_id: ''});
                            setSelectedProduct(null);
                        }}
                    />
                )}
            </EditorPanelRow>

            {allowMultiProduct && productSource === SELECTED_PRODUCTS_SOURCE && (
                <EditorPanelRow className="flex-col fct-review-product-picker">
                    <ReviewProductsPicker
                        productIds={selectedProductIdsOf(attributes)}
                        onChange={(productIds) => {
                            setAttributes(attributesForSelection(productIds));
                        }}
                    />
                </EditorPanelRow>
            )}

            {allowMultiProduct && productSource === 'all' && (
                <EditorPanelRow>
                    <p className="fct-review-preset-help">
                        {blocktranslate('Approved reviews from every published product that takes reviews.')}
                    </p>
                </EditorPanelRow>
            )}

            {allowMultiProduct && hasSingleProductChildren && productSource !== 'default' && (
                <EditorPanelRow>
                    <p className="fct-review-preset-help">
                        {productSource === 'all'
                            ? blocktranslate('The rating summary combines every product. The Write a Review button and review form need a single product, so they are removed.')
                            : blocktranslate('With two or more products, the rating summary combines them and the Write a Review button and review form are removed.')}
                    </p>
                </EditorPanelRow>
            )}

        </EditorPanel>
    );
};

export default ReviewProductPanel;
