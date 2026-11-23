import {moveRadioFocusWithKeys} from "@/BlockEditor/Components/radioGroupKeyboardNavigation";

let toggleInstanceCount = 0;

/**
 * A labelled two-or-more way switch for the sidebar: a radio group drawn as joined buttons.
 * options: [{value, label}]. Arrow keys, Home and End move the selection. help: a note read with the group.
 */
const SegmentedToggle = ({label, options, value, onChange, help}) => {
    const [labelId] = wp.element.useState(() => 'fct-segmented-toggle-label-' + (++toggleInstanceCount));
    // With no option checked (a stored value outside the options) the first one stays reachable by keyboard.
    const hasChecked = options.some((option) => option.value === value);
    const helpId = labelId + '-help';

    return (
        <div className="fct-segmented-toggle-field">
            <span id={labelId} className="fct-sidebar-label">{label}</span>
            <div className="fct-segmented-toggle" role="radiogroup" aria-labelledby={labelId} aria-describedby={help ? helpId : undefined}>
                {options.map((option, index) => {
                    const checked = option.value === value;
                    return (
                        <button
                            key={option.value}
                            type="button"
                            role="radio"
                            aria-checked={checked}
                            tabIndex={checked || (!hasChecked && index === 0) ? 0 : -1}
                            className="fct-segmented-toggle__option"
                            onClick={() => onChange(option.value)}
                            onKeyDown={(event) => {
                                const next = moveRadioFocusWithKeys(event, index, options.length);
                                if (next !== null) {
                                    onChange(options[next].value);
                                }
                            }}
                        >
                            {option.label}
                        </button>
                    );
                })}
            </div>
            {help && <p id={helpId} className="fct-sidebar-help">{help}</p>}
        </div>
    );
};

export default SegmentedToggle;
