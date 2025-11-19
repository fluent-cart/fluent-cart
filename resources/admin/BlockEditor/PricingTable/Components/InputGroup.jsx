import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const InputGroup = ({ placeholder = blocktranslate("Search"), openPopup }) => {
    return (
        <div className="fct-input-group">
            <div className="input-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path
                        d="M11.6667 11.668L14.6667 14.668"
                        stroke="currentColor"
                        strokeWidth="1.25"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                    <path
                        d="M13.3334 7.33398C13.3334 4.02028 10.6471 1.33398 7.33337 1.33398C4.01967 1.33398 1.33337 4.02028 1.33337 7.33398C1.33337 10.6477 4.01967 13.334 7.33337 13.334C10.6471 13.334 13.3334 10.6477 13.3334 7.33398Z"
                        stroke="currentColor"
                        strokeWidth="1.25"
                        strokeLinejoin="round"
                    />
                </svg>
            </div>
            <input
                className="fct-input"
                placeholder={placeholder}
                onClick={openPopup}
            />
        </div>
    );
};

export default InputGroup;
