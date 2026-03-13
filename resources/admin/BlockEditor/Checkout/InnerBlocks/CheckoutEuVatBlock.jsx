import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps } = wp.blockEditor;

const CheckoutEuVatBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fc-checkout-eu-vat-block',
        });

        return <div {...props} {...blockProps}>
            <div className="fc-checkout-section-title">
                {blocktranslate('EU VAT')}
            </div>
            <div className="fct_tax_field" style={{marginTop: '8px'}}>
                <div className="fct_tax_input_wrapper" style={{
                    display: 'flex',
                    gap: '8px',
                    alignItems: 'center'
                }}>
                    <input
                        type="text"
                        placeholder={blocktranslate('Enter Tax ID')}
                        disabled
                        style={{
                            flex: 1,
                            padding: '8px 12px',
                            border: '1px solid #ddd',
                            borderRadius: '4px',
                            backgroundColor: '#f9f9f9'
                        }}
                    />
                    <button
                        disabled
                        style={{
                            padding: '8px 16px',
                            border: '1px solid #ddd',
                            borderRadius: '4px',
                            backgroundColor: '#f0f0f0',
                            cursor: 'default'
                        }}
                    >
                        {blocktranslate('Apply')}
                    </button>
                </div>
            </div>
        </div>;
    },
    save: () => null,
};

export default CheckoutEuVatBlock;
