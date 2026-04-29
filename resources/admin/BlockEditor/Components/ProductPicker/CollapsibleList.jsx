import ListItem from "./ListItem";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const CollapsibleList = (props) => {
    const {
        variants,
        isOpen,
        updateSelectedVariations,
        selectedVariations,
        isMultiple,
        allow_subscription = true
    } = props;

    return <>
        <div className={`fct-collapsible-item-child-container ${isOpen ? 'is-collapsed' : ''}`}>
            <ul className="fct-collapsible-item-child-inner">
                {Object.entries(variants).map((variant, index) => {
                    const stockStatus = variants[index].available === 0
                        ? blocktranslate("Unlimited")
                        : /* translators: %d - number of items available */
                        blocktranslate('%d Available', variants[index].available);

                    return <li key={index}>
                        <ListItem
                            isMultiple={isMultiple}
                            variant={variants[index]}
                            title={variants[index].variation_title}
                            media={variants[index].thumbnail}
                            stock={stockStatus}
                            // price={variants[index].item_price/100}
                            price={''}
                            updateSelectedVariations={updateSelectedVariations}
                            checked={Object.keys(selectedVariations).includes(String(variants[index].id))}
                        />
                    </li>
                })}
            </ul>

        </div>
    </>
}
export default CollapsibleList;
