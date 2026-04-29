import {Search} from "@/BlockEditor/Icons";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const Input = ({
    placeholder = blocktranslate("Search"),
    name,
    type = "text",
    onClick,
    onInput,
    onBlur,
    onKeyDown,
    icon = false
}) => {

    return (
        <div className="fct-input-group">
            {icon &&
                <div className="input-icon">
                    <Search/>
                </div>
            }
            <input
                className="fct-input"
                name={name}
                type={type}
                placeholder={placeholder}
                onClick={onClick}
                onInput={onInput}
                onBlur={onBlur}
                onKeyDown={onKeyDown}
            />
        </div>
    );
};

export default Input;
