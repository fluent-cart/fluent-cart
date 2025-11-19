import SearchBarModes from "./SearchBarModes";
import SearchBarPreview from "./Preview/SearchBarPreview";
import {ProductSearch} from "../Icons";
import ProductSearchPreview from "./ProductSearch.png";

const {registerBlockType} = wp.blocks;
const blockEditorData = window.fluent_cart_fluent_products_search_bar_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    title: blockEditorData.title,
    example: {
        attributes: {
        },
        innerBlocks: [
            {
                name: 'core/image',
                attributes: {
                    url: ProductSearchPreview,
                    alt: 'Product Search Preview',
                    style: {
                        width: '100%',
                        height: 'auto',
                    }
                }
            },
        ],
    },
    icon: {
        src: ProductSearch
    },
    category: 'fluent-cart',
    attributes: {
        url_mode: {
            type: 'string',
            default: '',
        },
        category_mode: {
            type: 'boolean',
            default: false,
        },
        link_with_shop_app: {
            type: 'boolean',
            default: false,
        }
    },

    edit: function ({attributes, setAttributes}) {
        const { useBlockProps } = wp.blockEditor;
        const blockProps = useBlockProps();
        return (
            <div {...blockProps}>
                <div className="fluent-cart-searchbar-block-editor">
                    <SearchBarModes attributes={attributes} setAttributes={setAttributes}/>
                    <SearchBarPreview attributes={attributes.category_mode} setAttributes={setAttributes}/>
                </div>
            </div>
        );
    },

    save: function (props) {
        return null;
    },
});
