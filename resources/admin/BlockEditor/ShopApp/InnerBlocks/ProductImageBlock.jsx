import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";

const {InnerBlocks, useBlockProps} = wp.blockEditor;
const ProductImageBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fct-product-block-editor-product-card-image group-[.list]:flex-shrink-0',
        });
        const {context} = props;


        const singleProductData = useSingleProductData();

        const getImage = () => {
            const placeholderImage = window.fluent_cart_block_editor_asset.placeholder_image;
            return singleProductData.product && singleProductData.product.detail && singleProductData.product.detail.featured_media && singleProductData.product.detail.featured_media !== null && typeof singleProductData.product.detail.featured_media === 'object' && singleProductData.product.detail.featured_media.url ?
                singleProductData.product.detail.featured_media.url : placeholderImage;
        }

        return (
            <div {...blockProps} >
                <div className={'group-[.list]:flex-shrink-0 relative'}>
                    <img src={getImage()}
                         className={'w-full aspect-square object-cover rounded-md group-[.list]:w-[214px] pointer-events-none'}
                         alt={singleProductData.product ? singleProductData.product.post_title : 'Product'}/>
                    <div className="absolute inset-0" style={{pointerEvents: 'auto'}}>
                        <InnerBlocks
                            allowedBlocks={[
                                'fluent-cart/sold-out-badge',
                                'fluent-cart/sale-badge',
                            ]}
                            renderAppender={false}
                        />
                    </div>
                </div>
            </div>
        );
    },
    save: (props) => {
        const blockProps = useBlockProps.save();
        return (
            <div {...blockProps} className="fluent-cart-product-image">
                <InnerBlocks.Content/>
            </div>
        );
    }
};

export default ProductImageBlock;
