let rangeInstanceCount = 0;

/**
 * A labelled slider for a whole number from min to max, with its value in a box beside it.
 * zeroLabel is shown for the minimum, for a setting where that means "use the default".
 */
const RangeField = ({label, value, min, max, onChange, zeroLabel}) => {
    const [labelId] = wp.element.useState(() => 'fct-range-field-label-' + (++rangeInstanceCount));
    // A stored value that is missing or outside the range counts as the nearest end.
    const current = Number.isFinite(value) ? Math.min(max, Math.max(min, value)) : min;
    const text = zeroLabel !== undefined && current === min ? zeroLabel : String(current);

    return (
        <div className="fct-range-field">
            <span id={labelId} className="fct-sidebar-label">{label}</span>
            <div className="fct-range-field__row">
                <input
                    type="range"
                    className="fct-range-field__slider"
                    min={min}
                    max={max}
                    step={1}
                    value={current}
                    aria-labelledby={labelId}
                    aria-valuetext={text}
                    style={{'--fct-range-fill': ((current - min) / (max - min)) * 100 + '%'}}
                    onChange={(event) => onChange(parseInt(event.target.value, 10))}
                />
                {/* The slider already speaks the value; this is its visible copy. */}
                <output className="fct-range-field__value" aria-hidden="true">{text}</output>
            </div>
        </div>
    );
};

export default RangeField;
