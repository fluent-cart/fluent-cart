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

export const isMultiProductQuery = (queryType) => queryType === 'all' || queryType === 'multiple';

/**
 * The query of the block that owns this one's product: the nearest Product
 * Reviews container, else a standalone Summary Group; null outside both. Read
 * from attributes: a Summary Group inside a container provides its own query
 * context, which would hide the container's mode from its children.
 */
export const useReviewContainerQuery = (clientId) => {
    const containerId = useSelect((select) => {
        const {getBlockParents, getBlockName} = select(blockEditorStore);
        const parents = getBlockParents(clientId, true);

        return parents.find((parentId) => getBlockName(parentId) === 'fluent-cart/product-reviews')
            || parents.find((parentId) => getBlockName(parentId) === 'fluent-cart/product-review-summary-group')
            || '';
    }, [clientId]);

    const containerAttributes = useSelect(
        (select) => (containerId ? select(blockEditorStore).getBlockAttributes(containerId) : null),
        [containerId]
    );

    if (!containerAttributes) {
        return null;
    }

    return {
        queryType: containerAttributes.query_type || 'default',
        productIds: Array.isArray(containerAttributes.product_ids) ? containerAttributes.product_ids : [],
    };
};

/**
 * Whether Write a Review or the form sits in an All or Selected products (two or
 * more) container, which renders it as nothing on the page.
 */
export const useHiddenByReviewContainer = (clientId) => {
    const containerQuery = useReviewContainerQuery(clientId);
    const hidden = !!containerQuery && isMultiProductQuery(containerQuery.queryType);

    return {hidden, showNotice: hidden};
};

/**
 * Review count for the All or Multiple products preview, from the same endpoint
 * the page pages through. An empty Multiple selection is not fetched: it lists nothing.
 *
 * @returns {{status: 'idle'|'loading'|'ready'|'unavailable'|'error', total: number}}
 */
export const useMultiProductReviewCount = (queryType, productIds) => {
    const [countState, setCountState] = useState({status: 'idle', total: 0});
    const productIdsKey = (Array.isArray(productIds) ? productIds : []).join(',');
    const shouldFetch = queryType === 'all' || (queryType === 'multiple' && productIdsKey !== '');

    useEffect(() => {
        if (!shouldFetch || !rest.url) {
            setCountState({status: 'idle', total: 0});
            return undefined;
        }

        let stale = false;
        setCountState((previous) => ({...previous, status: 'loading'}));

        // parse: false keeps the HTTP status: a 404 means reviews are switched off.
        apiFetch({
            path: addQueryArgs(rest.url + '/public/reviews/all', {
                per_page: 1,
                product_ids: queryType === 'multiple' ? productIdsKey : '',
            }),
            headers: {
                'X-WP-Nonce': rest.nonce
            },
            parse: false,
        }).then((response) => response.json()).then((body) => {
            if (!stale) {
                setCountState({status: 'ready', total: parseInt(body?.reviews?.total, 10) || 0});
            }
        }).catch((error) => {
            if (!stale) {
                setCountState({status: error?.status === 404 ? 'unavailable' : 'error', total: 0});
            }
        });

        return () => {
            stale = true;
        };
    }, [shouldFetch, queryType, productIdsKey]);

    return countState;
};
