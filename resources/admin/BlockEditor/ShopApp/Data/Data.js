import color from "../../../../styles/tailwind/extends/color";

let _taxonomyDefaultFilters = [];
let _taxonomyDefaultFilterOptions = {};
Object.keys(window.fluent_cart_shop_app_block_editor_data.taxonomies).map((key, index) => {
    let filter = window.fluent_cart_shop_app_block_editor_data.taxonomies[key];
    _taxonomyDefaultFilters[key] = [];
    _taxonomyDefaultFilterOptions[key] = {
        filter_type: 'options',
        is_meta: true,
        label: filter.label,
        enabled: false,
        multiple: false,
    }
})


const DefaultData = {
    paginator: {type: 'string', default: 'scroll'}, //numbers
    per_page: {type: 'number', default: 10},
    // view_mode: {type: 'string', default: 'default'},
    view_mode: {type: 'string', default: 'grid'},
    price_format: {type: 'string', default: 'starts_from'}, //range
    search_grid_size: {type: 'number', default: 0},
    product_grid_size: {type: 'number', default: 0},
    product_box_grid_size: {type: 'number', default: 0},
    use_default_style: {type: 'boolean', default: true},
    enable_filter: {type: 'boolean', default: false},
    live_filter: {type: 'boolean', default: true},
    enable_wildcard_filter: {type: 'boolean', default: false},
    enable_wildcard_for_post_content: {type: 'boolean', default: false},
    order_type: {
        type: 'string',
        default: 'DESC'
    },
    order_by: {
        type: 'string',
        default: 'ID'
    },
    filters: {},
    default_filter_options: {
        type: 'object',
        default: {
            ..._taxonomyDefaultFilterOptions,
            price_range: {
                filter_type: 'range',
                is_meta: false,
                label: 'Price',
                enabled: false,
            }
        }
    },
    default_filters: {
        type: 'object',
        default: {
            enabled: false,
            allow_out_of_stock: false,
            wildcard: '',
            ..._taxonomyDefaultFilters
        }
    },

    colors: {
        //'--shop-app-primary-color': color.dark["700"]
    }
}

export default DefaultData;
