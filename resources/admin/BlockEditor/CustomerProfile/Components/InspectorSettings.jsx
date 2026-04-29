const { InspectorControls } = wp.blockEditor;
const { TextControl, ToggleControl, Tooltip, Button, BoxControl } = wp.components;
import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {ColorPickerField} from "@/BlockEditor/Components/ColorPickerField";
import colorConfig from "@/BlockEditor/CustomerProfile/colorConfig";

const InspectorSettings = (props) => {
    const {attributes, setAttributes} = props;

    const generalColors = Object.entries(attributes.colors.generalColors);
    const navColors = Object.entries(attributes.colors.navColors);
    const defaultColors = colorConfig;


    const setColorAttribute = (key, value, section) => {
        console.log(key, value, section);
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


    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Section Titles')}>
                            <EditorPanelRow className="flex-col">
                                {Object.entries(attributes.sectionTitles).map(([key, value]) => (
                                    <TextControl
                                        key={key}
                                        label={key.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase()) + ' Title'}
                                        value={value}
                                        onChange={(newValue) => {
                                            setAttributes({
                                                sectionTitles: {
                                                    ...attributes.sectionTitles,
                                                    [key]: newValue
                                                }
                                            });
                                        }}
                                    />
                                ))}
                            </EditorPanelRow>
                        </EditorPanel>
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
                            {generalColors.map(([key, {value, label}], index) => (
                                <EditorPanelRow className="flex-col" key={index}>
                                    <ColorPickerField
                                        label={label}
                                        value={value}
                                        onChange={(newValue) => setColorAttribute(key, newValue, 'generalColors')}
                                    />
                                </EditorPanelRow>
                            ))}
                        </EditorPanel>

                        <EditorPanel title={blocktranslate('Nav')}>
                            {navColors.map(([key, { value, label, type }], index) => (
                                <EditorPanelRow className="flex-col" key={index}>
                                    <ColorPickerField
                                        label={label}
                                        value={value}
                                        onChange={(newValue) => setColorAttribute(key, newValue, 'navColors')}
                                    />
                                </EditorPanelRow>
                            ))}
                        </EditorPanel>

                    </div>
                </div>
            </div>






        </InspectorControls>
    );
};

export default InspectorSettings;
