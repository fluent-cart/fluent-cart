import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {Edit, ShoppingCart} from "@/BlockEditor/Icons";
import SelectVariationModal from "@/BlockEditor/Components/ProductPicker/SelectVariationModal";


const { InspectorControls } = wp.blockEditor;
const {
    PanelBody,
    CheckboxControl,
    Button,
    Card,
    CardBody,
    Flex,
    FlexItem,
    FlexBlock,
} = wp.components;
const {useBlockProps,  RichText} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState} = wp.element;
const {useSelect, useDispatch} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;


const blockEditorData = window.fluent_cart_add_to_cart_button_data;
const rest = window['fluentCartRestVars'].rest;
const fetchUrl = rest.url + '/products/variants/';

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: ShoppingCart,
    },
    category: "fluent-cart",
    attributes: {
        variant_ids: [],
        variationsData: {
            type: [Array, Object],
            default: []
        },
        query_type: {
            type: 'string',
            default: 'default',
        },
        text: {
            type: "string",
            default: blocktranslate('Add to Cart', 'fluent-cart')
        },
        placeholder: {
            type: "string"
        }
    },
    supports: {
        html: false,
        align: ["left", "center", "right"],
        alignWide: false,
        typography: {
            fontSize: true,
            lineHeight: true
        },
        spacing: {
            margin: true,
            padding: true
        },
        color: {
            text: true
        },
        __experimentalBorder : {
            color: true,
            radius: true,
            style: true,
            width: true,
        },
        reusable: false,
        shadow: true,
    },
    edit: (props) => {
        const {attributes, setAttributes, clientId} = props;
        const {text, placeholder} = attributes;
        const blockProps = useBlockProps();
        const [preSelectedVariations, setPreSelectedVariations] = useState({});
        const selectedVariants = Array.isArray(attributes.variationsData)
            ? attributes.variationsData
            : [];
        const selectedVariant = selectedVariants[0] || null;

        const [ isSelectingProduct, setIsSelectingProduct ] = useState( false );

        const restrictedParentBlocks = [
            'fluent-cart/product-info',
            'fluent-cart/products',
            'fluent-cart/shopapp-product-container',
            'fluent-cart/shopapp-product-loop',
            'fluent-cart/product-carousel',
        ];

        const isInsideRestrictedParent = useSelect((select) => {
            const { getBlockParents, getBlockName } = select(blockEditorStore);
            const parents = getBlockParents(clientId);
            return parents.some((parentId) => restrictedParentBlocks.includes(getBlockName(parentId)));
        }, [clientId]);

        const { removeBlock } = useDispatch(blockEditorStore);

        useEffect(() => {
            if (isInsideRestrictedParent) {
                removeBlock(clientId);
            }
        }, [isInsideRestrictedParent]);

        let queryParams = {
            "variant_ids": attributes.variant_ids,
            'with_product': true,
            "per_page": 10,
            "page": 1,
            "order_by": 'ID',
            "order_type": 'DESC',
        };

        const fetchVariants = () => {
            // If no variations are selected, return
            if (!attributes.variant_ids?.length) {
                setPreSelectedVariations([]);
                setAttributes({ variationsData: [] });
                return;
            }

            apiFetch({
                path: addQueryArgs(fetchUrl, {params: queryParams}),
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            }).then((response) => {
                const items = response.variants || [];

                setPreSelectedVariations(items);

                setAttributes({ variationsData: items });

            }).finally(() => {

            });
        }


        useEffect(() => {
            fetchVariants();
        }, [attributes.variant_ids]);


        const renderProductCard = () => {
            if (!selectedVariant) {
                return null;
            }

            return (
                <Card size="small" style={{ marginTop: '12px' }}>
                    <CardBody>
                        <Flex align="flex-start" gap={ 3 }>
                            <FlexBlock>
                                <div style={{ marginBottom: '4px', fontWeight: '600', fontSize: '13px' }}>
                                    {selectedVariant?.variation_title}
                                </div>
                                <div style={{ fontSize: '13px', color: '#757575', marginBottom: '2px' }}>
                                    {blocktranslate("Price")}:
                                    <span style={{ fontWeight: '600', color: '#000', marginLeft: '4px' }} dangerouslySetInnerHTML={
                                        {__html: selectedVariant?.formatted_total}
                                    }></span>
                                </div>
                            </FlexBlock>
                            <FlexItem>
                                <Button
                                    icon={ Edit }
                                    label={blocktranslate("Edit product selection")}
                                    isSmall
                                    onClick={ () => setIsSelectingProduct( true ) }
                                />
                            </FlexItem>
                        </Flex>
                    </CardBody>
                </Card>
            );
        };

        return (
            <div>

                <InspectorControls>
                    <PanelBody
                        title={blocktranslate("Product Settings")}
                        initialOpen={ true }
                    >
                        <p>{blocktranslate("Select a specific product variation for this button.", 'fluent-cart')}</p>

                        { ( ! selectedVariant || isSelectingProduct ) && (
                            <SelectVariationModal
                                not_subscribable={true}
                                button={true}
                                isMultiple={false}
                                setAttributes={setAttributes}
                                onModalClosed={(selectedVariations) => {
                                    let variations = {...selectedVariations};
                                    let variantIds = Object.keys(variations);

                                    setAttributes({variationsData: {...variations}});
                                    setAttributes({variant_ids: variantIds});
                                    setIsSelectingProduct( false );

                                }}
                            />
                        ) }

                        {selectedVariant && !isSelectingProduct && renderProductCard()}

                    </PanelBody>
                </InspectorControls>


                <RichText
                    {...props} {...blockProps}
                    tagName="div"
                    className="wp-block-button__link wp-element-button"

                    value={ text }
                    onChange={ ( value ) => setAttributes( { text: value } ) }
                    placeholder={ placeholder ||  blocktranslate("Add text…", 'fluent-cart') }
                    allowedFormats={ [ 'core/bold', 'core/italic' ] }
                />

            </div>
        );
    },

    save: function (props) {
        return null;
    },
});
