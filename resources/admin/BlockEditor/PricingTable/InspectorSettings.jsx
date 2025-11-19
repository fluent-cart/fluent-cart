import {ColorPickerField} from "@/BlockEditor/Components/ColorPickerField";
import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import {colorConfig} from "@/BlockEditor/PricingTable/colorConfig";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";

const {
    InspectorControls,
} = wp.blockEditor;

const {
    ToggleControl,
    TextControl,
    Button,
    RangeControl,
    Tooltip
} = wp.components;


const InspectorSettings = (props) => {
    const {
        attributes,
        setAttributes,
        onChangeVariationGroup
    } = props;

    const badgeColors = Object.entries(attributes.colors.badgeColors);
    const cardColors = Object.entries(attributes.colors.cardColors);
    const generalColors = Object.entries(attributes.colors.generalColors);
    const tabColors = Object.entries(attributes.colors.tabColors);
    const defaultColors = colorConfig;

    const setBadgeAttribute = (key, value) => {
        setAttributes({
            badge: {
                ...attributes.badge,
                [key]: value
            }
        });
    }

    const setColorAttribute = (key, value, section) => {
        setAttributes({
            colors: {
                ...attributes.colors,
                [section]: {
                    ...attributes.colors[section],
                    [key]: {
                        ...attributes.colors[section][key],
                        value
                    }
                }
            }
        });
    };

    const resetColors = () => {
        // Initialize an empty object to store the reset color values
        const resetValues = {};

        // Loop through each color group (e.g., generalColors, badgeColors)
        Object.keys(defaultColors).forEach((section) => {
            // Create a new object in resetValues for each color group
            resetValues[section] = {};

            // Loop through each color key in the current color group
            Object.keys(defaultColors[section]).forEach((key) => {
                // Add each color key to resetValues with its label and an empty string for value
                resetValues[section][key] = {
                    ...defaultColors[section][key], // Preserve other properties, such as 'label'
                    value: '' // Reset the color value to an empty string
                };
            });
        });

        // Update the component's color attributes with the reset values, triggering a UI update
        setAttributes({ colors: resetValues });
    };


    const onChangeGroupBy = (value) => {
        setAttributes({group_by: value});
        onChangeVariationGroup(attributes.variationsData, value);
    }

    const setProductPerRowAttribute = (size) => {
        setAttributes({product_per_row: size});
    };

    const setButtonAttribute = (key, value) => {
        setAttributes({
            buttonOptions: {
                ...attributes.buttonOptions,
                [key]: value
            }
        });
    }

    return <InspectorControls>
        <div className="fct-inspector-control-wrap">

            <div className="fct-inspector-control-group">
                <div className="fct-inspector-control-heading">
                    {blocktranslate('Grouping')}
                </div>

                <div className="fct-inspector-control-body">
                    <div className="fct-inspector-control-settings">
                        <div className="fct-inspector-control-row flex-col">
                            <div className="fct-inspector-control-label">{blocktranslate('Price Grouping')}</div>

                            <CustomSelect
                                customKeys={{
                                    key: 'value',
                                    label: 'label'
                                }}
                                defaultValue={attributes.group_by} //repeat_interval,payment_type,none
                                options={[
                                    {label: blocktranslate('Repeat Interval'), value: 'repeat_interval'},
                                    {label: blocktranslate('Payment Type'), value: 'payment_type'},
                                    {label: blocktranslate('None'), value: 'none'},
                                ]}
                                onChange={function (value) {
                                    onChangeGroupBy(value)
                                }}
                            />
                        </div>
                    </div>

                    <div className="fct-inspector-control-settings">
                        <div className="fct-inspector-control-row">
                            <div className="fct-inspector-control-label">{blocktranslate('Product Per Row')}</div>
                            <RangeControl
                                value={attributes.product_per_row}
                                onChange={setProductPerRowAttribute}
                                min={0}
                                max={5}
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div className="fct-inspector-control-group">
                <div className="fct-inspector-control-heading">
                    {blocktranslate('Settings')}
                    <div className="fct-reset-button-wrap">
                        <Tooltip text={blocktranslate('Reset Colors')} delay={100}>
                            <Button onClick={resetColors} icon="update" size="small"/>
                        </Tooltip>
                    </div>
                </div>
                <div className="fct-inspector-control-body">
                    <EditorPanel title={blocktranslate('General')}>
                        <div className="fct-inspector-control-settings">
                            {generalColors.map(([key, {value, label}], index) => (
                                <div className="fct-inspector-control-row" key={index}>
                                    <ColorPickerField
                                        label={label}
                                        value={value}
                                        onChange={(newValue) => setColorAttribute(key, newValue, 'generalColors')}
                                    />
                                </div>
                            ))}
                        </div>
                    </EditorPanel>

                    <EditorPanel title={blocktranslate('Button')}>
                        <div className="fct-inspector-control-settings">

                            <div className="fct-inspector-control-row">
                                <div className="fct-inspector-control-label">{blocktranslate('Show Checkout Button')}</div>
                                <ToggleControl
                                    checked={attributes.show_checkout_button}
                                    onChange={(checked) => {
                                        setAttributes({show_checkout_button: checked});
                                    }}
                                />
                            </div>

                            {
                                attributes.show_checkout_button &&
                                <div>
                                    <div className="fct-inspector-control-row flex-col">
                                        <div className="fct-inspector-control-label">{blocktranslate('Checkout Button Text')}</div>
                                        <TextControl
                                            value={attributes.buttonOptions.text}
                                            onChange={(value) => setButtonAttribute('text', value)}
                                        />
                                    </div>
                                    <div className="fct-inspector-control-row flex-col">
                                        <div className="fct-inspector-control-label">{blocktranslate('Button Url Params')}</div>
                                        <TextControl
                                            value={attributes.checkout_button_url_params}
                                            onChange={(value) => setAttributes({checkout_button_url_params: value})}
                                        />
                                        <div className="fct-inspector-control-hint">
                                            {blocktranslate('Add any query params you want to pass to the checkout page. eg: utm_source=google&utm_medium=cpc')}
                                        </div>
                                    </div>
                                </div>
                            }

                            <hr/>

                            <div className="fct-inspector-control-row flex-col">
                                <div className="fct-inspector-control-label">{blocktranslate('Show Cart Button')}</div>
                                <ToggleControl
                                    checked={attributes.show_cart_button}
                                    onChange={(checked) => {
                                        setAttributes({show_cart_button: checked});
                                    }}
                                />
                            </div>

                            {
                                attributes.show_cart_button &&
                                <div className="fct-inspector-control-row flex-col">
                                    <div className="fct-inspector-control-label">{blocktranslate('Cart Button Text')}</div>
                                    <TextControl
                                        value={attributes.buttonOptions.cartButtonText}
                                        onChange={(value) => setButtonAttribute('cartButtonText', value)}
                                    />
                                    <div className="fct-inspector-control-hint">
                                        {blocktranslate('Add to cart button only will show for non subscription product')}
                                    </div>
                                </div>
                            }
                        </div>
                    </EditorPanel>

                    <EditorPanel title={blocktranslate('Badge')}>
                        <div className="fct-inspector-control-settings">
                            <div className="fct-inspector-control-row">
                                <div className="fct-inspector-control-label">{blocktranslate('Text')}</div>
                                <TextControl
                                    value={attributes.badge.text}
                                    onChange={(value) => setBadgeAttribute('text', value)}
                                />
                            </div>

                            <div className="fct-inspector-control-row flex-col">
                                <div className="fct-inspector-control-label">{blocktranslate('Position')}</div>
                                <CustomSelect
                                    options={[
                                        {label: blocktranslate('Center'), value: 'center'},
                                        {label: blocktranslate('Right'), value: 'right'},
                                        {label: blocktranslate('Left'), value: 'left'},
                                    ]}
                                    defaultValue={attributes.badge.position}
                                    onChange={(value) => setBadgeAttribute('position', value)}
                                    customKeys={{
                                        key: 'value',
                                        label: 'label'
                                    }}
                                />
                            </div>

                            {badgeColors.map(([key, {value, label}], index) => (
                                <div className="fct-inspector-control-row" key={index}>
                                    <ColorPickerField
                                        label={label}
                                        value={value}
                                        onChange={(newValue) => setColorAttribute(key, newValue, 'badgeColors')}
                                    />
                                </div>
                            ))}
                        </div>
                    </EditorPanel>

                    <EditorPanel title={blocktranslate('Card')}>
                        <div className="fct-inspector-control-settings">
                            <div className="fct-inspector-control-row">
                                <div className="fct-inspector-control-label">{blocktranslate('Show/Hide Icon')}</div>
                                <ToggleControl
                                    checked={attributes.iconVisibility}
                                    onChange={(checked) => {
                                        setAttributes({iconVisibility: checked});
                                    }}
                                />
                            </div>
                            {cardColors.map(([key, {value, label}], index) => (
                                <div className="fct-inspector-control-row" key={index}>
                                    <ColorPickerField
                                        label={label}
                                        value={value}
                                        onChange={(newValue) => setColorAttribute(key, newValue, 'cardColors')}
                                    />
                                </div>
                            ))}
                        </div>
                    </EditorPanel>

                    <EditorPanel title={blocktranslate('Tab')}>
                        <div className="fct-inspector-control-settings">
                            {tabColors.map(([key, {value, label}], index) => (
                                <div className="fct-inspector-control-row" key={index}>
                                    <ColorPickerField
                                        label={label}
                                        value={value}
                                        onChange={(newValue) => setColorAttribute(key, newValue, 'tabColors')}
                                    />
                                </div>
                            ))}
                        </div>
                    </EditorPanel>
                </div>
            </div>
        </div>
    </InspectorControls>
}
export default InspectorSettings;
