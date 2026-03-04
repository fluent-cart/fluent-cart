import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {InspectorControls} = wp.blockEditor;

const {
    PanelBody,
    TextControl,
    ToggleControl,
    __experimentalToggleGroupControl,
    __experimentalToggleGroupControlOption
} = wp.components;
const ToggleGroupControl = __experimentalToggleGroupControl;
const ToggleGroupControlOption = __experimentalToggleGroupControlOption;

const CustomerDashboardButtonInspectorSettings = ({attributes, setAttributes}) => {
    return (
        <>
            <InspectorControls>
                <PanelBody
                    title={blocktranslate("Settings", "fluent-cart")}
                    initialOpen={true}
                >
                    {/* Display Type */}
                    <ToggleGroupControl
                        isBlock
                        label={blocktranslate("Display type", "fluent-cart")}
                        value={attributes.display_type}
                        onChange={(value) =>
                            setAttributes({display_type: value})
                        }
                    >
                        <ToggleGroupControlOption value="button" label={blocktranslate("Button", "fluent-cart")} />
                        <ToggleGroupControlOption value="link" label={blocktranslate("Link", "fluent-cart")} />
                    </ToggleGroupControl>

                    {/* Button Text */}
                    <TextControl
                        label={blocktranslate("Button text", "fluent-cart")}
                        help={blocktranslate("Leave empty to use the default text: My Account", "fluent-cart")}
                        value={attributes.button_text}
                        onChange={(value) =>
                            setAttributes({button_text: value})
                        }
                    />

                    {/* Show Icon */}
                    <ToggleControl
                        label={blocktranslate("Show icon", "fluent-cart")}
                        checked={attributes.show_icon}
                        onChange={(value) =>
                            setAttributes({show_icon: value})
                        }
                    />

                    {/* Open in New Tab */}
                    <ToggleControl
                        label={blocktranslate("Open in new tab", "fluent-cart")}
                        checked={attributes.link_target === '_blank'}
                        onChange={(value) =>
                            setAttributes({link_target: value ? '_blank' : '_self'})
                        }
                    />
                </PanelBody>
            </InspectorControls>
        </>
    );
};

export default CustomerDashboardButtonInspectorSettings;
