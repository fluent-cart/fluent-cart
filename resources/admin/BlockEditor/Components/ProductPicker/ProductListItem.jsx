import CollapsibleList from "@/BlockEditor/Components/ProductPicker/CollapsibleList";
import ListItem from "./ListItem";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useState} = wp.element
const ProductListItem = (props) => {
    const {
        products,
        index,
        updateSelectedVariations,
        selectedVariations,
        isMultiple,
        allow_subscription = true
    } = props;

    const [isOpen, setIsOpen] = useState(false);

    const toggleCollapsible = () => {
        setIsOpen(!isOpen)
    }

    const product = products[index];
    const firstVariant = {
        ...product.variants[0],
        variation_type: product?.detail?.variation_type,
        variation_title: product?.post_title
    };
    const hasMultipleVariation = product.variants.length > 1;
    const title = hasMultipleVariation ?
        product?.post_title : firstVariant?.variation_title;


    const stock = hasMultipleVariation ? '' : (
        firstVariant?.manage_stock == '1' ?
            /* translators: %d - number of items available */
            blocktranslate('%d Available', firstVariant?.available) : blocktranslate("Unlimited")
    );

    const price = (product.variants.length);

    const media = hasMultipleVariation ?
        product?.detail?.featured_media?.url : firstVariant?.thumbnail;


    const variationOptions = () => {
        const baseVariants = allow_subscription
            ? product.variants
            : product.variants.filter(
                  (variant) => variant?.payment_type !== "subscription"
              );

        return baseVariants.map((variant) => ({
            ...variant,
            variation_type: product?.detail?.variation_type,
            variation_title: variant?.variation_title
        }));
    }
    

    return <>
        <div className="fct-collapsible-list-item-inner">

            <ListItem
                variant={firstVariant}
                title={title}
                media={media}
                stock={stock}
                price={price}
                showCollapseButton={hasMultipleVariation}
                checked={Object.keys(selectedVariations).includes(String(firstVariant?.id))}
                toggleCollapse={toggleCollapsible}
                updateSelectedVariations={updateSelectedVariations}
                isOpen={isOpen}
                isMultiple={isMultiple}
            />
            {
                hasMultipleVariation &&
                <CollapsibleList
                    allow_subscription={allow_subscription}
                    isMultiple={true}
                    variants={variationOptions()}
                    isOpen={isOpen}
                    updateSelectedVariations={updateSelectedVariations}
                    selectedVariations={selectedVariations}
                />
            }
        </div>
    </>
}

export default ProductListItem;
