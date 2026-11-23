import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

let stepperInstanceCount = 0;

/**
 * A labelled whole-number field with minus and plus buttons. Typing is kept as text and saved, clamped
 * to min and max, when the box loses focus or Enter is pressed; text that is not a number is dropped.
 */
const NumberStepper = ({label, value, min, max, onChange}) => {
    const {useState, useEffect} = wp.element;
    const [labelId] = useState(() => 'fct-number-stepper-label-' + (++stepperInstanceCount));
    // A stored value that is missing or not a number counts as the minimum.
    const current = Number.isFinite(value) ? value : min;
    const [draft, setDraft] = useState(String(current));
    useEffect(() => setDraft(String(current)), [current]);

    const clamp = (number) => Math.min(max, Math.max(min, number));

    const commitDraft = () => {
        const number = parseInt(draft, 10);
        if (Number.isNaN(number)) {
            setDraft(String(current));
            return;
        }
        const next = clamp(number);
        setDraft(String(next));
        if (next !== current) {
            onChange(next);
        }
    };

    return (
        <div className="fct-number-stepper-field">
            <span id={labelId} className="fct-sidebar-label">{label}</span>
            <div className="fct-number-stepper" role="group" aria-labelledby={labelId}>
                <button
                    type="button"
                    className="fct-number-stepper__button"
                    aria-label={blocktranslate('Decrease')}
                    disabled={current <= min}
                    onClick={() => onChange(clamp(current - 1))}
                >
                    −
                </button>
                <input
                    type="number"
                    inputMode="numeric"
                    className="fct-number-stepper__input"
                    aria-labelledby={labelId}
                    min={min}
                    max={max}
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    onBlur={commitDraft}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            commitDraft();
                        }
                    }}
                />
                <button
                    type="button"
                    className="fct-number-stepper__button"
                    aria-label={blocktranslate('Increase')}
                    disabled={current >= max}
                    onClick={() => onChange(clamp(current + 1))}
                >
                    +
                </button>
            </div>
        </div>
    );
};

export default NumberStepper;
