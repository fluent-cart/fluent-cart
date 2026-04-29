import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import { ProductContainerContext } from "@/BlockEditor/ShopApp/Context/ProductContainerContext";
const {useContext} = wp.element;


const {useBlockProps, InnerBlocks} = wp.blockEditor;

const DEFAULT_TEMPLATE = [
    [
        'fluent-cart/shopapp-product-spinner'
    ],
];

const ProductLoaderBlock = {
    attributes: {},
    usesContext: [
        'fluent-cart/paginator',
        'fluent-cart/enable_filter',
    ],
    edit: (props) => {
        const { simulateLoading } = useContext(ProductContainerContext);

        let isShown = simulateLoading ? 'show' : '';

        const blockProps = useBlockProps({
            className: 'fluent-cart-product-loader' + ' ' + isShown,
        });

        return <div {...blockProps} >
            <InnerBlocks template={DEFAULT_TEMPLATE} />
        </div>;
    },

    save: (props) => {
        const blockProps = useBlockProps.save();
        return <div {...blockProps} className="fluent-cart-product-loader">
            <InnerBlocks.Content/>
        </div>;
    },
};

export default ProductLoaderBlock;
