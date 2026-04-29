import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";

const { useBlockProps } = wp.blockEditor;
const ProductPriceBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fct-product-block-editor-product-card-sub-title',
        });
        const singleProductData = useSingleProductData();
        return <div {...props} {...blockProps}>
            {singleProductData ? (
                <>
                    <div dangerouslySetInnerHTML={{__html: singleProductData.product?.detail?.formatted_min_price}}></div>
                    {singleProductData.product?.detail?.min_price !== singleProductData.product?.detail?.max_price && (
                        <div> - <span dangerouslySetInnerHTML={{__html: singleProductData.product?.detail?.formatted_max_price}}></span></div>
                    )}
                </>
                )
            : '$0.00'}
        </div>;
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
            margin: true
        },
        color: {
            text: true,
            background: true
        }
    },
    usesContext: [
        'fluent-cart/price_format',
    ]
};

export default ProductPriceBlock;
