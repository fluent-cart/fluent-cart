import { useSingleProductData } from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
const { useBlockProps } = wp.blockEditor;
const ProductExcerptBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fct-product-block-editor-product-card-excerpt',
        });

        const singleProductData = useSingleProductData();

        return <p { ...props } {...blockProps}>
            {singleProductData && singleProductData.product?.post_excerpt || blocktranslate('(empty)')}
        </p>;
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
        }
    }
};

export default ProductExcerptBlock;

