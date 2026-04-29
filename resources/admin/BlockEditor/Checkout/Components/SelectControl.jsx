import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {RichText} = wp.blockEditor;

const SelectControl = ({ label, required = false, onLabelChange }) => {
    return (
        <div className="fluent-cart-input-control">
            <select>
                <option value="">{blocktranslate('Select country')}</option>
                <option value="option1">{blocktranslate('Option 1')}</option>
                <option value="option2">{blocktranslate('Option 2')}</option>
            </select>
        </div>
    );
};

export default SelectControl;
