const { useBlockProps } = wp.blockEditor;
const ProductFilterApplyButtonBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fct-product-block-filter-button',
        });

        return <button {...props} {...blockProps}>Apply Filter</button>;
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

export default ProductFilterApplyButtonBlock;
