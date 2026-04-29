const { useBlockProps } = wp.blockEditor;
const ProductPaginatorNumberBlock = {
    usesContext: [
        'fluent-cart/paginator'
    ],
    edit: (props) => {
        const { context } = props;

        const paginator = context['fluent-cart/paginator'];
        let isShown = paginator === 'numbers' ? 'show' : '';

        const blockProps = useBlockProps({
            className: 'fct-product-paginator-number-wrap' + ' ' + isShown,
        });

        return <div {...props} {...blockProps}>
            <ul className="fct-product-paginator-number-items">
                <li className="fct-product-paginator-number-item active">1
                </li>
                <li className="fct-product-paginator-number-item">2
                </li>
                <li className="fct-product-paginator-number-item">3
                </li>
                <li className="fct-product-paginator-number-item">4
                </li>
                <li className="fct-product-paginator-number-item">
                    --
                </li>
            </ul>
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

export default ProductPaginatorNumberBlock;
