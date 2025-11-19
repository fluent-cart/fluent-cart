import {ColorPickerField} from "@/BlockEditor/Components/ColorPickerField";
import BlockEditorControl from "@/BlockEditor/Components/BlockEditorControl";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useEffect} = wp.element;

const {
    ColorPicker,
    __experimentalRadio: Radio,
    __experimentalRadioGroup: RadioGroup,
    RangeControl
} = wp.components

const Styler = (props) => {
    const {attributes, setAttributes, blockEditorData} = props;

    const setColor = (name, value) => {
        let colors = attributes.colors ?? {};
        colors[name] = value
        setAttributes({colors: {...colors}});

        // Apply the color to the :root
        document.documentElement.style.setProperty(name, value);
    }

    const getColor = (name) => {

        if (typeof attributes.colors == "undefined" || typeof attributes.colors[name] == "undefined") {
            return '';
        }
        return attributes.colors[name];
    }

    useEffect(() => {
        // Apply initial colors
        if (attributes.colors) {
            for (const [name, value] of Object.entries(attributes.colors)) {
                document.documentElement.style.setProperty(name, value);
            }
        }
    }, [attributes.colors]);

    const availableColors = {
        'Global': {
            '--shop-app-primary-color': blocktranslate("Primary"),
            '--shop-app-title-color': blocktranslate("Title"),
            '--shop-app-sub-title-color': blocktranslate("Sub Title"),
            '--shop-app-text-color': blocktranslate("Text"),
            '--shop-app-border-outline': blocktranslate("Border"),
            '--shop-app-border-divider': blocktranslate("Divider"),
        },
        'Button': {
            '--button-bg': blocktranslate("Background"),
            '--button-color': blocktranslate("Text Color"),
            '--button-hover-bg': blocktranslate("Hover Background"),
            '--button-hover-color': blocktranslate("Hover Color"),
        },
        'Badge': {
            '--badge-count-bg': blocktranslate("Background"),
            '--badge-count-color': blocktranslate("Text Color"),
            '--badge-count-border-color': blocktranslate("Border Color"),
        },
        'View Switcher': {
            '--view-switcher-button-active-bg': blocktranslate("Background"),
            '--view-switcher-button-color': blocktranslate("Color"),
            '--view-switcher-button-active-color': blocktranslate("Active Color"),
        },
        'Product Card': {
            '--product-card-bg': blocktranslate("Background"),
            '--product-card-border-color': blocktranslate("Border Color"),
            '--product-card-title-color': blocktranslate("Title Color"),
            '--product-card-text-color': blocktranslate("Text Color"),
            '--product-card-price-color': blocktranslate("Price Color"),
            '--product-card-title-hover-color': blocktranslate("Title Hover Color"),
        },
        'Filter': {
            '--filter-bg': blocktranslate("Filter Background"),
            '--filter-border-color': blocktranslate("Filter Border"),
            '--filter-input-border-color': blocktranslate("Input Border"),
            '--filter-input-focus-border-color': blocktranslate("Input Focus Border"),
            '--filter-title-color': blocktranslate("Title Color"),
            '--filter-item-divider-color': blocktranslate("Divider Color"),
            '--filter-reset-button-bg': blocktranslate("Reset Button Background"),
            '--filter-reset-button-color': blocktranslate("Reset Button Color"),
            '--filter-reset-button-border-color': blocktranslate("Reset Button Border"),
            '--filter-reset-button-hover-bg': blocktranslate("Reset Button Hover Bg"),
            '--filter-reset-button-hover-color': blocktranslate("Reset Button Hover Color"),
            '--filter-checkbox-text-color': blocktranslate("Checkbox Text Color"),
            '--filter-checkbox-active-text-color': blocktranslate("Checkbox Active Text Color"),
            '--filter-checkmark-bg': blocktranslate("Checkbox Background"),
            '--filter-checkmark-border-color': blocktranslate("Checkbox Border"),
            '--filter-checkmark-active-bg': blocktranslate("Checkbox Active Background"),
            '--filter-checkmark-check-border-color': blocktranslate("Checkmark Border"),
            '--filter-range-slider-connect-bg': blocktranslate("Range Slider Background")
        },


    }

    return (

        <div>
            {Object.keys(availableColors).map((label, index) => {
                return <div key={index}>
                    <BlockEditorControl title={label}>
                        {Object.keys(availableColors[label]).map((key, index2) => {
                            return (
                                <ColorPickerField
                                    key={index2}
                                    label={availableColors[label][key]}
                                    value={getColor(key)}
                                    onChange={(color) => {
                                        setColor(key, color);
                                    }}/>
                            );
                        })}
                    </BlockEditorControl>
                </div>
            })}


        </div>
    );
};
export default Styler;