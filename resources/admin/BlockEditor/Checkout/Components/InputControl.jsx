import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
const {RichText} = wp.blockEditor;


const InputControl = ({ label, type = 'text', required = false, onLabelChange }) => {
    return (
        <>
            {type === 'textarea' ? (
                <div className="fluent-cart-input-control">
                    <textarea placeholder={blocktranslate("Notes about your order, e.g. Leave it at my doorstep.")}></textarea>
                </div>
            ) : (
                <div className="fluent-cart-input-control">
                    <input type="text"/>
                    <label>
                        <RichText
                            tagName="span"
                            value={label}
                            onChange={onLabelChange}
                        />
                        {required && <span className="required">*</span>}
                    </label>
                </div>
            )}
        </>
    );
};

export default InputControl;
