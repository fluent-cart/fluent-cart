import InspectorSettings from './Components/InspectorSettings';
import {productLoadFailure} from '@/BlockEditor/Components/ProductPicker/productLoadFailure';
import CardLayoutSettings from '@/BlockEditor/ShopApp/InnerBlocks/Components/CardLayoutSettings';
import {availableTemplate, cardLayoutName, cardPresets, cardTemplate, layoutOwnedByProducts} from '@/BlockEditor/ShopApp/InnerBlocks/ProductCardLayoutPresets';
import {SingleProductDataProvider, useSingleProductData} from '@/BlockEditor/ShopApp/Context/SingleProductContext';
import safeProductId from '../Components/safeProductId.js';

const {useBlockProps, useInnerBlocksProps, BlockContextProvider} = wp.blockEditor;
const {useEffect, useState} = wp.element;
const {useSelect} = wp.data;
const LOOPS = ['fluent-cart/shopapp-product-loop', 'fluent-cart/product-carousel-loop', 'fluent-cart/product-template'];
// The loops that lay cards out in a grid, where Masonry staggers them; a carousel is one row.
const GRID_LOOPS = ['fluent-cart/shopapp-product-loop', 'fluent-cart/product-template'];

export default function ProductCardTemplate({attributes, setAttributes, clientId, context}) {
    const inherited = useSingleProductData(true);
    const inLoop = useSelect(select => {
        const editor = select('core/block-editor');
        return editor.getBlockParents(clientId).some(id => LOOPS.includes(editor.getBlockName(id)));
    }, [clientId]);
    const inGrid = useSelect(select => {
        const editor = select('core/block-editor');
        return editor.getBlockParents(clientId).some(id => GRID_LOOPS.includes(editor.getBlockName(id)));
    }, [clientId]);
    // In a Products block, the card's Layout panel shows on that block instead, and not here.
    const layoutOnProducts = useSelect(select => !!layoutOwnedByProducts(select('core/block-editor'), clientId), [clientId]);
    const [selectedProduct, setSelectedProduct] = useState({});
    const productId = attributes.query_type === 'custom' && !inLoop
        ? attributes.product_id
        : (context.postType === 'fluent-products' ? context.postId : null);

    useEffect(() => {
        if (inLoop || (attributes.query_type !== 'custom' && inherited?.product) || !productId) {
            setSelectedProduct({});
            return;
        }
        // Keep a product the picker just handed over; the fetch below swaps in the full detail.
        setSelectedProduct((current) => (String(current?.ID) === String(productId) ? current : {}));
        let active = true;
        const rest = window.fluentCartRestVars.rest;
        wp.apiFetch({
            url: wp.url.addQueryArgs(rest.url + '/products/' + safeProductId(productId), {with: ['block_product_detail']}),
            headers: {'X-WP-Nonce': rest.nonce},
        }).then(response => {
            if (active) setSelectedProduct(response.product || {missing: true});
        }).catch((error) => {
            if (active) setSelectedProduct(productLoadFailure(error));
        });
        return () => { active = false; };
    }, [productId, inLoop, inherited?.product, attributes.query_type]);

    // Not the missing / loadError markers: the inner blocks read product fields.
    const loadedProduct = selectedProduct?.ID ? selectedProduct : {};
    const product = inLoop || attributes.query_type !== 'custom'
        ? inherited?.product || loadedProduct
        : loadedProduct;
    const layout = cardLayoutName(attributes);
    const styled = layout !== 'classic';
    const blockProps = useBlockProps({
        className: (styled ? `fct-card-layout fct-card-layout--${layout}` : 'fct-product-card-template')
            + (attributes.masonry ? ' fct-card-masonry' : ''),
    });
    const innerBlocksProps = useInnerBlocksProps(blockProps, {
        template: availableTemplate(cardTemplate(cardPresets()[layout])),
        templateLock: false,
        __unstableDisableLayoutClassNames: true,
    });
    const content = <div {...innerBlocksProps}/>;

    return <>
        {/* Which product first, then how its card looks. */}
        {!inLoop && <InspectorSettings attributes={attributes} setAttributes={setAttributes}
            selectedProduct={selectedProduct} setSelectedProduct={setSelectedProduct} displayedProduct={product}/>}
        {!layoutOnProducts && <CardLayoutSettings
            clientId={clientId}
            layout={layout}
            masonry={!!attributes.masonry}
            onMasonry={inGrid ? (masonry) => setAttributes({masonry}) : null}
        />}
        <SingleProductDataProvider value={{product}}>
            <BlockContextProvider value={{
                ...context,
                ...product,
                postId: product?.ID || context.postId,
                postType: product?.ID ? 'fluent-products' : context.postType,
                'fluent-cart/price_format': inLoop ? context['fluent-cart/price_format'] : attributes.price_format,
            }}>
                {inLoop ? content : <div className="fct-product-block-editor-product-card"
                    style={{width: attributes.card_width === '100%' ? '100%' : `${attributes.card_width}px`, maxWidth: '100%'}}>
                    {content}
                </div>}
            </BlockContextProvider>
        </SingleProductDataProvider>
    </>;
}
