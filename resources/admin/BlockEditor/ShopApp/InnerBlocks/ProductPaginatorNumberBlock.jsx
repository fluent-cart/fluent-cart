const { useBlockProps } = wp.blockEditor;
const ProductPaginatorNumberBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fct-product-paginator-number-wrap',
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
                <li className="fct-product-paginator-number-item arrow">
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
