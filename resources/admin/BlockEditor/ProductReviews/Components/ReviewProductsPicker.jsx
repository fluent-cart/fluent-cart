import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {Cross} from "@/BlockEditor/Icons";
import {MAX_SELECTED_PRODUCTS, modalSelectionFor} from "@/BlockEditor/ProductReviews/Components/reviewProductSelection";

const {useEffect, useState} = wp.element;
const {Notice} = wp.components;

const rest = window.fluentCartRestVars?.rest || {};

const toProductList = (response) => {
    const products = response?.products?.data || response?.products || [];

    return Array.isArray(products) ? products : [];
};

/**
 * Product picker for the Multiple products query. Saves ids only; titles are
 * loaded for the chips. Ids of deleted products stay saved so the list never widens.
 */
const ReviewProductsPicker = ({productIds, onChange}) => {
    const [selectedProducts, setSelectedProducts] = useState([]);
    const [loadFailed, setLoadFailed] = useState(false);
    const [wasCapped, setWasCapped] = useState(false);
    const productIdsKey = productIds.join(',');

    useEffect(() => {
        const loadedIds = selectedProducts.map((product) => String(product.ID));
        const alreadyLoaded = productIds.every((id) => loadedIds.includes(String(id)));

        if (!productIds.length || alreadyLoaded || !rest.url) {
            if (!productIds.length) {
                setSelectedProducts([]);
            }
            return undefined;
        }

        let stale = false;
        setLoadFailed(false);

        apiFetch({
            path: addQueryArgs(rest.url + '/products/fetchProductsByIds', {productIds}),
            headers: {
                'X-WP-Nonce': rest.nonce
            },
        }).then((response) => {
            if (!stale) {
                setSelectedProducts(toProductList(response));
            }
        }).catch(() => {
            if (!stale) {
                setLoadFailed(true);
            }
        });

        return () => {
            stale = true;
        };
    }, [productIdsKey]);

    const confirmSelection = (products) => {
        const pickedProducts = (Array.isArray(products) ? products : []).filter((product) => product?.ID);
        const keptProducts = pickedProducts.slice(0, MAX_SELECTED_PRODUCTS);

        setWasCapped(pickedProducts.length > keptProducts.length);
        // Placeholders have no title yet; dropping them lets the titles load.
        setSelectedProducts(keptProducts.filter((product) => !product.isPlaceholder));
        onChange(keptProducts.map((product) => parseInt(product.ID, 10)));
    };

    const removeProduct = (productId) => {
        setSelectedProducts((products) => products.filter((product) => String(product.ID) !== String(productId)));
        onChange(productIds.filter((id) => String(id) !== String(productId)));
    };

    const titleById = {};
    selectedProducts.forEach((product) => {
        titleById[String(product.ID)] = product.post_title;
    });

    return (
        <>
            <SelectProductModal
                onSelectionConfirmed={confirmSelection}
                selectedProduct={modalSelectionFor(productIds, selectedProducts)}
                setSelectedProduct={() => {}}
                isMultiple={true}
            />

            {wasCapped && (
                <Notice status="warning" isDismissible={false}>
                    {blocktranslate('Only the first %s products were kept.', MAX_SELECTED_PRODUCTS)}
                </Notice>
            )}

            {loadFailed && (
                <Notice status="error" isDismissible={false}>
                    {blocktranslate('Could not load the selected products. The saved selection is unchanged.')}
                </Notice>
            )}

            {productIds.length ? (
                <div className="fct-selected-products">
                    <span className="fct-selected-products__label">
                        {blocktranslate('Selected products')} ({productIds.length})
                    </span>
                    {/* The id tells apart products that share a name. */}
                    <ul className="fct-review-selected-products">
                        {productIds.map((productId) => {
                            const title = titleById[String(productId)] || blocktranslate('Unavailable product');

                            return (
                                <li key={productId} className="fct-review-selected-product">
                                    <span className="fct-review-selected-product__title" title={title}>{title}</span>
                                    <span className="fct-review-selected-product__id">#{productId}</span>
                                    <button
                                        type="button"
                                        className="fct-review-selected-product__remove"
                                        aria-label={blocktranslate('Remove %s', title + ' #' + productId)}
                                        onClick={() => removeProduct(productId)}
                                    >
                                        <Cross/>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ) : (
                <p className="fct-review-preset-help">
                    {blocktranslate('Select at least one product. Nothing is shown until you do.')}
                </p>
            )}
        </>
    );
};

export default ReviewProductsPicker;
