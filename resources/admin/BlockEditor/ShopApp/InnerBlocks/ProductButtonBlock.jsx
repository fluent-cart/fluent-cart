import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";
import blocktranslate, {_x} from "@/BlockEditor/BlockEditorTranslator";
const { useBlockProps } = wp.blockEditor;
const { useSelect } = wp.data;
import useProductSwatchPreview, {parseSwatchPreview} from '@/BlockEditor/ProductSwatches/useProductSwatchPreview';

const CARD_TEMPLATES = ['fluent-cart/product-card', 'fluent-cart/shopapp-product-loop', 'fluent-cart/product-carousel-loop', 'fluent-cart/product-template'];

// Only the button's own card template counts: a page can hold several product loops.
export const findCardSwatches = (editor, parents) => {
    const template = [...parents].reverse().find((id) => CARD_TEMPLATES.includes(editor.getBlockName(id)));
    if (!template) {
        return null;
    }
    return editor.getClientIdsOfDescendants([template])
        .filter((id) => editor.getBlockName(id) === 'fluent-cart/product-swatches')
        .map((id) => editor.getBlockAttributes(id));
};

// The Product Swatches block in the same card template, if any: on the storefront it turns
// View Options into the buy button for the picked variant.
export const useCardSwatches = (clientId) => useSelect((select) => {
    let editor = select('core/block-editor');
    let parents = editor.getBlockParents(clientId);
    if (!parents.length) {
        // The other cards are block previews with a store of their own; the template lives in the editor's.
        editor = wp.data.select('core/block-editor');
        parents = editor.getBlockParents(clientId);
    }
    return findCardSwatches(editor, parents);
}, [clientId]);

export const previewButtonState = html => parseSwatchPreview(html).button;

const ProductButtonBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fct-product-block-editor-product-card-primary-button',
        });

        const singleProductData = useSingleProductData();
        const swatches = useCardSwatches(props.clientId);
        const preview = useProductSwatchPreview(singleProductData?.product?.ID, swatches);
        const state = preview?.previews?.find(item => item.button)?.button;

        if (!singleProductData?.product) {
            return <button {...blockProps}>
                {_x('View', 'Product button')}
            </button>;
        }

        if (singleProductData?.product?.detail?.stock_availability === 'out-of-stock') {
            return <button {...blockProps} disabled className={`${blockProps.className || ''} out-of-stock`} style={{
                background: '#fff',
                boxShadow: 'none',
                border: '1px solid #d6dae1',
                color: '#2F3448',
                fontWeight: '600',
                cursor: 'not-allowed',
                pointerEvents: 'none',
            }}>
                {blocktranslate('Out of Stock')}
            </button>;
        }

        const variationType = singleProductData?.product?.detail?.variation_type;
        const isSimple = variationType === "simple";
        const hasSubscription = !!singleProductData?.product?.has_subscription;

        let buttonText = blocktranslate('View Options');

        if (!isSimple && state) {
            buttonText = state.label;
        }

        if (isSimple) {
            if (hasSubscription) {
                buttonText = blocktranslate('Buy Now');
            } else {
                buttonText = _x('View', 'Product button');
            }
        }


        return <ErrorBoundary>
            {preview?.error && <span role="status">{preview.error}</span>}
            <button {...blockProps} disabled={state?.mode === 'off'}>
                {buttonText}
            </button>
        </ErrorBoundary>;
    },
    save: (props) => {
        return null;
    },
    supports: {
        html: false,
        align: ["left", "center", "right"],
        typography: {
            fontSize: true,
            lineHeight: true
        },
        spacing: {
            margin: true,
            padding: true
        },
        color: {
            text: true
        },
        __experimentalBorder: {
            radius: true,
            color: true,
            width: true,
            style: true,
        }
    },
};

export default ProductButtonBlock;
