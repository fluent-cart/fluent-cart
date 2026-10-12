import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import InspectorSettings from "@/BlockEditor/ProductReviewForm/Components/InspectorSettings";
import {useHiddenByReviewContainer, useInsideProductReviews, useReviewBlockProduct} from "@/BlockEditor/ProductReviews/Components/reviewBlockShared";
import SingleProductOnlyNotice from "@/BlockEditor/ProductReviews/Components/SingleProductOnlyNotice";
import {ReviewForm} from "@/BlockEditor/Icons";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect} = wp.element;
const ServerSideRender = wp.serverSideRender;

const blockEditorData = window.fluent_cart_product_review_form_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: ReviewForm,
    },
    category: "fluent-cart",
    attributes: {
        // Mirrors ProductReviewFormBlockEditor::blockAttributes() — an
        // attribute missing on either side never reaches the render callback.
        query_type: {
            type: 'string',
            default: 'default',
        },
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        layout: {
            type: 'string',
            default: 'inline',
        },
        starColor: {
            type: 'string',
            default: '#f59e0b',
        },
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({
            className: 'fct-review-form-block-editor',
        });

        const insideParent = useInsideProductReviews(clientId);
        const {showNotice: showSingleProductNotice} = useHiddenByReviewContainer(clientId);
        const {selectedProduct, setSelectedProduct} = useReviewBlockProduct(attributes, insideParent);

        // The preview renders in its own REST request, with no parent block
        // around it: a form inside a container set to a custom product would
        // otherwise be rendered for the edited post (the default query), not
        // for the product the container picked. The editor already knows the
        // effective product, so the preview asks for it by id — the saved
        // attributes stay as they are, and the storefront still follows the
        // container.
        const previewAttributes = selectedProduct?.ID
            ? {...attributes, query_type: 'custom', product_id: selectedProduct.ID}
            : attributes;

        // Inside the container the parent owns the product: pin the child to
        // the default query so resolveProduct() follows the parent's setup
        // and a stale custom pick can never win.
        useEffect(() => {
            if (insideParent && attributes.query_type !== 'default') {
                setAttributes({query_type: 'default'});
            }
        }, [insideParent, attributes.query_type]);

        return (
            <div {...blockProps}>
                {showSingleProductNotice && <SingleProductOnlyNotice/>}
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    insideParent={insideParent}
                />

                {/* The block's own markup, rendered by its own render callback,
                    rather than a second drawing of it kept in step by hand. A
                    hand-made preview drifts — it had the wrong button width and
                    the wrong stepper — and every fix has to be made twice.

                    Stepped forms come back with step one visible and the rest
                    display:none, which is the first step and only the first
                    step. Nothing here is interactive: the storefront script is
                    not loaded in the editor, so the canvas shows the form
                    without it being fillable, which is what a preview is. */}
                <ServerSideRender
                    block={blockEditorData.slug + '/' + blockEditorData.name}
                    attributes={previewAttributes}
                    EmptyResponsePlaceholder={() => (
                        <div className="fct-review-form-block-editor-hint">
                            {blocktranslate('Reviews will be displayed for the current product')}
                        </div>
                    )}
                />
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
