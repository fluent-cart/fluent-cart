import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/Buttons/Components/InspectorSettings";
import {BuySection} from "@/BlockEditor/Icons";

const {
    useBlockProps,
    RichText,
} = wp.blockEditor;

const {registerBlockType} = wp.blocks;
const {useEffect, useState} = wp.element;
const {useSelect, useDispatch} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;




const blockEditorData = window.fluent_cart_buy_now_button_data;
const rest = window['fluentCartRestVars'].rest;
const fetchUrl = rest.url + '/products/variants/';

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: BuySection,
    },
    example: {
        attributes: {
        },

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
        enable_modal_checkout: {
            type: 'boolean',
            default: false,
        },
        text: {
            type: "string",
            default: "Buy Now"
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
        const {
            text,
            placeholder,
        } = attributes;

        const blockProps = useBlockProps();

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

        const [preSelectedVariations, setPreSelectedVariations] = useState({});
        const selectedVariants = Array.isArray(attributes.variationsData)
            ? attributes.variationsData
            : [];

        let selectedVariant = selectedVariants[0] || null;

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


        return (
            <div>

                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    preSelectedVariations={preSelectedVariations}
                    selectedVariant={selectedVariant}
                />

                <RichText
                    {...props} {...blockProps}
                    tagName="a"
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
