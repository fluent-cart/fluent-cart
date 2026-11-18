const {useEffect, useRef, useState} = wp.element;

import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

// The renderer's cap; the number box never offers more than a card shows.
const MAX_COUNT = 12;

// The saved value as the renderer reads it: exactly 0 is All, anything else 1–12.
export const normalizeVisibleCount = (value) => (
    value === 0 || value === '0' ? 0 : Math.max(1, Math.min(MAX_COUNT, parseInt(value, 10) || 1))
);

// The block's own default for a tab, shown in the box while All is ticked and nothing was typed.
const blockDefault = (type) => {
    const attributes = wp.blocks?.getBlockType?.('fluent-cart/product-swatches')?.attributes || {};
    return normalizeVisibleCount(attributes[type === 'list' ? 'simple_max_visible' : 'max_visible']?.default ?? 3) || 1;
};

// Typing waits this long for the next digit, so 10 saves once, not 1 then 10.
const SAVE_DELAY = 400;

// One save waiting for typing to pause: a new one replaces it, cancel() drops it.
export const createPendingSave = (delay = SAVE_DELAY) => {
    let timer = null;
    return {
        schedule(save) {
            clearTimeout(timer);
            timer = setTimeout(save, delay);
        },
        cancel() {
            clearTimeout(timer);
            timer = null;
        },
    };
};

// Show up to, as in the swatches design: a number box and an All checkbox beside it. All saves 0;
// unticking it brings back the last number, which the inspector keeps across tab switches.
const SwatchCountSetting = ({type, value, lastNumber, onLastNumber, onChange}) => {
    const current = normalizeVisibleCount(value);
    const isAll = current === 0;
    const restore = lastNumber || blockDefault(type);
    const [draft, setDraft] = useState(null);
    const pending = useRef(null);
    pending.current = pending.current || createPendingSave();
    const id = 'fct-swatch-count-' + type;

    // A value saved elsewhere (undo, a pattern) replaces what is being typed, and becomes the number to
    // restore. A save still waiting for the typing to pause is dropped, or it would overwrite that value.
    useEffect(() => {
        pending.current.cancel();
        setDraft(null);
        if (!isAll) {
            onLastNumber(current);
        }
    }, [current, isAll]);
    useEffect(() => () => pending.current.cancel(), []);

    const save = (text) => {
        pending.current.cancel();
        const number = parseInt(text, 10);
        // Cleared or 0 (0 would mean All): nothing to save; the box shows the saved number again.
        if (!(number >= 1)) {
            setDraft(null);
            return;
        }
        setDraft(null);
        onChange(Math.min(MAX_COUNT, number));
    };

    return (
        <div className="fct-swatch-card-setting">
            <div className="fct-swatch-count-row">
                <div className="fct-swatch-switch-text">
                    <label id={id + '-title'} htmlFor={id} className="fct-swatch-switch-title">{blocktranslate('Show up to')}</label>
                    <span id={id + '-help'} className="fct-swatch-switch-description">
                        {isAll ? blocktranslate('Every option shows on the card') : blocktranslate('Extras show as "+2", "+3"…')}
                    </span>
                </div>
                <div className="fct-swatch-count-controls">
                    <input
                        id={id}
                        type="number"
                        className="fct-swatch-count-input"
                        min={1}
                        max={MAX_COUNT}
                        step={1}
                        disabled={isAll}
                        aria-describedby={id + '-help'}
                        value={draft !== null ? draft : (isAll ? restore : current)}
                        onChange={(event) => {
                            const text = event.target.value;
                            setDraft(text);
                            pending.current.schedule(() => save(text));
                        }}
                        onBlur={(event) => draft !== null && save(event.target.value)}
                        onKeyDown={(event) => event.key === 'Enter' && save(event.currentTarget.value)}
                    />
                    <label className="fct-swatch-count-all">
                        <input
                            type="checkbox"
                            checked={isAll}
                            aria-describedby={id + '-title ' + id + '-help'}
                            onChange={(event) => {
                                pending.current.cancel();
                                setDraft(null);
                                onChange(event.target.checked ? 0 : restore);
                            }}
                        />
                        <span>{blocktranslate('All')}</span>
                    </label>
                </div>
            </div>
        </div>
    );
};

export default SwatchCountSetting;
