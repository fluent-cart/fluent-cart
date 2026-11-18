import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {moveRadioFocusWithKeys} from "@/BlockEditor/Components/radioGroupKeyboardNavigation";
import SettingSwitch, {withBoldValue} from "@/BlockEditor/ProductSwatches/Components/SettingSwitch";
import {sampleRowSummary} from "@/BlockEditor/ProductSwatches/Components/CardPreview";

const POSITIONS = ['name', 'below'];

// List products used to follow the one shared show_label; a block saved before the split still does.
export const listShowsSelected = (attributes) => (
    typeof attributes.simple_show_selected === 'boolean' ? attributes.simple_show_selected : !!attributes.show_label
);

// Show the selected choice, per product type, and where: after the row's name or in one line below the rows.
const SwatchSelectedChoiceSetting = ({type, attributes, setAttributes, example, maxVisible}) => {
    const isAttribute = type === 'attribute';
    const shown = isAttribute ? !!attributes.show_label : listShowsSelected(attributes);
    const positionKey = isAttribute ? 'selected_position' : 'simple_selected_position';
    const position = attributes[positionKey] === 'below' ? 'below' : 'name';
    const id = 'fct-swatch-selected-' + type;
    // As on the card: the row's label comes first only while Show label is on.
    const labelShown = isAttribute ? attributes.show_group_name : attributes.simple_show_label;
    const afterName = (labelShown ? example.name + ' · ' : '') + example.values[0];

    // Attribute products own show_label now, so pin the list's value before changing it.
    const toggle = (checked) => setAttributes(isAttribute
        ? {show_label: checked, simple_show_selected: listShowsSelected(attributes)}
        : {simple_show_selected: checked});

    // A small card in each choice, drawn from this tab's settings like the preview card above: up to five
    // dots (as in the design), "+N" for the values How many to show hides, the label and the pick.
    const summary = sampleRowSummary(type, maxVisible, attributes.sold_out_display);
    const dots = summary.visible.slice(0, 5);
    const more = summary.hidden;
    /* translators: %s is the picked value of each row, e.g. Navy · S */
    const selectedLine = blocktranslate('Selected: %s', example.values.join(' · '));
    const samples = {
        name: {title: afterName, bottom: ''},
        below: {title: labelShown ? example.name : '', bottom: selectedLine},
    };

    return (
        <div className="fct-swatch-card-setting">
            <SettingSwitch
                id={id}
                title={blocktranslate('Show the selected choice')}
                /* translators: %s is an example, e.g. Color · Navy */
                description={withBoldValue(blocktranslate('e.g. %s'), afterName)}
                checked={shown}
                onChange={toggle}
            />

            {shown && (
                <div className="fct-swatch-setting-box">
                    <span id={id + '-where'} className="fct-swatch-section-label">{blocktranslate('Where it appears')}</span>
                    <div className="fct-swatch-position-choices" role="radiogroup" aria-labelledby={id + '-where'}>
                        {POSITIONS.map((choice, index) => (
                            <button
                                key={choice}
                                type="button"
                                role="radio"
                                aria-checked={position === choice}
                                tabIndex={position === choice ? 0 : -1}
                                className="fct-swatch-position-choice"
                                onClick={() => setAttributes({[positionKey]: choice})}
                                onKeyDown={(event) => {
                                    const next = moveRadioFocusWithKeys(event, index, POSITIONS.length);
                                    if (next !== null) {
                                        setAttributes({[positionKey]: POSITIONS[next]});
                                    }
                                }}
                            >
                                <span className="fct-swatch-position-sample" aria-hidden="true">
                                    {samples[choice].title && <span className="is-title">{samples[choice].title}</span>}
                                    <span className="fct-swatch-position-dots">
                                        {dots.map((option) => (
                                            <span key={option.value} className={isAttribute ? 'is-dot' : 'is-pill'} style={option.hex ? {background: option.hex} : undefined}/>
                                        ))}
                                        {more > 0 && <span className="is-more">+{more}</span>}
                                    </span>
                                    {samples[choice].bottom && <span className="is-below">{samples[choice].bottom}</span>}
                                </span>
                                <span className="fct-swatch-position-label">
                                    {choice === 'name' ? blocktranslate('After the label') : blocktranslate('Below the options')}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
};

export default SwatchSelectedChoiceSetting;
