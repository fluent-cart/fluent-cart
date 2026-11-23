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

    // The saved filter for a key, else the block's default for it.
    const filterFor = (key) => (
        attributes.filters?.hasOwnProperty(key) ? attributes.filters[key] : (attributes.default_filter_options?.[key] || {})
    );

    const filterKeys = Object.keys(attributes.default_filter_options || {}).filter((key) => key !== 'enabled');

    // A switch row; a note under it is tied to the switch.
    const switchRow = (label, checked, onChange, help) => (
        <ToggleControl
            label={label}
            help={help}
            checked={!!checked}
            onChange={onChange}
            __nextHasNoMarginBottom
        />
    );

    return (
        <BlockEditorControl title={blocktranslate('Filter Option')}>
            <div className="fct-sidebar-stack">
                {switchRow(
                    blocktranslate('Enable Filter'),
                    attributes.enable_filter,
                    (checked) => setAttributes({enable_filter: checked})
                )}

                {attributes.enable_filter && (
                    <>
                        {switchRow(
                            blocktranslate('Wildcard Filter'),
                            attributes.enable_wildcard_filter,
                            (checked) => setAttributes({enable_wildcard_filter: checked})
                        )}

                        {attributes.enable_wildcard_filter && (
                            <div className="fct-sidebar-nested">
                                {switchRow(
                                    blocktranslate('Also search in Content'),
                                    attributes.enable_wildcard_for_post_content,
                                    (checked) => setAttributes({enable_wildcard_for_post_content: checked})
                                )}
                            </div>
                        )}

                        {switchRow(
                            blocktranslate('Live Filter'),
                            attributes.live_filter,
                            (checked) => setAttributes({live_filter: checked})
                        )}

                        {filterKeys.map((key) => {
                            const filter = filterFor(key);
                            const isOn = !!attributes.filters?.[key]?.enabled;

                            return (
                                <div className="fct-sidebar-section" key={key}>
                                    {switchRow(
                                        <span className="capitalize">{key.replace(/[-_]/g, ' ')}</span>,
                                        isOn,
                                        (checked) => setFilters(key, {
                                            ...filterFor(key),
                                            enabled: checked,
                                            filter_type: attributes.default_filter_options[key].filter_type,
                                            is_meta: attributes.default_filter_options[key].is_meta,
                                            multiple: attributes.default_filter_options[key].multiple,
                                        })
                                    )}

                                    {isOn && (
                                        <div className="fct-sidebar-nested">
                                            <TextControl
                                                label={blocktranslate('Display Name')}
                                                labelPosition="top"
                                                className="fct-sidebar-field"
                                                value={filter.label || ''}
                                                onChange={(value) => setFilters(key, {...(attributes.filters?.[key] || {enabled: true}), label: value})}
                                                __nextHasNoMarginBottom
                                                __next40pxDefaultSize
                                            />

                                            {filter.filter_type === 'options' && filter.enabled && (
                                                switchRow(
                                                    blocktranslate('Show empty'),
                                                    filter.show_empty !== false,
                                                    (checked) => setFilters(key, {...filterFor(key), show_empty: checked}),
                                                    /* translators: %1$s: taxonomy name (e.g. "categories", "brands") */
                                                    blocktranslate('Display %1$s even if they have no products.', key.replace('product-', ''))
                                                )
                                            )}
                                        </div>
                                    )}
                                </div>
                            );
                        })}

                        {attributeGroups.length > 0 && (
                            <div className="fct-sidebar-section">
                                {switchRow(
                                    blocktranslate('Attribute Filters'),
                                    attributeFiltersActive,
                                    (checked) => saveAttributeFilters({active: checked})
                                )}

                                {attributeFiltersActive && (
                                    <div className="fct-sidebar-nested">
                                        <div className="fct-sidebar-field">
                                            <span className="fct-sidebar-label">{blocktranslate('Attributes')}</span>
                                            <CustomSelect
                                                isMulti={true}
                                                customKeys={{key: 'value', label: 'label'}}
                                                defaultValue={selectedGroups.map((group) => group.id)}
                                                options={selectableAttributeGroups.map((group) => ({value: group.id, label: group.title}))}
                                                onChange={(groupIds) => saveAttributeFilters({groupIds: groupIds.slice(0, attributeGroupLimit)})}
                                            />
                                            {isAttributeGroupLimitReached &&
                                                <p className="components-base-control__help" role="status">{blockEditorData?.attribute_group_limit_note}</p>
                                            }
                                        </div>

                                        {switchRow(
                                            blocktranslate('Show counts'),
                                            attributeShowCount,
                                            (checked) => saveAttributeFilters({showCount: checked})
                                        )}

                                        {switchRow(
                                            blocktranslate('Show empty'),
                                            attributeShowEmpty,
                                            (checked) => saveAttributeFilters({showEmpty: checked}),
                                            blocktranslate('Display attribute values even if no product uses them.')
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </>
                )}
            </div>
        </BlockEditorControl>
    );
}

export default FilterSettings;
