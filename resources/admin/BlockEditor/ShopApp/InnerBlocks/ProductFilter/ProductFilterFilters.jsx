const {useBlockProps, InspectorControls} = wp.blockEditor;
const {ToggleControl, TextControl} = wp.components
const ProductFilterFilters = {
    attributes: {},
    edit: (props) => {

        const {attributes, setAttributes} = props;
        const setFilters = (name, value) => {
            let filter = {...attributes.filters ?? {}};
            filter[name] = value;
            setAttributes({filters: {...filter}});
        }
        return <div {...props} {...useBlockProps()}>
            <h2>Product Filters</h2>
        </div>;
    },
    save: (props) => {
        const blockProps = useBlockProps.save();
        return null;
    }
};

export default ProductFilterFilters;
