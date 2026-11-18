const {useMemo, useState} = wp.element;

import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {normalizeVisibleCount} from "@/BlockEditor/ProductSwatches/Components/SwatchCountSetting";
import {nextRadioIndex} from "@/BlockEditor/Components/radioGroupKeyboardNavigation";

// Sample products: the sidebar has no product of its own, so these show what the settings do.
const sampleRows = (type) => (type === 'attribute' ? [
    {name: blocktranslate('Color'), options: [
        {value: blocktranslate('Navy'), hex: '#2f3e63'},
        {value: blocktranslate('Mustard'), hex: '#c9a23a'},
        {value: blocktranslate('Sage'), hex: '#6f9a7a'},
        {value: blocktranslate('Rust'), hex: '#b45a3c', soldOut: true},
        {value: blocktranslate('Sand'), hex: '#d9c7a7'},
        {value: blocktranslate('Plum'), hex: '#6b3f5e'},
    ]},
    {name: blocktranslate('Size'), options: ['S', 'M', 'L', 'XL'].map((value) => ({value, soldOut: value === 'XL'}))},
] : [
    {name: '', options: ['6', '12', '24', '48'].map((count) => ({value: blocktranslate('%s pods', count), soldOut: count === '48'}))
        .concat({value: blocktranslate('Sampler')})},
]);

// The sample's first row name and the value each row starts on, for the settings' examples.
export const previewExample = (type, rowName = '') => {
    const rows = sampleRows(type);
    return {
        name: type === 'attribute' ? rows[0].name : (rowName || blocktranslate('Option')),
        values: rows.map((row) => row.options[0].value),
    };
};

// The first sample row as a card with these settings shows it: Hide drops sold-out values, then How many
// to show keeps the first ones and counts the rest as "+N". The preview card and the position samples share it.
export const sampleRowSummary = (type, maxVisible, soldOutDisplay = 'normal') => {
    const options = sampleRows(type)[0].options.filter((option) => soldOutDisplay !== 'hide' || !option.soldOut);
    const count = normalizeVisibleCount(maxVisible);
    const visible = count === 0 ? options : options.slice(0, count);
    return {visible, hidden: options.length - visible.length};
};

const TeeIcon = ({fill}) => (
    <svg width="44" height="44" viewBox="0 0 120 120" aria-hidden="true">
        <path d="M40 18 L28 22 L8 42 L20 56 L30 48 L30 104 L90 104 L90 48 L100 56 L112 42 L92 22 L80 18 Q60 32 40 18 Z" fill={fill}/>
    </svg>
);

const PodsIcon = () => (
    <svg width="44" height="44" viewBox="0 0 120 120" aria-hidden="true">
        <rect x="22" y="34" width="76" height="60" rx="8" fill="#8a5a3b"/>
        <rect x="22" y="34" width="76" height="12" rx="6" fill="#5b3a25"/>
        <circle cx="44" cy="70" r="9" fill="#f5efe6"/>
        <circle cx="76" cy="70" r="9" fill="#f5efe6"/>
    </svg>
);

// A card drawn from the tab's current settings: how many options, names and the selected choice.
// Each row is one tab stop, so trying the card does not crowd the settings below it.
const CardPreview = ({type, enabled, maxVisible, showName, rowName, showSelected, selectedBelow, soldOutDisplay = 'normal'}) => {
    const rows = useMemo(() => sampleRows(type), [type]);
    // Like the renderer: 0 shows every option, other values stay within 1–12, and each row starts on its first shown value.
    const count = normalizeVisibleCount(maxVisible);
    const limit = count === 0 ? Infinity : count;
    const [picked, setPicked] = useState({});
    // Sold-out samples (Rust, XL, 48 pods) follow the Sold-out options setting, as on the card.
    const crossed = (option) => soldOutDisplay === 'cross' && !!option.soldOut;
    const shownRows = rows.map((row, rowIndex) => {
        const options = soldOutDisplay === 'hide' ? row.options.filter((option) => !option.soldOut) : row.options;
        const visible = options.slice(0, limit);
        const current = visible.find((option) => option.value === picked[rowIndex] && !crossed(option))
            || visible.find((option) => !crossed(option)) || visible[0];
        return {...row, visible, current, hidden: options.length - visible.length};
    });
    const isAttribute = type === 'attribute';

    return (
        <div className="fct-swatch-preview">
            <div
                className="fct-swatch-preview-card"
                role="group"
                aria-label={blocktranslate('Card preview')}
            >
                <div className={'fct-swatch-preview-image is-' + type} aria-hidden="true">
                    {isAttribute ? <TeeIcon fill={enabled ? shownRows[0].current.hex : '#d6d3cd'}/> : <PodsIcon/>}
                </div>
                <div className="fct-swatch-preview-details">
                    <span className="fct-swatch-preview-name">
                        {isAttribute ? blocktranslate('Classic Tee') : blocktranslate('Espresso Pods')}
                    </span>
                    {!enabled ? (
                        <span className="fct-swatch-preview-off">{blocktranslate('Off: no options on the card.')}</span>
                    ) : shownRows.map((row, rowIndex) => {
                        const name = isAttribute ? row.name : (rowName || blocktranslate('Option'));
                        const title = [showName ? name : '', showSelected && !selectedBelow ? row.current.value : ''].filter(Boolean).join(' · ');

                        return (
                            <div key={rowIndex} className="fct-swatch-preview-row">
                                {title && <span className="fct-swatch-preview-title" aria-hidden="true">{title}</span>}
                                <div className="fct-swatch-preview-options" role="radiogroup" aria-label={name}>
                                    {row.visible.map((option, index) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            role="radio"
                                            tabIndex={row.current === option ? 0 : -1}
                                            className={'fct-swatch-preview-option' + (option.hex ? ' is-dot' : '') + (crossed(option) ? ' is-sold-out' : '')}
                                            style={option.hex ? {background: option.hex} : undefined}
                                            aria-label={crossed(option)
                                                /* translators: %s is an attribute value, e.g. XL */
                                                ? blocktranslate('%s, sold out', option.value)
                                                : (option.hex ? option.value : undefined)}
                                            aria-checked={row.current === option}
                                            aria-disabled={crossed(option) || undefined}
                                            onClick={() => !crossed(option) && setPicked((prev) => ({...prev, [rowIndex]: option.value}))}
                                            onKeyDown={(event) => {
                                                // Crossed-out samples are skipped, like disabled radio buttons.
                                                let next = nextRadioIndex(event, index, row.visible.length);
                                                for (let step = 0; next !== null && crossed(row.visible[next]) && step < row.visible.length; step++) {
                                                    next = nextRadioIndex({...event, key: event.key === 'Home' ? 'ArrowRight' : (event.key === 'End' ? 'ArrowLeft' : event.key), currentTarget: event.currentTarget}, next, row.visible.length);
                                                }
                                                if (next === null || crossed(row.visible[next])) {
                                                    return;
                                                }
                                                event.preventDefault();
                                                setPicked((prev) => ({...prev, [rowIndex]: row.visible[next].value}));
                                                event.currentTarget.closest('[role="radiogroup"]').querySelectorAll('[role="radio"]')[next].focus();
                                            }}
                                        >
                                            {option.hex ? null : option.value}
                                        </button>
                                    ))}
                                    {row.hidden > 0 && (
                                        <span className="fct-swatch-preview-more">
                                            <span aria-hidden="true">+{row.hidden}</span>
                                            <span className="screen-reader-text">{blocktranslate('%s more', row.hidden)}</span>
                                        </span>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                    {enabled && showSelected && selectedBelow && (
                        <span className="fct-swatch-preview-selected" aria-hidden="true">
                            {/* translators: %s is the picked value of each row, e.g. Navy · S */}
                            {blocktranslate('Selected: %s', shownRows.map((row) => row.current.value).join(' · '))}
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
};

export default CardPreview;
