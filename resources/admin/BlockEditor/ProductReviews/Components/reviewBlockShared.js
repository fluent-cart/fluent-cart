import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";

const {useEffect, useState} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;

const rest = window.fluentCartRestVars?.rest || {};

/**
 * Whether this block sits inside a product-owning container — a review
 * container, or the Product Info / product loop family ProductTitle and
 * friends honor. Inside one, the block follows the container's product
 * and hides its own product controls; outside it behaves standalone.
 */
export const useInsideProductReviews = (clientId) => useSelect((select) => {
    const {getBlockParents, getBlockName} = select(blockEditorStore);
    const containers = [
        'fluent-cart/product-reviews',
        'fluent-cart/product-review-summary-group',
        'fluent-cart/product-info',
        'fluent-cart/products',
        'fluent-cart/shopapp-product-container',
        'fluent-cart/shopapp-product-loop',
        'fluent-cart/product-carousel',
        'fluent-cart/product-carousel-loop',
        'fluent-cart/related-product',
        'fluent-cart/product-template',
    ];

    return getBlockParents(clientId).some(
        (parentId) => containers.includes(getBlockName(parentId))
    );
}, [clientId]);

/**
 * Product + rating-summary preview data for a review block. Inside the
 * container the parent's SingleProductDataProvider supplies the product;
 * standalone custom mode fetches the picked product. The summary always
 * comes from the public summary endpoint (it reads the canonical rating
 * cache and backfills a missing breakdown server-side), keyed to whichever
 * product is effective. isStale guards against out-of-order responses:
 * switching products quickly must never let an older request's response
 * (or failure) overwrite the preview for the current selection.
 */
export const useReviewBlockProduct = (attributes, insideParent, withSummary = false) => {
    const singleProductData = useSingleProductData();
    const [selectedProduct, setSelectedProduct] = useState({});
    const [reviewSummary, setReviewSummary] = useState(null);

    const contextProduct = singleProductData?.product || null;
    const isCustom = !insideParent && attributes.query_type === 'custom';
    const effectiveProductId = insideParent
        ? (contextProduct?.ID || '')
        : (isCustom ? attributes.product_id : (contextProduct?.ID || ''));

    useEffect(() => {
        let stale = false;
        const isStale = () => stale;

        if (isCustom) {
            if (attributes.product_id && rest.url) {
                apiFetch({
                    path: addQueryArgs(rest.url + '/products/' + attributes.product_id, {
                        // Screen key, not a relation name — ProductController::allowedWiths()
                        // owns what it resolves to (detail + variants).
                        with: ['block_product_detail']
                    }),
                    headers: {
                        'X-WP-Nonce': rest.nonce
                    }
                }).then((response) => {
                    if (!isStale()) {
                        setSelectedProduct(response.product || {});
                    }
                }).catch(() => {
                    if (!isStale()) {
                        setSelectedProduct({});
                    }
                });
            } else {
                setSelectedProduct({});
            }
        } else {
            setSelectedProduct(contextProduct || {});
        }

        return () => {
            stale = true;
        };
    }, [contextProduct, isCustom, attributes.product_id]);

    useEffect(() => {
        let stale = false;
        const isStale = () => stale;

        if (withSummary && effectiveProductId && rest.url) {
            apiFetch({
                path: rest.url + '/public/reviews/' + effectiveProductId + '/summary',
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            }).then((response) => {
                if (!isStale()) {
                    setReviewSummary(response.summary || null);
                }
            }).catch(() => {
                if (!isStale()) {
                    setReviewSummary(null);
                }
            });
        } else {
            setReviewSummary(null);
        }

        return () => {
            stale = true;
        };
    }, [withSummary, effectiveProductId]);

    return {selectedProduct, setSelectedProduct, reviewSummary, effectiveProductId};
};
