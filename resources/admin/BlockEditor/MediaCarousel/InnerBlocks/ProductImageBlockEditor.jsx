import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import { useSingleProductData } from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import { ProductImage } from "@/BlockEditor/Icons";

const { useBlockProps } = wp.blockEditor;

const blockEditorData = window.fluent_cart_product_image_data;
const placeholderImage = blockEditorData.placeholder_image;

const ProductImageBlockEditor = {
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: { src: ProductImage },
    category: "fluent-cart",

    edit: () => {
        const blockProps = useBlockProps();
        const singleProductData = useSingleProductData();

        // Read current image from context (provided by image loop in MediaCarouselLoopBlock)
        const currentImage = singleProductData?.currentImage;
        const product = singleProductData?.product;

        const imgUrl = currentImage?.url
            || product?.detail?.featured_media?.url
            || placeholderImage;

        const imgAlt = currentImage?.title
            || product?.post_title
            || blocktranslate('Product Image');

        return (
            <div {...blockProps}>
                <img
                    src={imgUrl}
                    alt={imgAlt}
                    className="w-full aspect-square object-cover rounded-md"
                />
            </div>
        );
    },

    save() {
        return null;
    },

    supports: {
        html: false,
        align: ['left', 'center', 'right', 'wide', 'full'],
        __experimentalBorder: {
            color: true,
            radius: true,
            style: true,
            width: true,
        },
        spacing: {
            margin: true,
            padding: true,
        },
        shadow: true,
        __experimentalFilter: {
            duotone: true,
        },
    },
    usesContext: [
        'fluent-cart/query_type',
        'fluent-cart/carousel_settings',
        'fluent-cart/current_image',
        'fluent-cart/image_index',
    ],
};

export default ProductImageBlockEditor;
