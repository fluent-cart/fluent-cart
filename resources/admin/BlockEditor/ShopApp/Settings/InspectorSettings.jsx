import Styler from "@/BlockEditor/ShopApp/Styler";
import PaginatorSettings from "@/BlockEditor/ShopApp/Settings/PaginatorSettings";
import FilterSettings from "./FilterSettings";
import DefaultData from "@/BlockEditor/ShopApp/Data/Data";
import {log10} from "chart.js/helpers";
import BlockEditorControl from "@/BlockEditor/Components/BlockEditorControl";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";

const {InspectorControls} = wp.blockEditor

const {
    TextControl,
    Card,
    CardHeader,
    CardBody,
    ToggleControl,
    Button,
    Tooltip,
    __experimentalRadio: Radio,
    __experimentalRadioGroup: RadioGroup,
    RangeControl
} = wp.components

const InspectorSettings = (props) => {
    const {attributes, setAttributes, blockEditorData} = props;

    const setSearchGridSize = (size) => {
        setAttributes({search_grid_size: size});
    };

    const setProductGridSize = (size) => {
        setAttributes({product_grid_size: size});
    };

    const setBoxGridSize = (size) => {
        setAttributes({product_box_grid_size: size});
    };

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
                        <div className="fct-block-editor-control-item">
                            <div className="fct-inspector-control-row">
                                <span className="label">{blocktranslate('View Mode')}</span>
                                <div className="actions">
                                    <CustomSelect
                                        customKeys={{
                                            key: 'value',
                                            label: 'label'
                                        }}
                                        defaultValue={attributes.view_mode}
                                        options={[
                                            // {label: blocktranslate('Default'), value: 'default'},
                                            {label: blocktranslate('Grid'), value: 'grid'},
                                            {label: blocktranslate('List'), value: 'list'},
                                        ]}
                                        onChange={function (value) {
                                            // if (typeof value === "object") {
                                            //     setAttributes({view_mode: value});
                                            // } else {
                                            //     // setAttributes({view_mode: 'default'});
                                            // }
                                            setAttributes({view_mode: value});
                                        }}
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="fct-block-editor-control-item">
                            <div className="fct-inspector-control-row">
                                <span className="label">{blocktranslate('Price Format')}</span>
                                <div className="actions">
                                    <CustomSelect
                                        customKeys={{
                                            key: 'value',
                                            label: 'label'
                                        }}
                                        defaultValue={attributes.price_format}
                                        options={[
                                            // {label: blocktranslate('Default'), value: 'default'},
                                            {label: blocktranslate('Starts From'), value: 'starts_from'},
                                            {label: blocktranslate('Range'), value: 'range'},
                                        ]}
                                        onChange={function (value) {
                                            // if (typeof value === "object") {
                                            //
                                            //     setAttributes({price_format: value});
                                            // } else {
                                            //     // setAttributes({view_mode: 'default'});
                                            //     setAttributes({price_format: 'starts_from'});
                                            // }
                                            setAttributes({price_format: value});
                                        }}
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="fct-block-editor-control-item">
                            <div className="fct-inspector-control-row">
                                <span className="label">{blocktranslate("Product Per Row")}</span>
                                <div className="actions">
                                    <RangeControl
                                        value={attributes.product_box_grid_size}
                                        onChange={setBoxGridSize}
                                        min={0}
                                        max={5}
                                    />
                                </div>
                            </div>
                        </div>

                        {
                            attributes.enable_filter &&
                            <div className="fct-block-editor-control-item">
                                <div className="fct-inspector-control-row">
                                    <span className="label">{blocktranslate("Search Grid Size")}</span>
                                    <div className="actions">
                                        <RangeControl
                                            value={attributes.search_grid_size}
                                            onChange={setSearchGridSize}
                                            min={0}
                                            max={5}
                                        />
                                    </div>
                                </div>
                            </div>
                        }

                        {
                            attributes.enable_filter &&
                            <div className="fct-block-editor-control-item">
                                <div className="fct-inspector-control-row">
                                    <span className="label">{blocktranslate("Product Grid Size")}</span>
                                    <div className="actions">
                                        <RangeControl
                                            value={attributes.product_grid_size}
                                            onChange={setProductGridSize}
                                            min={0}
                                            max={5}
                                        />
                                    </div>
                                </div>
                            </div>
                        }

                    </BlockEditorControl>

                    <FilterSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        blockEditorData={blockEditorData}
                    />

                    <BlockEditorControl title={blocktranslate('Default Filter')}>
                        <div>
                            <div className="fct-block-editor-control-item">
                                <div className="fct-inspector-control-row">
                                    <span className="label">{blocktranslate("Enable Filtering")}</span>
                                    <div className="actions">
                                        <ToggleControl
                                            checked={attributes.default_filters.enabled}
                                            onChange={(checked) => {
                                                let default_filters = attributes.default_filters;
                                                default_filters.enabled = checked;
                                                setAttributes({
                                                    default_filters: {...default_filters}
                                                });
                                            }}
                                        />
                                    </div>
                                </div>
                            </div>

                            {

                                attributes.default_filters.enabled && <>
                                    <div className="fct-block-editor-control-item">
                                        <div className="fct-inspector-control-row">
                                            <span className="label">{blocktranslate("Allow Out Of Stock")}</span>
                                            <div className="actions">
                                                <ToggleControl
                                                    checked={attributes.default_filters.allow_out_of_stock}
                                                    onChange={(checked) => {
                                                        let default_filters = attributes.default_filters;
                                                        default_filters.allow_out_of_stock = checked
                                                        setAttributes({default_filters: {...default_filters}});
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <div className="fct-block-editor-control-item">
                                        <div className="fct-inspector-control-row">
                                            <span className="label">{blocktranslate("Search")}</span>
                                            <div className="actions">
                                                <TextControl
                                                    value={attributes.default_filters.wildcard}
                                                    onChange={(value) => {
                                                        let filters = attributes.default_filters;
                                                        filters.wildcard = value
                                                        setAttributes({default_filters: {...filters}});
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    {
                                        Object.keys(window.fluent_cart_shop_app_block_editor_data.taxonomies).map((key, index) => {
                                            let filter = window.fluent_cart_shop_app_block_editor_data.taxonomies[key];
                                            return <div className="fct-block-editor-control-item" key={index}>
                                                <div className="fct-inspector-control-row">
                                                    <span className="label">{filter.label}</span>
                                                    <div className="actions">
                                                        <CustomSelect
                                                            placeholder={blocktranslate("Select")}
                                                            isMulti={true}
                                                            customKeys={{
                                                                key: 'value',
                                                                label: 'label'
                                                            }}
                                                            defaultValue={attributes.default_filters[key]}
                                                            options={filter.terms}
                                                            onChange={function (selectedFilters) {
                                                                let data = [];
                                                                // if (selectedFilters.length > 0) {
                                                                //     for (filter of selectedFilters) {
                                                                //         if (filter.hasOwnProperty('key')) {
                                                                //             data.push(filter.key);
                                                                //         } else if (filter.hasOwnProperty('value')) {
                                                                //             data.push(filter.value);
                                                                //         }
                                                                //     }
                                                                // }

                                                                let filters = { ...attributes.default_filters };
                                                                filters[key] = selectedFilters;
                                                                setAttributes({
                                                                    default_filters: filters
                                                                });
                                                            }}
                                                        />
                                                    </div>
                                                </div>

                                            </div>
                                        })
                                    }

                                </>
                            }
                        </div>
                    </BlockEditorControl>
                </div>
            </div>
        </InspectorControls>
    );
};
export default InspectorSettings;
