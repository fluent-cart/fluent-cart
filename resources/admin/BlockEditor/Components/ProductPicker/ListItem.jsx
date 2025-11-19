import {CaretRight} from "@/BlockEditor/Icons";

const {useState} = wp.element;
const {CheckboxControl} = wp.components;
const placeholderImage = window.fluent_cart_block_editor_asset.placeholder_image;

const ListItem = (props) => {
    const {
        variant,
        title,
        media,
        stock,
        price,
        showCollapseButton = false,
        toggleCollapse = () => {
        },
        updateSelectedVariations,
        checked,
        isOpen,
        isMultiple = true
    } = props;

    const [isChecked, setChecked] = useState(checked);

    return <>

        <div className={`fct-collapsible-item ${showCollapseButton ? 'has-collapse-item' : ''}`}>
            <div className="fct-collapsible-content-wrapper">
                {showCollapseButton &&
                    <div className={`content-collapsible-btn ${isOpen ? 'is-collapsed' : ''}`} onClick={toggleCollapse}>
                        <div className="icon"><CaretRight/></div>
                    </div>}

                {!showCollapseButton &&
                    <>
                        <div className="content-checkbox">
                            <CheckboxControl
                                checked={isChecked && checked}
                                onChange={(checked) => {
                                    updateSelectedVariations(variant, checked)
                                    setChecked(checked)
                                }}
                            />
                        </div>
                    </>
                }

                <div className="content-img">
                    <img src={media ? media : placeholderImage} alt=""/>
                </div>

                <div className="content-title">
                    <div className="title">{title}</div>
                </div>

            </div>

            {stock && (
                <div className="fct-collapsible-stock-wrapper">
                    {stock}
                </div>
            )}

            <div className="fct-collapsible-value-wrapper">
                <span>{price}</span>
            </div>
        </div>
    </>;
}

export default ListItem;
