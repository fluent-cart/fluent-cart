import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useBlockProps, InspectorControls} = wp.blockEditor;
const {ToggleControl, TextControl} = wp.components
const ProductFilterSearchBlock = {
    attributes: {},
    edit: (props) => {

        const {attributes, setAttributes} = props;
        const setFilters = (name, value) => {
            let filter = {...attributes.filters ?? {}};
            filter[name] = value;
            setAttributes({filters: {...filter}});
        }
        return <div {...props} {...useBlockProps()}>
            <div className="fct-product-block-filter-item">
                <div className="relative w-full">
                    <div className="absolute top-0 left-[14px] bottom-0 flex items-center text-system-mid">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 18 18" fill="none">
                            <path d="M13.125 13.125L16.5 16.5" stroke="currentColor" strokeWidth="1.25"
                                  strokeLinecap="round" strokeLinejoin="round"></path>
                            <path
                                d="M15 8.25C15 4.52208 11.9779 1.5 8.25 1.5C4.52208 1.5 1.5 4.52208 1.5 8.25C1.5 11.9779 4.52208 15 8.25 15C11.9779 15 15 11.9779 15 8.25Z"
                                stroke="currentColor" strokeWidth="1.25" strokeLinejoin="round"></path>
                        </svg>
                    </div>
                    <input className="fct-product-block-input" type="text" name="wildcard"
                           placeholder={blocktranslate("Search products by name")} value=""/></div>
            </div>
        </div>;
    },
    save: (props) => {
        const blockProps = useBlockProps.save();
        return null;
    }
};

export default ProductFilterSearchBlock;
