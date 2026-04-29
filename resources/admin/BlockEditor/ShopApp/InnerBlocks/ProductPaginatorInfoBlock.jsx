import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useContext} = wp.element;
const { useBlockProps } = wp.blockEditor;
const ProductPaginatorInfoBlock = {
    usesContext: [
        'fluent-cart/paginator'
    ],
    edit: (props) => {
        const { context } = props;

        const paginator = context['fluent-cart/paginator'];
        let isShown = paginator === 'numbers' ? 'show' : '';

        const blockProps = useBlockProps({
            className: 'fct-product-paginator-info' + ' ' + isShown,
        });

        return <div { ...props } {...blockProps}>
            { blocktranslate('( Showing 1 to 10 of 34 Items ) 10 Per page')}
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
            text: true
        }
    }
};

export default ProductPaginatorInfoBlock;
