import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const ProductCard = (props) => {
    const {product, placeholderImage, index} = props;

    const getTitle = () => {
        return product ? product.post_title : (blocktranslate("Product") + ' ' + (index + 1));
    }
    const getPostExcerpt = () => {
        return product ? product.post_excerpt : blocktranslate("Lorem ipsum dolor set amet");
    }

    const getImage = () => {
        return product && product.detail && product.detail.featured_media && product.detail.featured_media !== null && typeof product.detail.featured_media === 'object' && product.detail.featured_media.url ?
            product.detail.featured_media.url : placeholderImage;
    }

    const getMinPrice = () => {
        return product && product.detail && product.detail.min_price !== null ?
            (product.detail.min_price / 100).toFixed(2) : '0.00';
    }

    const getMaxPrice = () => {
        return product && product.detail && product.detail.max_price !== null ?
            (product.detail.max_price / 100).toFixed(2) : '0.00';
    }


    return (
        <>
            <div className="fct-product-block-editor-product-card">
                <div className={'group-[.list]:flex-shrink-0'}>
                    <img src={getImage()}
                         className={'w-full aspect-square object-cover rounded-md group-[.list]:w-[214px]'}
                         alt={product ? product.post_title : 'Product'}/>
                </div>

                <div className="p-4 group-[.list]:flex-1 h-full flex flex-col">
                    <div>
                        <h5 className="fct-product-block-editor-product-card-title">{getTitle()}</h5>
                        <div className="fct-product-block-editor-product-card-text">
                            {getPostExcerpt()}
                        </div>
                        <div className="fct-product-block-editor-product-card-sub-title">
                            <div>
                                $<span>{getMinPrice()}</span>
                            </div>
                            <div>-</div>
                            <div>
                                $<span>{getMaxPrice()}</span>
                            </div>
                        </div>
                    </div>
                    <button
                        className="fct-product-block-editor-product-card-primary-button">
                        {product && product.detail.variation_type === 'simple' ? blocktranslate('Add To Cart') : blocktranslate('View Product')}
                    </button>
                </div>
            </div>
        </>
    );
}
export default ProductCard;
