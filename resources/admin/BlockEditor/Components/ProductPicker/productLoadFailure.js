// GET /products/{product} answers a deleted product with a 403 "Permission Callback Error"
// carrying Eloquent's not-found message (route binding fails in the permission step).
// Pinned by tests/wp-cli/integration/product-not-found-response.php.
const MODEL_NOT_FOUND = /No query results for model/;

// Only a product that is gone counts as missing; a denial or a network error says nothing about it.
export const productLoadFailure = (error) => {
    const isGone = error?.data?.status === 404 || MODEL_NOT_FOUND.test(String(error?.message || ''));
    return isGone ? {missing: true} : {loadError: true};
};
