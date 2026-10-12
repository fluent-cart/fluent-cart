import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {Notice} = wp.components;

/**
 * Shown on Write a Review and the review form inside an All or Selected
 * products (two or more) container, which renders them as nothing on the page.
 */
const SingleProductOnlyNotice = () => (
    <Notice status="warning" isDismissible={false} className="fct-review-single-product-notice">
        {blocktranslate('Hidden on the page: a review needs a single product, and the Product Reviews block is showing several.')}
    </Notice>
);

export default SingleProductOnlyNotice;
