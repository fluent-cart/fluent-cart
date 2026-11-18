import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {moveRadioFocusWithKeys} from "@/BlockEditor/Components/radioGroupKeyboardNavigation";

const CHOICES = ['normal', 'cross', 'hide'];

// Sold-out options, as in the swatches design: each choice drawn with an in-stock "M" beside a
// sold-out "XL", shown as today, crossed out, or left out. One setting for both product types.
const SwatchSoldOutSetting = ({type, attributes, setAttributes}) => {
    const current = CHOICES.includes(attributes.sold_out_display) ? attributes.sold_out_display : 'normal';
    const titleId = 'fct-swatch-sold-out-' + type;
    const labels = {
        normal: blocktranslate('No change'),
        cross: blocktranslate('Cross out'),
        hide: blocktranslate('Hide'),
    };

    return (
        <section className="fct-swatch-section">
            <span id={titleId} className="fct-swatch-switch-title">{blocktranslate('Sold-out options')}</span>
            <div className="fct-swatch-sold-out-choices" role="radiogroup" aria-labelledby={titleId}>
                {CHOICES.map((choice, index) => (
                    <button
                        key={choice}
                        type="button"
                        role="radio"
                        aria-checked={current === choice}
                        tabIndex={current === choice ? 0 : -1}
                        className="fct-swatch-sold-out-choice"
                        onClick={() => setAttributes({sold_out_display: choice})}
                        onKeyDown={(event) => {
                            const next = moveRadioFocusWithKeys(event, index, CHOICES.length);
                            if (next !== null) {
                                setAttributes({sold_out_display: CHOICES[next]});
                            }
                        }}
                    >
                        <span className="fct-swatch-sold-out-sample" aria-hidden="true">
                            <span>M</span>
                            {choice !== 'hide' && <span className={choice === 'cross' ? 'is-crossed' : ''}>XL</span>}
                        </span>
                        <span className="fct-swatch-sold-out-label">{labels[choice]}</span>
                    </button>
                ))}
            </div>
        </section>
    );
};

export default SwatchSoldOutSetting;
