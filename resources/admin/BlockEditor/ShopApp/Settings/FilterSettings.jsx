import BlockEditorControl from "@/BlockEditor/Components/BlockEditorControl";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";

const {
    TextControl,
    ToggleControl,
} = wp.components

const FilterSettings = (props) => {
    const {attributes, setAttributes, blockEditorData} = props;

    const setFilters = (name, value) => {
        let filter = {...attributes.filters ?? {}};
        filter[name] = value;
        setAttributes({filters: {...filter}});
    }

    return (
        <BlockEditorControl title={blocktranslate('Filter Option')}>
            {/*<div className="fct-block-editor-control-item">*/}
            {/*    <div className="fct-inspector-control-row">*/}
            {/*        <span className="label">{blocktranslate('Order Type')}</span>*/}
            {/*        <div className="action" style={{width: '100px'}}>*/}

            {/*            <CustomSelect*/}
            {/*                placeholder={blocktranslate("Select")}*/}
            {/*                multiple={true}*/}
            {/*                customKeys={{*/}
            {/*                    key: 'value',*/}
            {/*                    label: 'label'*/}
            {/*                }}*/}
            {/*                defaultValue={attributes.order_type}*/}
            {/*                options={[*/}
            {/*                    {label: blocktranslate('ASC'), value: 'ASC'},*/}
            {/*                    {label: blocktranslate('DESC'), value: 'DESC'},*/}
            {/*                ]}*/}
            {/*                onChange={function (value) {*/}
            {/*                    setAttributes({order_type: value});*/}
            {/*                }}*/}
            {/*            />*/}
            {/*        </div>*/}
            {/*    </div>*/}
            {/*</div>*/}
            {/*<div className="fct-block-editor-control-item">*/}
            {/*    <div className="fct-inspector-control-row">*/}
            {/*        <span className="label">{blocktranslate('Order By')}</span>*/}
            {/*        <div className="action" style={{width: '100px'}}>*/}

            {/*            <CustomSelect*/}
            {/*                placeholder={blocktranslate("Select")}*/}
            {/*                multiple={true}*/}
            {/*                customKeys={{*/}
            {/*                    key: 'value',*/}
            {/*                    label: 'label'*/}
            {/*                }}*/}
            {/*                defaultValue={attributes.order_by}*/}
            {/*                options={[*/}
            {/*                    {label: blocktranslate('ID'), value: 'ID'},*/}
            {/*                    {label: blocktranslate('Title'), value: 'post_title'},*/}
            {/*                    {label: blocktranslate('Date'), value: 'post_date'}*/}
            {/*                ]}*/}
            {/*                onChange={function (value) {*/}
            {/*                    setAttributes({order_by: value});*/}
            {/*                }}*/}
            {/*            />*/}
            {/*        </div>*/}
            {/*    </div>*/}
            {/*</div>*/}

            <div className="fct-block-editor-control-item">
                <div className="fct-inspector-control-row">
                    <span className="label">{blocktranslate('Enable Filter')}</span>
                    <div className="actions">
                        <ToggleControl
                            checked={attributes.enable_filter}
                            onChange={(checked) => {
                                setAttributes({enable_filter: checked});
                            }}
                        />
                    </div>
                </div>
            </div>

            {
                attributes.enable_filter &&
                <>

                    <div className="fct-block-editor-control-item">
                        <div className="fct-inspector-control-row">
                            <span className="label">{blocktranslate('Wildcard Filter')}</span>
                            <div className="actions">
                                <ToggleControl
                                    checked={attributes.enable_wildcard_filter}
                                    onChange={(checked) => {
                                        setAttributes({enable_wildcard_filter: checked});
                                    }}
                                />
                            </div>
                        </div>
                    </div>

                    {
                        attributes.enable_wildcard_filter &&
                        <div className="fct-block-editor-control-item">
                            <div className="fct-inspector-control-row">
                                <span className="label">{blocktranslate('Also search in Content')}</span>
                                <div className="actions">
                                    <ToggleControl
                                        checked={attributes.enable_wildcard_for_post_content}
                                        onChange={(checked) => {
                                            setAttributes({enable_wildcard_for_post_content: checked});
                                        }}
                                    />
                                </div>
                            </div>
                        </div>
                    }

                    <div className="fct-block-editor-control-item">
                        <div className="fct-inspector-control-row">
                            <span className="label">{blocktranslate('Live Filter')}</span>
                            <div className="actions">
                                <ToggleControl
                                    checked={attributes.live_filter}
                                    onChange={(checked) => {
                                        setAttributes({live_filter: checked});
                                    }}
                                />
                            </div>
                        </div>
                    </div>

                    {
                        Object.keys(attributes.default_filter_options).map((key, index) => {

                            let filter = {};
                            if (attributes.filters?.hasOwnProperty(key)) {
                                filter = attributes.filters[key];
                            } else if(attributes.default_filter_options?.hasOwnProperty(key)) {
                                filter = attributes.default_filter_options[key];
                            }

                            return (key !== 'enabled') ?
                                <div className={'mt-5'} key={key}>
                                    <div className="fct-block-editor-control-item">
                                        <div className="fct-inspector-control-row">
                                            <span className="label capitalize">{key.replace(/[-_]/g, ' ')}</span>
                                            <div className="actions">
                                                <ToggleControl
                                                    checked={typeof attributes.filters !== 'undefined' && typeof attributes.filters[key] !== 'undefined' && attributes.filters[key]?.enabled}
                                                    onChange={(checked) => {

                                                        let filter = {};
                                                        if (attributes.filters?.hasOwnProperty(key)) {
                                                            filter = attributes.filters[key];
                                                        } else if(attributes.default_filter_options?.hasOwnProperty(key)) {
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
                                        </div>
                                    </div>

                                    <div className="fct-block-editor-control-item">
                                        <div className="fct-inspector-control-row">
                                            <span className="label">{blocktranslate('Display Name')}</span>
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
                                        </div>
                                    </div>
                                </div> : <></>
                        })
                    }

                </>
            }
        </BlockEditorControl>

    )
}

export default FilterSettings;
