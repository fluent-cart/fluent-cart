import BlockEditorControl from "@/BlockEditor/Components/BlockEditorControl";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";

const {
    TextControl,
    ToggleControl,
} = wp.components

const FilterSettings = (props) => {
    const {attributes, setAttributes, blockEditorData} = props;
    const attributeGroups = blockEditorData?.attribute_groups || [];

    const setFilters = (name, value) => {
        let filter = {...attributes.filters ?? {}};
        filter[name] = value;
        setAttributes({filters: {...filter}});
    }

    // One settings entry for the section; each chosen group is saved as its own attr_{id}
    // filter, which is the shape the sidebar renderer reads.
    const attributeSettings = attributes.filters?.attribute_filters || {};
    const attributeFiltersActive = attributeSettings.active === true;
    // Both off until turned on.
    const attributeShowCount = attributeSettings.show_count === true;
    const attributeShowEmpty = attributeSettings.show_empty === true;
    const selectedGroups = Object.values(attributes.filters || {})
        .filter((filter) => filter?.filter_type === 'attribute')
        .map((filter) => attributeGroups.find((group) => Number(group.id) === Number(filter.group_id)))
        .filter(Boolean);
    // The storefront drops groups past this limit, so at the limit only chosen groups are listed.
    const attributeGroupLimit = Number(blockEditorData?.attribute_group_limit) || attributeGroups.length;
    const isAttributeGroupLimitReached = selectedGroups.length >= attributeGroupLimit;
    const selectableAttributeGroups = isAttributeGroupLimitReached ? selectedGroups : attributeGroups;

    const saveAttributeFilters = ({
        active = attributeFiltersActive,
        groupIds = selectedGroups.map((group) => group.id),
        showCount = attributeShowCount,
        showEmpty = attributeShowEmpty,
    }) => {
        const filters = {};
        Object.entries(attributes.filters || {}).forEach(([key, filter]) => {
            if (filter?.filter_type !== 'attribute') {
                filters[key] = filter;
            }
        });

        filters.attribute_filters = {filter_type: 'attribute_settings', active, show_count: showCount, show_empty: showEmpty};
        groupIds.forEach((groupId) => {
            filters['attr_' + groupId] = {
                filter_type: 'attribute',
                is_meta: false,
                group_id: groupId,
                show_count: showCount,
                show_empty: showEmpty,
                enabled: active,
            };
        });

        setAttributes({filters});
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

                                    {filter.filter_type === 'options' && filter.enabled &&
                                        <div className="fct-block-editor-control-item">
                                            <div className="fct-inspector-control-row">
                                                <span className="label">{blocktranslate('Show empty')}</span>
                                                <div className="actions">
                                                    <ToggleControl
                                                        checked={filter.show_empty !== false}
                                                        onChange={(checked) => {
                                                            let updatedFilter = {};
                                                            if (attributes.filters?.hasOwnProperty(key)) {
                                                                updatedFilter = {...attributes.filters[key]};
                                                            } else if (attributes.default_filter_options?.hasOwnProperty(key)) {
                                                                updatedFilter = {...attributes.default_filter_options[key]};
                                                            }
                                                            updatedFilter['show_empty'] = checked;
                                                            setFilters(key, updatedFilter);
                                                        }}
                                                    />
                                                </div>
                                            </div>
                                            {/* translators: %1$s: taxonomy name (e.g. "categories", "brands") */}
                                            <span className="text-xs text-system-light leading-tight">
                                                {blocktranslate('Display %1$s even if they have no products.', key.replace('product-', ''))}
                                            </span>
                                        </div>
                                    }
                                </div> : <></>
                        })
                    }

                    {attributeGroups.length > 0 &&
                        <div className="mt-5">
                            <div className="fct-block-editor-control-item">
                                <div className="fct-inspector-control-row">
                                    <span className="label">{blocktranslate('Attribute Filters')}</span>
                                    <div className="actions">
                                        <ToggleControl
                                            aria-label={blocktranslate('Attribute Filters')}
                                            checked={attributeFiltersActive}
                                            onChange={(checked) => saveAttributeFilters({active: checked})}
                                        />
                                    </div>
                                </div>
                            </div>
                            {attributeFiltersActive &&
                                <>
                                    <div className="fct-block-editor-control-item">
                                        <div className="fct-inspector-control-row">
                                            <span className="label">{blocktranslate('Attributes')}</span>
                                            <div className="actions">
                                                <CustomSelect
                                                    isMulti={true}
                                                    customKeys={{key: 'value', label: 'label'}}
                                                    defaultValue={selectedGroups.map((group) => group.id)}
                                                    options={selectableAttributeGroups.map((group) => ({value: group.id, label: group.title}))}
                                                    onChange={(groupIds) => saveAttributeFilters({groupIds: groupIds.slice(0, attributeGroupLimit)})}
                                                />
                                            </div>
                                        </div>
                                        {isAttributeGroupLimitReached &&
                                            <p className="components-base-control__help" role="status">{blockEditorData?.attribute_group_limit_note}</p>
                                        }
                                    </div>
                                    <div className="fct-block-editor-control-item">
                                        <div className="fct-inspector-control-row">
                                            <span className="label">{blocktranslate('Show counts')}</span>
                                            <div className="actions">
                                                <ToggleControl
                                                    aria-label={blocktranslate('Show counts')}
                                                    checked={attributeShowCount}
                                                    onChange={(checked) => saveAttributeFilters({showCount: checked})}
                                                />
                                            </div>
                                        </div>
                                    </div>
                                    <div className="fct-block-editor-control-item">
                                        <div className="fct-inspector-control-row">
                                            <span className="label">{blocktranslate('Show empty')}</span>
                                            <div className="actions">
                                                <ToggleControl
                                                    aria-label={blocktranslate('Show empty')}
                                                    checked={attributeShowEmpty}
                                                    onChange={(checked) => saveAttributeFilters({showEmpty: checked})}
                                                />
                                            </div>
                                        </div>
                                        <span className="text-xs text-system-light leading-tight">
                                            {blocktranslate('Display attribute values even if no product uses them.')}
                                        </span>
                                    </div>
                                </>
                            }
                        </div>
                    }

                </>
            }
        </BlockEditorControl>

    )
}

export default FilterSettings;
