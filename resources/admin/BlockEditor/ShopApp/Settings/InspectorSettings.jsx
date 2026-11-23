import PaginatorSettings from "@/BlockEditor/ShopApp/Settings/PaginatorSettings";
import FilterSettings from "./FilterSettings";
import BlockEditorControl from "@/BlockEditor/Components/BlockEditorControl";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";
import SegmentedToggle from "@/BlockEditor/Components/SegmentedToggle";
import RangeField from "@/BlockEditor/Components/RangeField";

const {InspectorControls} = wp.blockEditor

const {
    TextControl,
    ToggleControl,
    SelectControl
} = wp.components

// 0 leaves the block's own default; 1 to 6 are the sizes the server accepts
// (ShopAppHandler::handelGridSizes). Raise GRID_SIZE_MAX only with that limit.
const GRID_SIZE_MAX = 6;
const GRID_SIZE_CHOICES = Array.from({length: GRID_SIZE_MAX + 1}, (unused, size) => size);

const InspectorSettings = (props) => {
    const {attributes, setAttributes, blockEditorData, hideViewMode = false} = props;

    const defaultFilters = attributes.default_filters || {};
    const taxonomies = window.fluent_cart_shop_app_block_editor_data?.taxonomies || {};
    // Saves a copy with the change, never the saved object edited in place.
    const setDefaultFilters = (change) => setAttributes({default_filters: {...defaultFilters, ...change}});

    // How many of the layout's columns the filter or the products take: a slider, Default at 0.
    const gridSpanField = (label, attribute) => (
        <RangeField
            label={label}
            value={Number(attributes[attribute]) || 0}
            min={0}
            max={GRID_SIZE_MAX}
            zeroLabel={blocktranslate('Default')}
            onChange={(size) => setAttributes({[attribute]: size})}
        />
    );

    // A grid size toggle: Default for 0, then 1 to 6. A missing value counts as the default.
    const gridSizeToggle = (label, attribute, help) => (
        <SegmentedToggle
            label={label}
            help={help}
            options={GRID_SIZE_CHOICES.map((size) => ({value: size, label: size === 0 ? blocktranslate('Default') : String(size)}))}
            value={Number(attributes[attribute]) || 0}
            onChange={(size) => setAttributes({[attribute]: size})}
        />
    );

    return (

        <InspectorControls key="setting">
            <div id="gutenpride-controls" className="fluent-cart-product-block-editor">
                <div className="fluent-cart-product-block-editor-inner">
                    <PaginatorSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        blockEditorData={blockEditorData}
                    />

                    <BlockEditorControl title={blocktranslate('Product Grid Option')}>
                        <div className="fct-sidebar-stack">
                            {!hideViewMode && (
                                <SegmentedToggle
                                    label={blocktranslate('View Mode')}
                                    options={[
                                        {value: 'grid', label: blocktranslate('Grid')},
                                        {value: 'list', label: blocktranslate('List')},
                                    ]}
                                    value={attributes.view_mode}
                                    onChange={(viewMode) => setAttributes({view_mode: viewMode})}
                                />
                            )}

                            <SelectControl
                                label={blocktranslate('Price Format')}
                                labelPosition="top"
                                className="fct-sidebar-select"
                                value={attributes.price_format}
                                options={[
                                    {label: blocktranslate('Starts from'), value: 'starts_from'},
                                    {label: blocktranslate('Range'), value: 'range'},
                                ]}
                                onChange={(priceFormat) => setAttributes({price_format: priceFormat})}
                                __nextHasNoMarginBottom
                                __next40pxDefaultSize
                            />

                            {gridSizeToggle(
                                blocktranslate("Product Per Row"),
                                'product_box_grid_size',
                                blocktranslate("Layout cards show fewer per row where each would be under 220px wide.")
                            )}

                            {attributes.enable_filter && gridSpanField(blocktranslate("Search Grid Size"), 'search_grid_size')}

                            {attributes.enable_filter && gridSpanField(blocktranslate("Product Grid Size"), 'product_grid_size')}
                        </div>
                    </BlockEditorControl>

                    <FilterSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        blockEditorData={blockEditorData}
                    />

                    <BlockEditorControl title={blocktranslate('Default Filter')}>
                        <div className="fct-sidebar-stack">
                            <ToggleControl
                                label={blocktranslate('Enable Filtering')}
                                checked={!!defaultFilters.enabled}
                                onChange={(checked) => setDefaultFilters({enabled: checked})}
                                __nextHasNoMarginBottom
                            />

                            {defaultFilters.enabled && (
                                <div className="fct-sidebar-nested">
                                    <ToggleControl
                                        label={blocktranslate('Allow Out Of Stock')}
                                        checked={!!defaultFilters.allow_out_of_stock}
                                        onChange={(checked) => setDefaultFilters({allow_out_of_stock: checked})}
                                        __nextHasNoMarginBottom
                                    />

                                    <TextControl
                                        label={blocktranslate('Search')}
                                        labelPosition="top"
                                        className="fct-sidebar-field"
                                        value={defaultFilters.wildcard || ''}
                                        onChange={(value) => setDefaultFilters({wildcard: value})}
                                        __nextHasNoMarginBottom
                                        __next40pxDefaultSize
                                    />

                                    {Object.keys(taxonomies).map((key) => (
                                        <div className="fct-sidebar-field" key={key}>
                                            <span className="fct-sidebar-label">{taxonomies[key].label}</span>
                                            <CustomSelect
                                                placeholder={blocktranslate('Select')}
                                                isMulti={true}
                                                customKeys={{key: 'value', label: 'label'}}
                                                defaultValue={defaultFilters[key]}
                                                options={taxonomies[key].terms}
                                                onChange={(selectedFilters) => setDefaultFilters({[key]: selectedFilters})}
                                            />
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </BlockEditorControl>
                </div>
            </div>
        </InspectorControls>
    );
};
export default InspectorSettings;
