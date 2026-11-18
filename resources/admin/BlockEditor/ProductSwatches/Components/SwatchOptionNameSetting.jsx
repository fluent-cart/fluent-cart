const {TextControl} = wp.components;

import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import SettingSwitch, {withBoldValue} from "@/BlockEditor/ProductSwatches/Components/SettingSwitch";

const NAME_PRESETS = () => [blocktranslate('Pack'), blocktranslate('Plan'), blocktranslate('Size'), blocktranslate('Bundle')];

// Show label: a small heading above each row. Attribute rows use each attribute's own name;
// the list row uses the text typed here, "Option" when empty.
const SwatchOptionNameSetting = ({type, attributes, setAttributes}) => {
    const isAttribute = type === 'attribute';
    const key = isAttribute ? 'show_group_name' : 'simple_show_label';
    const shown = attributes[key];
    const rowName = attributes.simple_label || '';
    const id = 'fct-swatch-option-name-' + type;
    const example = isAttribute ? blocktranslate('Color') : (rowName || blocktranslate('Option'));

    return (
        <div className="fct-swatch-card-setting">
            <SettingSwitch
                id={id}
                title={blocktranslate('Show label')}
                /* translators: %s is an example label, e.g. Color */
                description={withBoldValue(blocktranslate('e.g. %s above the row'), example)}
                checked={shown}
                onChange={(checked) => setAttributes({[key]: checked})}
            />

            {shown && !isAttribute && (
                <div className="fct-swatch-option-name-text fct-swatch-setting-box">
                    <label htmlFor={id + '-text'} className="fct-swatch-section-label">
                        {blocktranslate('Label name')}
                    </label>
                    <TextControl
                        id={id + '-text'}
                        value={rowName}
                        placeholder={blocktranslate('Option')}
                        onChange={(value) => setAttributes({simple_label: value})}
                    />
                    <div className="fct-swatch-option-name-presets">
                        {NAME_PRESETS().map((preset) => (
                            <button
                                key={preset}
                                type="button"
                                className="fct-swatch-option-name-preset"
                                aria-pressed={rowName === preset}
                                onClick={() => setAttributes({simple_label: preset})}
                            >
                                {preset}
                            </button>
                        ))}
                    </div>
                    <span className="fct-swatch-section-help">
                        {blocktranslate('Empty shows "Option".')}
                    </span>
                </div>
            )}
        </div>
    );
};

export default SwatchOptionNameSetting;
