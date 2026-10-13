import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {PanelBody, Notice, Button} = wp.components;

/**
 * Stands in for the product-selector panel when the add-on that provides it is
 * not installed.
 *
 * The checkout block is where an author goes looking for "let shoppers pick a
 * product here", so an empty inspector is the wrong answer — it reads as the
 * feature not existing rather than not being installed. This says what the
 * panel would do and where to get it.
 *
 * Rendered ONLY when the add-on is absent. When it is present it registers a
 * real Products panel of its own, and two panels with the same name would be
 * worse than none.
 *
 * When the block already carries a selection, say so. This block declares the
 * selector's attributes inertly, so a configuration made while the add-on was
 * installed survives here intact — and an author looking at a panel that offers
 * them nothing would otherwise reasonably assume it had been thrown away.
 *
 * @param {Object} props
 * @param {Object} props.attributes The block's saved attributes.
 */
const ProductsProPanel = ({attributes}) => {
    const blockData = window.fluent_cart_checkout_data || {};
    const savedCount = Array.isArray(attributes?.variant_ids) ? attributes.variant_ids.length : 0;

    return (
        <PanelBody title={blocktranslate('Products')} initialOpen={true}>
            <Notice status="info" isDismissible={false}>
                {blocktranslate('Let shoppers choose from products you pick, right here on the checkout — no product page visit needed. Available in FluentCart Pro.')}
            </Notice>

            {savedCount > 0 && (
                <Notice status="warning" isDismissible={false}>
                    {blocktranslate('This checkout already has products configured. They are kept, and work again as soon as FluentCart Pro is active.')}
                </Notice>
            )}

            <Button
                variant="primary"
                href={blockData.upgrade_url || 'https://fluentcart.com/pricing/'}
                target="_blank"
                rel="noopener noreferrer"
                style={{marginTop: '8px'}}
            >
                {blocktranslate('Upgrade to Pro')}
            </Button>
        </PanelBody>
    );
};

export default ProductsProPanel;
