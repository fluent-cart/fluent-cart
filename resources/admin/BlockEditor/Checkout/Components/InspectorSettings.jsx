const {InspectorControls} = wp.blockEditor;
const {TextControl, ToggleControl, Tooltip, Button, SelectControl} = wp.components;
import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {ColorPickerField} from "@/BlockEditor/Components/ColorPickerField";
import colorConfig from "@/BlockEditor/Checkout/colorConfig";


// Helper function to format label text
const formatLabelText = (key) => {
    return key.replace(/^(billing|shipping)_/, '')     // Remove prefix
        .replace(/_1$/, '')  // Remove "_1" at the end
        .replace(/_/g, ' ')                             // Replace underscores with spaces
        .replace(/([A-Z])/g, ' $1')                     // Space before capital letters
        .replace(/^./, str => str.toUpperCase());       // Capitalize first letter
};


const InspectorSettings = ({attributes, setAttributes}) => {
    const {labels} = attributes;
    const generalColors = Object.entries(attributes.colors.generalColors);
    const cardColors = Object.entries(attributes.colors.cardColors);
    const defaultColors = colorConfig;

    const handleLabelChange = (key, value) => {
        setAttributes({
            labels: {
                ...labels,
                [key]: value
            }
        });
    };


    // Group labels by prefix (billing_, shipping_)
    // e.g. {billing: [["billing_full_name", "Full Name"]], shipping: [["shipping_full_name", "Recipient Name"]]}

    const groupedLabels = Object.entries(labels).reduce((groups, [key, value]) => {
        const [prefix] = key.split('_');
        if (!groups[prefix]) {
            groups[prefix] = [];
        }
        groups[prefix].push([key, value]);
        return groups;
    }, {});


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
        //Initialize an empty object to store the reset color values
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
        setAttributes({colors: resetValues});
    };

    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        {Object.entries(groupedLabels).map(([prefix, entries]) => (
                            <EditorPanel
                                key={prefix}
                                title={
                                /* translators: %s - billing or shipping */
                                blocktranslate(`%s Field Labels`, `${prefix.charAt(0).toUpperCase() + prefix.slice(1)}`)
                            }
                            >
                                {entries.map(([key, value]) => (
                                    <EditorPanelRow key={key} className="flex-col">
                                        <TextControl
                                            label={key === 'billing_address' || key === 'shipping_address' ? blocktranslate('Address Section Label') : formatLabelText(key)}
                                            value={value}
                                            onChange={(val) => handleLabelChange(key, val)}
                                        />
                                    </EditorPanelRow>
                                ))}
                            </EditorPanel>
                        ))}
                    </div>
                </div>

                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Coupon')}>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Label')}
                                    value={attributes.coupons.label}
                                    onChange={(val) => setAttributes({coupons: {...attributes.coupons, label: val}})}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Placeholder')}
                                    value={attributes.coupons.placeholder}
                                    onChange={(val) => setAttributes({
                                        coupons: {
                                            ...attributes.coupons,
                                            placeholder: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Button text')}
                                    value={attributes.coupons.applyButton}
                                    onChange={(val) => setAttributes({
                                        coupons: {
                                            ...attributes.coupons,
                                            applyButton: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow>
                                <div className="fct-inspector-control-label">{blocktranslate('Collapsed')}</div>
                                <ToggleControl
                                    checked={attributes.coupons.collapsible}
                                    onChange={(checked) => {
                                        setAttributes({coupons: {...attributes.coupons, collapsible: checked}});
                                    }}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow>
                                <div className="fct-inspector-control-label">{blocktranslate('Icon Visibility')}</div>
                                <ToggleControl
                                    checked={attributes.coupons.iconVisibility}
                                    onChange={(checked) => {
                                        setAttributes({coupons: {...attributes.coupons, iconVisibility: checked}});
                                    }}
                                />
                            </EditorPanelRow>
                        </EditorPanel>
                    </div>
                </div>

                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Submit Button')}>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Text')}
                                    value={attributes.submitButton.text}
                                    onChange={(val) => setAttributes({
                                        submitButton: {
                                            ...attributes.submitButton,
                                            text: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <SelectControl
                                    label={blocktranslate('Size')}
                                    value={attributes.submitButton.size}
                                    placeholder={blocktranslate('Select a size')}
                                    options={[
                                        {label: blocktranslate('Small'), value: 'small'},
                                        {label: blocktranslate('Medium'), value: 'medium'},
                                        {label: blocktranslate('Large'), value: 'large'},
                                    ]}
                                    onChange={(val) => setAttributes({
                                        submitButton: {
                                            ...attributes.submitButton,
                                            size: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <SelectControl
                                    label={blocktranslate('Alignment')}
                                    value={attributes.submitButton.alignment}
                                    placeholder={blocktranslate('Select a alignment')}
                                    options={[
                                        {label: blocktranslate('Left'), value: 'left'},
                                        {label: blocktranslate('Right'), value: 'right'},
                                        {label: blocktranslate('Center'), value: 'center'},
                                    ]}
                                    onChange={(val) => setAttributes({
                                        submitButton: {
                                            ...attributes.submitButton,
                                            alignment: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow>
                                <div className="fct-inspector-control-label">{blocktranslate('Full Width')}</div>
                                <ToggleControl
                                    checked={attributes.submitButton.full}
                                    onChange={(checked) => {
                                        setAttributes({submitButton: {...attributes.submitButton, full: checked}});
                                    }}
                                />
                            </EditorPanelRow>

                        </EditorPanel>
                    </div>
                </div>

                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Order Summary')}>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    type="number"
                                    min={2}
                                    label={blocktranslate('Max Visible Items')}
                                    value={attributes.orderSummary.maxVisibleItems}
                                    onChange={(val) => setAttributes({
                                        orderSummary: {
                                            ...attributes.orderSummary,
                                            maxVisibleItems: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Heading')}
                                    value={attributes.orderSummary.heading}
                                    onChange={(val) => setAttributes({
                                        orderSummary: {
                                            ...attributes.orderSummary,
                                            heading: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Toggle Button Text')}
                                    value={attributes.orderSummary.toggleButtonText}
                                    onChange={(val) => setAttributes({
                                        orderSummary: {
                                            ...attributes.orderSummary,
                                            toggleButtonText: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Remove Button Text')}
                                    value={attributes.orderSummary.removeButtonText}
                                    onChange={(val) => setAttributes({
                                        orderSummary: {
                                            ...attributes.orderSummary,
                                            removeButtonText: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Total text')}
                                    value={attributes.orderSummary.totalText}
                                    onChange={(val) => setAttributes({
                                        orderSummary: {
                                            ...attributes.orderSummary,
                                            totalText: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow>
                                <div className="fct-inspector-control-label">{blocktranslate('Show Remove Button')}</div>
                                <ToggleControl
                                    checked={attributes.orderSummary.showRemoveButton}
                                    onChange={(checked) => {
                                        setAttributes({
                                            orderSummary: {
                                                ...attributes.orderSummary,
                                                showRemoveButton: checked
                                            }
                                        });
                                    }}
                                />
                            </EditorPanelRow>
                        </EditorPanel>
                    </div>
                </div>

                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Shipping Methods')}>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Heading')}
                                    value={attributes.shippingMethods.heading}
                                    onChange={(val) => setAttributes({
                                        shippingMethods: {
                                            ...attributes.shippingMethods,
                                            heading: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>
                        </EditorPanel>
                    </div>
                </div>

                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Payment')}>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Heading')}
                                    value={attributes.paymentMethods.heading}
                                    onChange={(val) => setAttributes({
                                        paymentMethods: {
                                            ...attributes.paymentMethods,
                                            heading: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>
                        </EditorPanel>
                    </div>
                </div>

                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Allow Create Account')}>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Label')}
                                    value={attributes.allowCreateAccount.label}
                                    onChange={(val) => setAttributes({
                                        allowCreateAccount: {
                                            ...attributes.allowCreateAccount,
                                            label: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Tooltip Text')}
                                    value={attributes.allowCreateAccount.infoText}
                                    onChange={(val) => setAttributes({
                                        allowCreateAccount: {
                                            ...attributes.allowCreateAccount,
                                            infoText: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>
                        </EditorPanel>
                    </div>
                </div>

                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Address Modal')}>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Billing Address Heading')}
                                    value={attributes.addressModal.billingAddress}
                                    onChange={(val) => setAttributes({
                                        addressModal: {
                                            ...attributes.addressModal,
                                            billingAddress: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Shipping Address Heading')}
                                    value={attributes.addressModal.shippingAddress}
                                    onChange={(val) => setAttributes({
                                        addressModal: {
                                            ...attributes.addressModal,
                                            shippingAddress: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Modal Open Button Text')}
                                    value={attributes.addressModal.openButtonText}
                                    onChange={(val) => setAttributes({
                                        addressModal: {
                                            ...attributes.addressModal,
                                            openButtonText: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Add Address Button Text')}
                                    value={attributes.addressModal.addButtonText}
                                    onChange={(val) => setAttributes({
                                        addressModal: {
                                            ...attributes.addressModal,
                                            addButtonText: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Apply Button Text')}
                                    value={attributes.addressModal.applyButtonText}
                                    onChange={(val) => setAttributes({
                                        addressModal: {
                                            ...attributes.addressModal,
                                            applyButtonText: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Submit Button Text')}
                                    value={attributes.addressModal.submitButtonText}
                                    onChange={(val) => setAttributes({
                                        addressModal: {
                                            ...attributes.addressModal,
                                            submitButtonText: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Cancel Button Text')}
                                    value={attributes.addressModal.cancelButtonText}
                                    onChange={(val) => setAttributes({
                                        addressModal: {
                                            ...attributes.addressModal,
                                            cancelButtonText: val
                                        }
                                    })}
                                />
                            </EditorPanelRow>

                        </EditorPanel>
                    </div>
                </div>

                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-heading">
                        {blocktranslate('Styling')}
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

                        <EditorPanel title={blocktranslate('Card')}>
                            {cardColors.map(([key, {value, label}], index) => (
                                <EditorPanelRow className="flex-col" key={index}>
                                    <ColorPickerField
                                        label={label}
                                        value={value}
                                        onChange={(newValue) => setColorAttribute(key, newValue, 'cardColors')}
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
