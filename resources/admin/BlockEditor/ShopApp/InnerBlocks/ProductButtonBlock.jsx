import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
const { useBlockProps } = wp.blockEditor;
const ProductButtonBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fct-product-block-editor-product-card-primary-button',
        });

        const singleProductData = useSingleProductData();

        if (!singleProductData.product) {
            return <button {...props} {...blockProps}>
                {blocktranslate('View')}
            </button>;
        }

        if (singleProductData.product?.detail?.stock_availability === 'out-of-stock') {
            return <button {...props} {...blockProps} disabled className={`${blockProps.className || ''} out-of-stock`} style={{
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

        const variationType = singleProductData.product?.detail?.variation_type;
        const isSimple = variationType === "simple";
        const hasSubscription = !!singleProductData.product?.has_subscription;

        let buttonText = blocktranslate('View Options');

        if (isSimple) {
            if (hasSubscription) {
                buttonText = blocktranslate('Buy Now');
            } else {
                buttonText = blocktranslate('View');
            }
        }


        return <button {...props} {...blockProps}>
            {buttonText}
        </button>;
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
