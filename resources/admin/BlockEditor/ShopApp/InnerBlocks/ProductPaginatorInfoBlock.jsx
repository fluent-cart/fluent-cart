import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps } = wp.blockEditor;
const ProductPaginatorInfoBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fct-product-paginator-info fluent-cart-shop-app-paginator-selector-wrap',
        });

        return <div { ...props } {...blockProps}>{blocktranslate('( Showing 1 to 10 of 34 Items ) 10 Per page')}</div>;
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
            text: true
        }
    }
};

export default ProductPaginatorInfoBlock;
