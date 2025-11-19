import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";



const {useBlockProps, InspectorControls, InnerBlocks} = wp.blockEditor;
const {ToggleControl, TextControl} = wp.components
const ProductFilterBlock = {
    attributes: {
        filter: {
            type: 'object',
            default: {}
        },
        enable_filter: {type: 'boolean', default: false},
        live_filter: {type: 'boolean', default: true},
        enable_wildcard_filter: {type: 'boolean', default: true},
        enable_wildcard_for_post_content: {type: 'boolean', default: true},
        default_filter_options: {}
    },
    usesContext: [
        'fluent-cart/paginator',
        'fluent-cart/per_page',
        'fluent-cart/enable_filter',
        'fluent-cart/product_box_grid_size',
        'fluent-cart/view_mode',
        'fluent-cart/filters',
        'fluent-cart/default_filters',
        'fluent-cart/order_type',
        'fluent-cart/order_by',
        'fluent-cart/live_filter',
        'fluent-cart/price_format',
        'fluent-cart/enable_wildcard_filter'
    ],
    edit: (props) => {

        const {attributes, setAttributes} = props;
        const setFilters = (name, value) => {
            let filter = {...attributes.filters ?? {}};
            filter[name] = value;
            setAttributes({filters: {...filter}});
        }

        const blockProps = useBlockProps({
            className: 'fluent-cart-product-filter-wrapper',
        });

        // get context
        const {context} = props;

        const enableFilter = context['fluent-cart/enable_filter'];

        return <div {...props} {...useBlockProps()}>
            <InspectorControls>
                <div className="fct-shop-filter-inspector-control-wrap fct-inspector-control-wrap">
                    <div className="fct-inspector-control-group">
                        <div className="fct-inspector-control-body">

                            <EditorPanelRow>
                                <span className="fct-inspector-control-label">
                                    {blocktranslate('Enable Filter')}
                                </span>
                                <div className="actions">
                                    <ToggleControl
                                        checked={attributes.enable_filter}
                                        onChange={(checked) => {
                                            setAttributes({
                                                enable_filter: checked
                                            });
                                        }}
                                    />
                                </div>
                            </EditorPanelRow>


                            {
                                attributes.enable_filter &&
                                <>

                                    <EditorPanelRow>
                                        <span className="fct-inspector-control-label">
                                            {blocktranslate('Wildcard Filter')}
                                        </span>
                                        <div className="actions">
                                            <ToggleControl
                                                checked={attributes.enable_wildcard_filter}
                                                onChange={(checked) => {
                                                    setAttributes({enable_wildcard_filter: checked});
                                                }}
                                            />
                                        </div>
                                    </EditorPanelRow>

                                    {
                                        attributes.enable_wildcard_filter &&
                                        <EditorPanelRow>
                                            <span className="fct-inspector-control-label">
                                                {blocktranslate('Also search in Content')}
                                            </span>
                                            <div className="actions">
                                                <ToggleControl
                                                        checked={attributes.enable_wildcard_for_post_content}
                                                        onChange={(checked) => {
                                                            setAttributes({enable_wildcard_for_post_content: checked});
                                                        }}
                                                    />
                                            </div>
                                        </EditorPanelRow>
                                    }

                                    <EditorPanelRow>
                                        <span className="fct-inspector-control-label">
                                            {blocktranslate('Live Filter')}
                                        </span>
                                        <div className="actions">
                                            <ToggleControl
                                                checked={attributes.live_filter}
                                                onChange={(checked) => {
                                                    setAttributes({live_filter: checked});
                                                }}
                                            />
                                        </div>
                                    </EditorPanelRow>

                                    {
                                        Object.keys(attributes.default_filter_options || {}).map((key, index) => {

                                            let filter = {};
                                            if (attributes.filters?.hasOwnProperty(key)) {
                                                filter = attributes.filters[key];
                                            } else if (attributes.default_filter_options?.hasOwnProperty(key)) {
                                                filter = attributes.default_filter_options[key];
                                            }

                                            return (key !== 'enabled') ?
                                                <div className={'mt-5'} key={key}>
                                                    <EditorPanelRow>
                                                        <span className="fct-inspector-control-label capitalize">
                                                            {key.replace(/[-_]/g, ' ')}
                                                        </span>
                                                        <div className="actions">
                                                            <ToggleControl
                                                                checked={typeof attributes.filters !== 'undefined' && typeof attributes.filters[key] !== 'undefined' && attributes.filters[key]?.enabled}
                                                                onChange={(checked) => {

                                                                    let filter = {};
                                                                    if (attributes.filters?.hasOwnProperty(key)) {
                                                                        filter = attributes.filters[key];
                                                                    } else if (attributes.default_filter_options?.hasOwnProperty(key)) {
                                                                        filter = attributes.default_filter_options[key];
                                                                    }

                                                                    filter['enabled'] = checked
                                                                    filter['filter_type'] = attributes.default_filter_options[key].filter_type
                                                                    filter['is_meta'] = attributes.default_filter_options[key].is_meta
                                                                    filter['multiple'] = attributes.default_filter_options[key].multiple
                                                                    setFilters(key, filter)

                                                                }}
                                                            />
                                                        </div>
                                                    </EditorPanelRow>


                                                    <EditorPanelRow>
                                                        <span className="fct-inspector-control-label">
                                                            {blocktranslate('Display Name')}
                                                        </span>
                                                        <div className="actions">
                                                            <TextControl
                                                                value={filter.label}
                                                                onChange={(value) => {
                                                                    let filter = {};
                                                                    if (attributes.filters?.hasOwnProperty(key)) {
                                                                        filter = attributes.filters[key];
                                                                    } else {
                                                                        filter['enabled'] = true
                                                                    }

                                                                    filter['label'] = value
                                                                    setFilters(key, filter)

                                                                }}
                                                            />
                                                        </div>
                                                    </EditorPanelRow>
                                                    
                                                </div> : <></>
                                        })
                                    }

                                </>
                            }
                        </div>
                    </div>
                </div>
            </InspectorControls>

            <div {...blockProps} >
                {enableFilter && <InnerBlocks
                    allowedBlocks={[
                        'fluent-cart/shopapp-product-filter-search-box',
                        'fluent-cart/shopapp-product-filter-button'
                    ]}
                />}

            </div>
        </div>;
    },

    save: (props) => {
        const blockProps = useBlockProps.save();
        return (
            <div {...blockProps} className="fluent-cart-product-filter-wrapper">
                <InnerBlocks.Content/>
            </div>
        );
    },
};

export default ProductFilterBlock;
