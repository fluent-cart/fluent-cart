import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import InputControl from "./InputControl";
import SelectControl from "./SelectControl";
const { RichText } = wp.blockEditor;

const AddressCard = ({
                         heading,
                         onHeadingChange,
                         className = 'billing-address',
                         type = 'billing',
                         labels = {},
                         updateLabel
                     }) => {

    const renderInput = (fieldKey, label, required = false) => {
        const namespacedKey = `${type}_${fieldKey}`;
        return (
            <InputControl
                key={namespacedKey}
                label={labels[namespacedKey] || label}
                required={required}
                onLabelChange={(val) => updateLabel(type, fieldKey, val)}
            />
        );
    };

    const renderSelect = (fieldKey, label, required = false) => {
        const namespacedKey = `${type}_${fieldKey}`;
        return (
            <SelectControl
                key={namespacedKey}
                label={labels[namespacedKey] || label}
                required={required}
                onLabelChange={(val) => updateLabel(type, fieldKey, val)}
            />
        );
    };

    return (
        <div className={`fluent-cart-checkout-block-editor-card ${className}`}>
            <div className="fluent-cart-checkout-block-editor-card-header">
                <h3 className="fluent-cart-checkout-block-editor-card-title">
                    <RichText
                        tagName="span"
                        value={heading}
                        onChange={(val) => onHeadingChange(type, val)}
                    />
                </h3>
            </div>
            <div className="fluent-cart-checkout-block-editor-card-body">
                <div className="fluent-cart-checkout-block-editor-input-group">
                    {type === 'shipping' && renderInput('full_name', blocktranslate('Full Name'), true)}
                    {renderInput('phone', blocktranslate('Phone'))}
                    {renderInput('company_name', blocktranslate('Company Name'))}

                    {renderSelect('country', blocktranslate('Country / Region'), true)}

                    {renderInput('address_1', blocktranslate('Full Address'), true)}

                    <div className="fluent-cart-checkout-block-editor-input-group-inner">
                        {renderInput('city', blocktranslate('Town / City'), true)}
                        {renderInput('postcode', blocktranslate('Postcode'), true)}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default AddressCard;
