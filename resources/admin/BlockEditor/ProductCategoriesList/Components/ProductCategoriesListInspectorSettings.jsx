import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {InspectorControls} = wp.blockEditor;

const {
    PanelBody,
    ToggleControl,
    __experimentalToggleGroupControl,
    __experimentalToggleGroupControlOption
} = wp.components;
const ToggleGroupControl = __experimentalToggleGroupControl;
const ToggleGroupControlOption = __experimentalToggleGroupControlOption;

const ProductCategoriesListInspectorSettings = ({attributes, setAttributes, clientId}) => {
    return (
        <>
            <InspectorControls>
                <PanelBody
                    title={blocktranslate("Settings", "fluent-cart")}
                    initialOpen={true}
                >
                    {/* Display Style */}
                    <ToggleGroupControl
                        isBlock
                        label={blocktranslate("Display style", "fluent-cart")}
                        value={attributes.display_style}
                        onChange={(value) =>
                            setAttributes({display_style: value})
                        }
                    >
                        <ToggleGroupControlOption value="list" label={blocktranslate("List", "fluent-cart")} />
                        <ToggleGroupControlOption value="dropdown" label={blocktranslate("Dropdown", "fluent-cart")} />
                    </ToggleGroupControl>

                    {/* Show Product Count */}
                    <ToggleControl
                        label={blocktranslate("Show product count", "fluent-cart")}
                        help={blocktranslate("Display the number of products in each category.", "fluent-cart")}
                        checked={attributes.show_product_count}
                        onChange={(value) =>
                            setAttributes({show_product_count: value})
                        }
                    />

                    {/* Show Hierarchy */}
                    <ToggleControl
                        label={blocktranslate("Show hierarchy", "fluent-cart")}
                        help={blocktranslate("Display child categories nested under their parents.", "fluent-cart")}
                        checked={attributes.show_hierarchy}
                        onChange={(value) =>
                            setAttributes({show_hierarchy: value})
                        }
                    />

                    {/* Show Empty */}
                    <ToggleControl
                        label={blocktranslate("Show empty categories", "fluent-cart")}
                        help={blocktranslate("Display categories even if they have no products.", "fluent-cart")}
                        checked={attributes.show_empty}
                        onChange={(value) =>
                            setAttributes({show_empty: value})
                        }
                    />

                </PanelBody>
            </InspectorControls>
        </>
    );
};

export default ProductCategoriesListInspectorSettings;