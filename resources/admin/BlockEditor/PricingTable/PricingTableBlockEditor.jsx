import React from "react";
import {CaretRight, Cart, Delete, PricingTableIcon} from "@/BlockEditor/Icons";
import EmptyBlock from "@/BlockEditor/Components/Empty";
import SelectVariationModal from "@/BlockEditor/Components/ProductPicker/SelectVariationModal.jsx";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from '@wordpress/url';
import InspectorSettings from "@/BlockEditor/PricingTable/InspectorSettings";
import {colorConfig} from "@/BlockEditor/PricingTable/colorConfig";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import PricingTablePreview from "./PricingTable.png";

const {InspectorControls} = wp.editor;
const {registerBlockType} = wp.blocks;
const blockEditorData = window.fluent_cart_product_pricing_table_data;
const rest = window['fluentCartRestVars'].rest;
const fetchUrl = rest.url + '/products/variants/';

const {
    TextControl
} = wp.components


const {useEffect, useState} = wp.element;
const { useBlockProps } = wp.blockEditor;

const {
    Button
} = wp.components

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    icon: {
        src: PricingTableIcon
    },
    example: {
        attributes: {
        },
        innerBlocks: [
            {
                name: 'core/image',
                attributes: {
                    url: PricingTablePreview,
                    alt: 'Pricing Table Preview',

                    style: {
                        width: '100%',
                        height: 'auto',
                    }
                }
            },
        ],
    },
    category: 'fluent-cart',
    title: blockEditorData.title,
    attributes: {
        variations: [],
        show_cart_button: {type: 'boolean', default: true},
        show_checkout_button: {type: 'boolean', default: true},
        primary_variation: {type: 'int', default: null},
        group_by: {type: 'string', default: 'repeat_interval'}, //repeat_interval,payment_type,none
        product_per_row: {type: 'number', default: 0},
        active_variant: {
            type: 'object',
            default: {}
        },
        active_tab: {
            type: 'number',
            default: 0,
        },
        variationsData: {
            type: [Array, Object],
            default: []
        },
        tabContent: {
            type: 'array',
            default: []
        },
        badge: {
            type: 'object',
            default: {
                text: blocktranslate('Recommended'),
                position: 'right'
            }
        },
        colors: {
            type: 'object',
            default: colorConfig
        },
        buttonOptions: {
            type: 'object',
            default: {
                text: '',
                cartButtonText: ''
            }
        },
        checkout_button_url_params: {
            type: 'string',
            default: ''
        },
        iconVisibility: {type: Boolean, default: true},
    },


    edit: ({attributes, setAttributes}) => {
        const blockProps = useBlockProps();
        useEffect(() => {
            //setAttributes({variationsData: {}});
            //TODO Fetch variation data from server using attributes.variations and update variationsData
            fetchVariants();
        }, []);

        let queryParams = {
            "variant_ids": attributes.variations,
            "select": ['id', 'other_info', 'variation_title'],
            "per_page": 10,
            "page": 1,
            "order_by": 'ID',
            "order_type": 'DESC',
        };

        const [preSelectedVariations, setPreSelectedVariations] = useState({});
        const [expandedProducts, setExpandedProducts] = useState({});


        const fetchVariants = () => {
            apiFetch({
                path: addQueryArgs(fetchUrl, {params: queryParams}),
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            }).then((response) => {
                let items = response.variants;

                const prepareVariantsData = items.reduce((item, data) => {
                    item[data.id] = {
                        other_info: data.other_info,
                        variation_title: data.variation_title
                    };
                    return item;
                }, {});

                setPreSelectedVariations(prepareVariantsData);

                setAttributes({variationsData: prepareVariantsData});
                onChangeVariationGroup(prepareVariantsData);

            }).finally(() => {

            });
        }

        const updateVariationData = (key, variation) => {
            // let variations = {...attributes.variationsData};
            // variations[key] = variation;
            // setAttributes({variationsData: {...variations}});
            setVariationUpdated(true);

            // Trigger Gutenberg save
            wp.data.dispatch('core/editor').savePost();


            apiFetch({
                path: fetchUrl + key + '/pricing-table',
                method: 'PUT',
                data: {description: variation.other_info.description},
                headers: {
                    'X-WP-Nonce': fluentCartRestVars.rest.nonce
                }
            }).then((res) => {

            }).finally(() => {

            });
        }

        const deleteVariation = (key) => {
            // Create a copy of variations data
            let variations = {...attributes.variationsData};

            // Delete the specific key from variations if it exists
            if (variations.hasOwnProperty(key)) {
                delete variations[key]
            }
            setAttributes({variationsData: {...variations}});
            setAttributes({variations: Object.keys(variations)});

            // Get the active tab index and clone the tabContent array
            const {active_tab, tabContent} = attributes;

            // Copy of tab content
            const updatedTabContent = [...tabContent];

            // Ensure the active tab exists
            if (updatedTabContent[active_tab]) {
                const activeTab = updatedTabContent[active_tab];

                // Delete the key if it exists in the active tab's content
                if (activeTab.content?.[key]) {
                    delete activeTab.content[key];

                    // Check if the content is empty after deletion
                    if (Object.keys(activeTab.content).length === 0) {
                        // Optionally remove the entire tab if the content is empty
                        updatedTabContent.splice(active_tab, 1);
                    } else {
                        // Otherwise, update the active tab with the new content
                        updatedTabContent[active_tab] = {...activeTab, content: {...activeTab.content}};
                    }

                    // Update attributes with the modified tab content
                    setAttributes({tabContent: updatedTabContent});

                    // Check if we need to reset active tab or handle empty tabs
                    if (updatedTabContent.length === 0) {
                        // If no tabs left, reset the attributes related to tabs
                        setAttributes({active_tab: 0, active_variant: {}, tabContent: []});
                    } else if (active_tab >= updatedTabContent.length) {
                        // If the active tab was deleted, switch to the first available tab
                        setAttributes({active_tab: 0});
                    }
                }
            }
        }


        const groupLanguageMap = [
            {key: 'daily', value: 'daily'},
            {key: 'weekly', value: 'weekly'},
            {key: 'monthly', value: 'monthly'},
            {key: 'quarterly', value: 'quarterly'},
            {key: 'half_yearly', value: 'half_yearly'},
            {key: 'yearly', value: 'yearly'},
            {key: 'onetime', value: 'onetime'},
            {key: 'subscription', value: 'subscription'},
            {key: 'installment', value: 'installment'}
        ];

        const getGroupValueByKey = (key) => {
            const item = groupLanguageMap.find(entry => entry.key === key);
            return item ? item.value : 'onetime'; // Return 'onetime' if the key is not found
        };

        const onChangeVariationGroup = (variations, groupBy = attributes.group_by) => {
            let groupTitles = {};
            let groupKey = 'repeat_interval';
            let items = variations;

            if (groupBy === 'payment_type') {
                groupKey = 'payment_type';
            } else if (groupBy === 'none') {
                groupKey = 'onetime';
            }


            if (groupBy !== 'none') {
                Object.entries(items).forEach(([key, value]) => {
                    // Extract the `groupKey` value or fallback to 'onetime'
                    let groupValue = value.other_info[groupKey] || 'onetime';
                    // Get the value by key
                    if (value.other_info['installment'] === 'yes') {
                        groupValue = 'installment';
                    }
                    groupTitles[groupValue] = getGroupValueByKey(groupValue);
                });
            } else {
                groupTitles['onetime'] = getGroupValueByKey('onetime');
            }

            // The reduce function starts with an empty object `{}` where we'll store the grouped items.
            const groupedVariations = Object.entries(items).reduce((item, [key, value]) => {

                // Use the group value based on `groupKey`, or default to "onetime" if it’s not available
                let groupValue = value.other_info[groupKey] || 'onetime';
                if (value.other_info['installment'] === 'yes') {
                    groupValue = 'installment';
                }
                // Initialize the object for this group if it doesn't exist
                if (!item[groupValue]) {
                    item[groupValue] = {};
                }

                // Add the current item into the correct group using `key` as its ID within the group
                item[groupValue][key] = {
                    id: key, // Set the item ID
                    other_info: value.other_info,
                    variation_title: value.variation_title,
                };
                return item;
            }, {});

            const tabContent = Array.isArray(groupedVariations)
                ? groupedVariations
                : Object.keys(groupedVariations).map(groupKey => ({
                    title: groupKey, // The group key becomes the tab title
                    content: Object.entries(groupedVariations[groupKey]).reduce((item, [id, variation]) => {
                        item[id] = {id, ...variation}; // Add the ID as a key with its corresponding data
                        return item;
                    }, {})
                }));

            if (Object.keys(groupTitles).length === 1) {
                setAttributes({active_tab: 0});
            }

            return setAttributes({tabContent});
        }

        const [variationUpdated, setVariationUpdated] = useState(false);

        // Toggle the visibility of the textarea for a specific product
        const toggleProductDescription = (productKey) => {
            setExpandedProducts((prevExpanded) => {
                const newExpandedState = Object.keys(prevExpanded).reduce((acc, key) => {
                    acc[key] = false; // Collapse all items
                    return acc;
                }, {});

                // Toggle the specific product’s expanded state
                newExpandedState[productKey] = !prevExpanded[productKey];

                return newExpandedState;
            });
        };



        return(
            <div {...blockProps}>
                <div className="py-4">
                    <InspectorSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        onChangeVariationGroup={onChangeVariationGroup}
                    />

                    <div className="fluent-cart-pricing-table-block-editor">
                        <div className="fluent-cart-pricing-table-block-editor-head">
                            <SelectVariationModal
                                setAttributes={setAttributes}
                                preSelectedVariations={preSelectedVariations}
                                onModalClosed={(selectedVariations) => {
                                    let variations = {...selectedVariations};
                                    setAttributes({variationsData: {...variations}});
                                    setAttributes({variations: Object.keys(variations)});
                                    onChangeVariationGroup(variations);
                                }}
                            />
                        </div>
                        <div className="fluent-cart-pricing-table-block-editor-body">
                            {Object.keys(attributes.tabContent[attributes.active_tab]?.content || {}).length > 0 ? (
                                <div className="fct-custom-tabs">
                                    {attributes.tabContent.length > 1 && (
                                        <div className="fct-tab-header">
                                            {Array.isArray(attributes.tabContent) && attributes.tabContent.map((tab, index) => (
                                                <Button
                                                    key={index}
                                                    isPrimary={attributes.active_tab === index}
                                                    onClick={() => setAttributes({active_tab: index})}
                                                >
                                                    {tab.title}
                                                </Button>
                                            ))}
                                        </div>
                                    )}

                                    <div className="fct-tab-content">
                                        {Array.isArray(attributes.tabContent) && attributes.tabContent[attributes.active_tab] && (
                                            <>
                                                <div className="fct-tab-content-inner">
                                                    <div className="fct-active-product-select-container">
                                                        <div className="fct-form-group">
                                                            <label>{blocktranslate('Recommended variant')}</label>
                                                            {(() => {
                                                                const currentTab = attributes.tabContent[attributes.active_tab];
                                                                const tabTitle = currentTab?.title || "";

                                                                return (

                                                                    // <Selectrix
                                                                    //     customKeys={{
                                                                    //         key: 'value',
                                                                    //         label: 'label'
                                                                    //     }}
                                                                    //     options={
                                                                    //         Object.keys(attributes.tabContent[attributes.active_tab]?.content || {}).map((key) => ({
                                                                    //             value: key,  // Using the key as the value
                                                                    //             label: attributes.tabContent[attributes.active_tab]?.content[key]?.variation_title || key  // Using variation title or key as label
                                                                    //         }))
                                                                    //     }
                                                                    //     defaultValue={attributes.active_variant[tabTitle] || null}
                                                                    //     name={tabTitle}
                                                                    //     onChange={(value) => {
                                                                    //         setAttributes({
                                                                    //             active_variant: {
                                                                    //                 ...attributes.active_variant,
                                                                    //                 [tabTitle]: value.key // This will store the selected variant ID for the respective tab
                                                                    //             }
                                                                    //         });
                                                                    //     }}
                                                                    // />
                                                                    <>
                                                                        <CustomSelect
                                                                            key={tabTitle}
                                                                            customKeys={{
                                                                                key: 'value',
                                                                                label: 'label'
                                                                            }}
                                                                            options={
                                                                                Object.keys(attributes.tabContent[attributes.active_tab]?.content || {}).map((key) => ({
                                                                                    value: key,  // Using the key as the value
                                                                                    label: attributes.tabContent[attributes.active_tab]?.content[key]?.variation_title || key  // Using variation title or key as label
                                                                                }))
                                                                            }
                                                                            defaultValue={attributes.active_variant?.[tabTitle] || null}
                                                                            name={tabTitle}
                                                                            onChange={(value) => {
                                                                                const updated = { ...attributes.active_variant };

                                                                                if (value === null || (Array.isArray(value) && value.length === 0)) {
                                                                                    delete updated[tabTitle]; // remove the key entirely
                                                                                } else {
                                                                                    updated[tabTitle] = value; // set new value
                                                                                }

                                                                                setAttributes({ active_variant: updated }); // pass new object reference
                                                                            }}
                                                                        />
                                                                    </>

                                                                );
                                                            })()}
                                                        </div>
                                                    </div>

                                                    <div className="fct-selected-products-container">
                                                        {Object.keys(attributes.tabContent[attributes.active_tab]?.content || {}).map((key) => {
                                                            const variation = attributes.tabContent[attributes.active_tab]?.content[key] || {};
                                                            const isExpanded = expandedProducts[key];
                                                            return (
                                                                <div className="fct-selected-product"
                                                                     key={`variations-${variation.id}`}>
                                                                    <div
                                                                        className={`fct-selected-product-toggle ${isExpanded ? 'is-expanded' : ''}`}
                                                                        onClick={() => toggleProductDescription(key)}>
                                                                        <div className="icon">
                                                                            <CaretRight/>
                                                                        </div>
                                                                    </div>

                                                                    <div className="fct-selected-product-contents">
                                                                        <div className="product-title"
                                                                             title={variation.variation_title}>
                                                                            {variation.variation_title}
                                                                        </div>
                                                                        <div className="product-id">#{key}</div>
                                                                        <div
                                                                            className={`product-textarea ${isExpanded ? 'is-expanded' : ''}`}>
                                                                            <div className="product-textarea-inner">
                                                                        <textarea
                                                                            placeholder={blocktranslate('Variants features...')}
                                                                            defaultValue={variation.other_info.description || ''}
                                                                            onBlur={(event) => {
                                                                                // Save the description with newline characters when the user presses Enter
                                                                                variation.other_info.description = event.target.value.replace(/\r?\n/g, '\n');

                                                                                updateVariationData(key, variation);
                                                                            }}
                                                                        />
                                                                                <div className="hint-text">
                                                                                    <strong>{blocktranslate('Hint: ')}</strong>
                                                                                    {blocktranslate('Use Enter to add line breaks for a list format')}
                                                                                </div>

                                                                                <div className="product-url-params-inner">
                                                                                    <div className="product-title">{blocktranslate('Button Url Params')}</div>
                                                                                    <TextControl
                                                                                        value={attributes.checkout_button_url_params}
                                                                                        onChange={(value) => setAttributes({checkout_button_url_params: value})}
                                                                                    />
                                                                                    <div className="fct-inspector-control-hint">
                                                                                        {blocktranslate('Add any query params you want to pass to the checkout page. eg: utm_source=google&utm_medium=cpc')}
                                                                                    </div>
                                                                                </div>
                                                                            </div>

                                                                        </div>

                                                                    </div>

                                                                    <div
                                                                        className="product-delete-icon"
                                                                        onClick={() => {
                                                                            deleteVariation(key)
                                                                        }}
                                                                        title={blocktranslate('Delete')}
                                                                    >
                                                                        <Delete/>
                                                                    </div>

                                                                </div>
                                                            );
                                                        })}
                                                    </div>

                                                </div>
                                            </>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <EmptyBlock text={blocktranslate('Find products to make a pricing table')}/>
                            )}
                        </div>

                    </div>
                </div>
            </div>
        )
    },
    save: function (props) {
        return null;
    },
});
