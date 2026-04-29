import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps,RichText } = wp.blockEditor;

const CheckoutBillingAddressFieldBlock = {
    attributes: {
        addressTitle: {
            type: 'string',
            default: blocktranslate('Billing Address')
        }
    },
    edit: (props) => {
        const { attributes, setAttributes } = props;

        const blockProps = useBlockProps();

        return <div { ...props } {...blockProps}>
            <div className="fc-checkout-section-title">
                <RichText
                    tagName="span"
                    value={attributes.addressTitle}
                    onChange={(value) => setAttributes({ addressTitle: value })}
                    placeholder={blocktranslate('Address')}
                    allowedFormats={['core/bold', 'core/italic']}
                />
            </div>
            <div className="fc-checkout-address-fields-block-row">
                <select disabled>
                    <option value="">{blocktranslate('Select a Country')}</option>
                </select>
                <input type="text" readOnly disabled placeholder={blocktranslate('House number and street name *')}/>
                <input type="text" readOnly disabled placeholder={blocktranslate('Apartment, suite, unit, etc. (optional)')}/>
                <select disabled>
                    <option value="">{blocktranslate('State')}</option>
                </select>
                <div className="two-column">
                    <input type="text" readOnly disabled placeholder={blocktranslate('Town / City')}/>
                    <input type="text" readOnly disabled placeholder={blocktranslate('Postcode / ZIP')}/>
                </div>
                <input type="text" readOnly disabled placeholder={blocktranslate('Phone Number')}/>
            </div>
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
    },
    category: "fluent-cart"
};

export default CheckoutBillingAddressFieldBlock;
