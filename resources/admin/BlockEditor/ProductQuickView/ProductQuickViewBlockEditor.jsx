import {QuickView} from "@/BlockEditor/Icons";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";
import InspectorSettings from "./Components/InspectorSettings";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import useProductSwatchPreview from "@/BlockEditor/ProductSwatches/useProductSwatchPreview";
import {useCardSwatches} from "@/BlockEditor/ShopApp/InnerBlocks/ProductButtonBlock";
import {showsViewOptions} from "@/BlockEditor/ShopApp/InnerBlocks/cardButtonState";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useSelect, useDispatch} = wp.data;
const {useEffect} = wp.element;

// The image blocks that hold Quick View with the badges; anywhere else it sits beside the button.
const IMAGE_BLOCKS = ['fluent-cart/shopapp-product-image', 'fluent-cart/product-image'];

const blockEditorData = window.fluent_cart_product_quick_view_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: QuickView,
    },
    category: 'fluent-cart',
    attributes: {
        position: {type: 'string', default: 'button'},
        show_on: {type: 'string', default: 'always'},
        display: {type: 'string', default: 'icon'},
        image_position: {type: 'string', default: 'bottom-center'},
    },
    // Only allowed inside product loops (see the PHP ancestor list). In an image block it sits on the
    // picture; anywhere else the storefront puts it beside the buy button.
    // The loops hand each card's product over as block context.
    usesContext: ['detail'],
    edit: ({attributes, setAttributes, context, clientId}) => {
        // A simple product gets no quick view on the storefront, so its card shows a note instead.
        const isSimple = context?.detail?.variation_type === 'simple';
        // Nor does a card still showing View Options, which opens quick view itself.
        const product = useSingleProductData(true)?.product;
        const preview = useProductSwatchPreview(product?.ID, useCardSwatches(clientId));
        const besideViewOptions = !isSimple && showsViewOptions(product, preview?.previews?.find(item => item.button)?.button);
        const hasPreview = !isSimple && !besideViewOptions;
        const inImage = useSelect((select) => {
            const store = select('core/block-editor');
            return IMAGE_BLOCKS.includes(store.getBlockName(store.getBlockRootClientId(clientId)));
        }, [clientId]);
        // The block's own registry: carousel and related previews run in a sub-registry.
        const {__unstableMarkNextChangeAsNotPersistent: markNotPersistent} = useDispatch('core/block-editor');
        // Position follows where the block sits, so the storefront places it there too.
        useEffect(() => {
            const position = inImage ? 'image' : 'button';
            if (attributes.position === position) return;
            // A sync, not an edit: no undo step and no unsaved-changes prompt.
            markNotPersistent?.();
            setAttributes({position});
        }, [inImage]);
        // Beside the button it is always a visible icon; the saved choices return on the image.
        const onImage = inImage;
        const showOn = onImage ? attributes.show_on : 'always';
        const display = onImage ? attributes.display : 'icon';
        // The storefront moves it beside the button or onto the image; the canvas shows how it will look there.
        const blockProps = useBlockProps({
            className: !hasPreview
                ? 'fct-card-quick-view'
                : 'fct-card-quick-view is-preview is-preview-' + (onImage ? 'image' : 'button') + (showOn === 'hover' ? ' is-preview-hover' : ''),
            // The storefront's marker, so one set of position rules serves both.
            'data-image-position': hasPreview && onImage ? attributes.image_position : undefined,
        });

        return (
            <div {...blockProps}>
                <ErrorBoundary>
                    <InspectorSettings attributes={attributes} setAttributes={setAttributes} onImage={onImage}/>

                    {isSimple && (
                        <span className="fct-card-swatch-empty">
                            {blocktranslate('No quick view for simple products.')}
                        </span>
                    )}

                    {besideViewOptions && (
                        <span className="fct-card-swatch-empty">
                            {blocktranslate('Hidden while the card shows View Options.')}
                        </span>
                    )}

                    {/* A preview, not a control: kept out of the accessibility tree. */}
                    {hasPreview && <span
                        className={'fct-card-quick-view-button ' + (display === 'icon' ? 'is-icon' : 'is-icon-text')}
                        title={showOn === 'hover' ? blocktranslate('Shown when the card is hovered.') : undefined}
                        aria-hidden="true"
                    >
                        <QuickView/>
                        {display !== 'icon' && (
                            <span className="fct-card-quick-view-text">{blocktranslate('Quick view')}</span>
                        )}
                    </span>}
                </ErrorBoundary>
            </div>
        );
    },

    save: function () {
        return null;
    },

    supports: {
        html: false,
        spacing: {
            margin: true,
            padding: true,
        },
    },
});
