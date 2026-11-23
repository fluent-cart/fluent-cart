const {CheckboxControl} = wp.components;

let groupInstanceCount = 0;

/**
 * A labelled row of checkboxes that each switch on their own.
 * options: [{value, label, checked}]. onToggle(value, checked) gets the new state.
 */
const CheckboxGroup = ({label, options, onToggle}) => {
    const [labelId] = wp.element.useState(() => 'fct-checkbox-group-label-' + (++groupInstanceCount));

    return (
        <div className="fct-checkbox-group-field">
            <span id={labelId} className="fct-sidebar-label">{label}</span>
            <div className="fct-checkbox-group" role="group" aria-labelledby={labelId}>
                {options.map((option) => (
                    <CheckboxControl
                        key={option.value}
                        label={option.label}
                        checked={option.checked}
                        onChange={(checked) => onToggle(option.value, checked)}
                        __nextHasNoMarginBottom
                    />
                ))}
            </div>
        </div>
    );
};

export default CheckboxGroup;
