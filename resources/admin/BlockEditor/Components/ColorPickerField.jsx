import {Cross, ColorPickerIcon} from "../Icons";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {
    Button,
    Dropdown,
    ColorPicker
} = wp.components

const {
    Fragment
} = wp.element


export const ColorPickerField = props => {

    const {label, value, onChange} = props;

    const clearColor = () => {
        onChange(null); // Reset color to default state (e.g., empty string)
    };

    return (
        <div className="fct-color-picker-control-wrap">
            <span className="label">{label}</span>
            <div className="fct-color-picker-control-action-wrap">
                <Dropdown
                    popoverProps={ { placement: 'bottom-start' } }
                    renderToggle={({isOpen, onToggle}) => (
                        <Fragment>
                            <div className="fct-color-picker-control-action">
                                <div
                                    className={`picker-button ${value ? 'has-color' : ''}`}
                                    style={{backgroundColor: value}}
                                    onClick={onToggle}
                                    aria-expanded={isOpen}
                                >
                                    {!value && <ColorPickerIcon/>}
                                </div>

                                <span className={`picker-text ${value ? 'has-color' : ''}`}>
                                    {value ? value : blocktranslate('Add...')}
                                </span>

                                {value &&
                                    <span className="picker-remove" onClick={clearColor}><Cross/></span>
                                }

                            </div>
                        </Fragment>
                    )}
                    renderContent={() => (
                            <div>
                                <ColorPicker
                                    enableAlpha
                                    color={value}
                                    onChange={onChange}
                                />
                                <Button onClick={clearColor} variant="secondary" size="compact">{blocktranslate('Clear')}</Button>
                            </div>
                        )
                    }
                />
            </div>
        </div>
    )
}
