import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {ShoppingBag, ShoppingBagAlt, ShoppingCart} from "@/BlockEditor/Icons";


const {
    InspectorControls,
    PanelColorSettings,
    __experimentalColorGradientSettingsDropdown,
    __experimentalUseMultipleOriginColorsAndGradients
} = wp.blockEditor;

const {
    PanelBody,
    CheckboxControl,
    RadioControl,
    ToggleControl,
    BaseControl,
    TextControl,
    __experimentalToggleGroupControl,
    __experimentalToggleGroupControlOption
} = wp.components;
const {useState} = wp.element;
const ToggleGroupControl = __experimentalToggleGroupControl;
const ToggleGroupControlOption = __experimentalToggleGroupControlOption;
const ColorGradientSettingsDropdown = __experimentalColorGradientSettingsDropdown;
const useMultipleOriginColorsAndGradients = __experimentalUseMultipleOriginColorsAndGradients;

const MiniCartInspectorSettings = ({ attributes, setAttributes, clientId }) => {
    // Build color settings array
    const colorSettings = [
        {
            label: blocktranslate('Icon', 'fluent-cart'),
            colorValue: attributes.icon_color,
            onColorChange: ( value ) => setAttributes( { icon_color: value } )
        }
    ];

    // Add price color if price is shown
    if (attributes.show_total_price) {
        colorSettings.push({
            label: blocktranslate('Price', 'fluent-cart'),
            colorValue: attributes.price_color,
            onColorChange: ( value ) => setAttributes( { price_color: value } )
        });
    }

    // Add product count color if the count is shown
    if (attributes.show_item_count !== 'never') {
        colorSettings.push({
            label: blocktranslate('Product Count', 'fluent-cart'),
            colorValue: attributes.product_count_color,
            onColorChange: (value) => setAttributes({ product_count_color: value })
        });
    }

    const colorGradientSettings = useMultipleOriginColorsAndGradients();

    return (
        <>

            {/* Color Settings - Styles Tab */}
            <InspectorControls group="color">
                <ColorGradientSettingsDropdown
                    panelId={ clientId }
                    settings={colorSettings}
                    { ...colorGradientSettings }
                />
            </InspectorControls>


            {/* Other Settings - Settings Tab */}
            <InspectorControls>
                <PanelBody
                    title={ blocktranslate("Settings", "fluent-cart") }
                    initialOpen={ true }
                >

                    {/* Cart Icon */}
                    <ToggleGroupControl
                        className="fct-mini-cart-inspector-controls"
                        isBlock
                        label={ blocktranslate("Cart Icon", "fluent-cart") }
                        value={ attributes.cart_icon }
                        onChange={ (value) => setAttributes({ cart_icon: value }) }
                    >
                        <ToggleGroupControlOption value="cart" label={ <ShoppingCart /> } />
                        <ToggleGroupControlOption value="bag" label={ <ShoppingBag /> } />
                        <ToggleGroupControlOption value="bag-alt" label={ <ShoppingBagAlt /> } />
                    </ToggleGroupControl>

                    {/* Custom Icon URL (Optional) */}
                    <TextControl
                        label={ blocktranslate("Or use custom icon URL", "fluent-cart") }
                        help={ blocktranslate("Leave empty to use the selected icon above. Enter a URL to override with a custom icon.", "fluent-cart") }
                        value={ ['cart', 'bag', 'bag-alt'].includes(attributes.cart_icon) ? '' : attributes.cart_icon }
                        onChange={ (value) => setAttributes({ cart_icon: value || 'cart' }) }
                        placeholder="https://example.com/icon.svg"
                        type="url"
                    />

                    {/* Show Total Price */}
                    <BaseControl
                        label={ blocktranslate("Display options", "fluent-cart") }
                    >
                        <ToggleControl
                            label={ blocktranslate("Display total price", "fluent-cart") }
                            help={ blocktranslate("Toggle to display the total price of the cart.", "fluent-cart") }
                            checked={ attributes.show_total_price }
                            onChange={ (value) =>
                                setAttributes({ show_total_price: value })
                            }
                        />
                    </BaseControl>

                    {/* Cart Item Count */}
                    <BaseControl
                        label={ blocktranslate("Show cart item count", "fluent-cart") }
                        help={ blocktranslate("The editor does not display the real count value, but a placeholder to indicate how it will look on the front-end.", "fluent-cart") }
                    >
                        <RadioControl
                            selected={ attributes.show_item_count }
                            options={ [
                                {
                                    label: blocktranslate("Always (even if empty)", "fluent-cart"),
                                    value: "always",
                                },
                                {
                                    label: blocktranslate("Only if cart has items", "fluent-cart"),
                                    value: "has_items",
                                },
                                {
                                    label: blocktranslate("Never", "fluent-cart"),
                                    value: "never",
                                },
                            ] }
                            onChange={ (value) =>
                                setAttributes({ show_item_count: value })
                            }
                        />
                    </BaseControl>


                </PanelBody>
            </InspectorControls>
        </>
    );
};

export default MiniCartInspectorSettings;
