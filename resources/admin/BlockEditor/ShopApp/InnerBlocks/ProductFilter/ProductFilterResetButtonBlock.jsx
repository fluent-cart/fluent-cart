const { useBlockProps } = wp.blockEditor;
const ProductFilterResetButtonBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fct-product-block-reset-button',
        });

        return <button {...props} {...blockProps}>
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none">
                <path
                    d="M14.2501 9.75V15.75M9.75012 9.75V15.75M20.2498 5.25L3.74976 5.25001M18.7501 5.25V19.5C18.7501 19.6989 18.6711 19.8897 18.5305 20.0303C18.3898 20.171 18.199 20.25 18.0001 20.25H6.00012C5.80121 20.25 5.61044 20.171 5.46979 20.0303C5.32914 19.8897 5.25012 19.6989 5.25012 19.5V5.25M15.7501 5.25V3.75C15.7501 3.35218 15.5921 2.97064 15.3108 2.68934C15.0295 2.40804 14.6479 2.25 14.2501 2.25H9.75012C9.3523 2.25 8.97077 2.40804 8.68946 2.68934C8.40816 2.97064 8.25012 3.35218 8.25012 3.75V5.25"
                    stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"
                    strokeLinejoin="round"></path>
            </svg>
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
            margin: true
        },
        color: {
            text: true
        }
    }
};

export default ProductFilterResetButtonBlock;
