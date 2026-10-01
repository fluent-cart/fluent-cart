import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import InspectorSettings from "@/BlockEditor/WriteAReviewButton/Components/InspectorSettings";
import {useInsideProductReviews} from "@/BlockEditor/ProductReviews/Components/reviewBlockShared";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {WriteAReviewButton, AddReview} from "@/BlockEditor/Icons";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState} = wp.element;

const rest = window.fluentCartRestVars?.rest || {};

const blockEditorData = window.fluent_cart_write_a_review_button_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: WriteAReviewButton,
    },
    category: "fluent-cart",
    attributes: {
        query_type: {
            type: 'string',
            default: 'default',
        },
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        // Mirrors WriteAReviewButtonBlockEditor::blockAttributes() — an
        // attribute missing on either side never reaches the render callback.
        container: {
            type: 'string',
            default: 'drawer',
        },
        layout: {
            type: 'string',
            default: 'inline',
        },
        addReviewButtonText: {
            type: 'string',
            default: '',
        },
        editReviewButtonText: {
            type: 'string',
            default: '',
        },
        loginReviewButtonText: {
            type: 'string',
            default: '',
        },
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const singleProductData = useSingleProductData();
        const [selectedProduct, setSelectedProduct] = useState({});
        const insideParent = useInsideProductReviews(clientId);

        // The wrapper hugs the button so the selection outline matches what
        // is clickable, the way Add to Cart does. Inside a review container
        // the frontend stretches the button to the column, so there the
        // wrapper takes the column too.
        const blockProps = useBlockProps({
            className: 'fct-write-a-review-button-block-editor'
                + (insideParent ? ' is-inside-container' : ''),
        });

        // Inside the container the parent owns the product: pin the child to
        // the default query so resolveProduct() follows the parent's setup
        // and a stale custom pick can never win.
        useEffect(() => {
            if (insideParent && attributes.query_type !== 'default') {
                setAttributes({query_type: 'default'});
            }
        }, [insideParent, attributes.query_type]);

        // isStale guards against out-of-order responses: switching products
        // quickly must never let an older request's response (or failure)
        // overwrite the preview for the current selection.
        const fetchSelectedProduct = (isStale) => {
            if (!rest.url) {
                return;
            }

            apiFetch({
                path: addQueryArgs(rest.url + '/products/' + attributes.product_id, {
                    // Screen key, not a relation name — ProductController::allowedWiths()
                    // owns what it resolves to (detail + variants).
                    with: ['block_product_detail']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            }).then((response) => {
                if (!isStale()) {
                    setSelectedProduct(response.product || {});
                }
            }).catch(() => {
                if (!isStale()) {
                    setSelectedProduct({});
                }
            });
        };

        useEffect(() => {
            let stale = false;
            const isStale = () => stale;

            if (attributes.query_type === 'custom' && !insideParent) {
                if (attributes.product_id) {
                    fetchSelectedProduct(isStale);
                } else {
                    setSelectedProduct({});
                }
            } else {
                // Default mode: clear any stale custom pick when no contextual
                // product exists, so the preview matches the frontend (empty).
                setSelectedProduct(singleProductData?.product || {});
            }

            return () => {
                stale = true;
            };
        }, [singleProductData?.product, attributes.query_type, attributes.product_id, insideParent]);

        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    insideParent={insideParent}
                />

                {/* The button is the block — preview it in every query mode,
                    the way the Buy Section previews its button. What it opens
                    is not on the canvas, so the sidebar names it instead. */}
                {/* Only inside a review container does the frontend stretch
                    the button to the column, so only there does the preview. */}
                <div className={'fct-write-a-review-button-block-editor-cta' + (insideParent ? ' is-full' : '')}>
                    {attributes.addReviewButtonText || blocktranslate('Write a Review')}
                    <AddReview/>
                </div>
            </div>
        );
    },
    save: function () {
        return null;
    },
    supports: {
        html: false,
        align: true,
        typography: {
            fontSize: true,
            lineHeight: true,
            __experimentalFontFamily: true,
            __experimentalFontWeight: true,
            __experimentalDefaultControls: {
                fontSize: true,
            },
        },
        color: {
            text: true,
            background: true,
        },
        spacing: {
            margin: true,
            padding: true,
        },
        __experimentalBorder: {
            color: true,
            radius: true,
            style: true,
            width: true,
        },
    },
});
