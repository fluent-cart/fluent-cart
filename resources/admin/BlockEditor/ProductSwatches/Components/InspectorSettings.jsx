const {InspectorControls} = wp.blockEditor;
const {TabPanel} = wp.components;
const {useRef, useState} = wp.element;

import CardPreview, {previewExample} from "@/BlockEditor/ProductSwatches/Components/CardPreview";
import SettingSwitch from "@/BlockEditor/ProductSwatches/Components/SettingSwitch";
import SwatchAttributeListSetting from "@/BlockEditor/ProductSwatches/Components/SwatchAttributeListSetting";
import SwatchOptionNameSetting from "@/BlockEditor/ProductSwatches/Components/SwatchOptionNameSetting";
import SwatchSelectedChoiceSetting, {listShowsSelected} from "@/BlockEditor/ProductSwatches/Components/SwatchSelectedChoiceSetting";
import SwatchSoldOutSetting from "@/BlockEditor/ProductSwatches/Components/SwatchSoldOutSetting";
import SwatchCountSetting, {normalizeVisibleCount} from "@/BlockEditor/ProductSwatches/Components/SwatchCountSetting";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

// Opens each tab: its on/off switch and a card drawn from its settings.
const TabTop = ({type, title, enabled, onToggle, preview}) => (
    <div className="fct-swatch-tab-top">
        <SettingSwitch
            id={'fct-swatch-switch-' + type}
            title={title}
            checked={enabled}
            onChange={onToggle}
            large
        />
        <CardPreview type={type} enabled={enabled} {...preview}/>
    </div>
);

// How it shows on the card, as in the swatches design: how many, the label and the selected choice,
// grouped under one heading with no dividers between them.
const CardDisplaySection = ({type, attributes, setAttributes, lastCounts, setLastCounts}) => {
    const countKey = type === 'attribute' ? 'max_visible' : 'simple_max_visible';
    return (
        <section className="fct-swatch-section fct-swatch-card-display" aria-labelledby={'fct-swatch-card-display-' + type}>
            <h3 id={'fct-swatch-card-display-' + type} className="fct-swatch-group-heading">{blocktranslate('How it shows on the card')}</h3>
            <SwatchCountSetting
                type={type}
                value={attributes[countKey]}
                lastNumber={lastCounts[type]}
                onLastNumber={(number) => setLastCounts((prev) => (prev[type] === number ? prev : {...prev, [type]: number}))}
                onChange={(value) => setAttributes({[countKey]: value})}
            />
            <SwatchOptionNameSetting type={type} attributes={attributes} setAttributes={setAttributes}/>
            <SwatchSelectedChoiceSetting
                type={type}
                attributes={attributes}
                setAttributes={setAttributes}
                example={previewExample(type, attributes.simple_label)}
                maxVisible={normalizeVisibleCount(attributes[countKey])}
            />
        </section>
    );
};

const AttributeTab = ({attributes, setAttributes, attributeGroups, lastCounts, setLastCounts}) => (
    <>
        <TabTop
            type="attribute"
            title={blocktranslate('Show attributes on the card')}
            enabled={attributes.advanced_enabled}
            onToggle={(checked) => setAttributes({advanced_enabled: checked})}
            preview={{
                maxVisible: attributes.max_visible,
                showName: attributes.show_group_name,
                showSelected: !!attributes.show_label,
                selectedBelow: attributes.selected_position === 'below',
                soldOutDisplay: attributes.sold_out_display,
            }}
        />

        {attributes.advanced_enabled && (
            <div className="fct-swatch-tab-settings">
                <SwatchAttributeListSetting
                    attributes={attributes}
                    setAttributes={setAttributes}
                    attributeGroups={attributeGroups}
                />

                <CardDisplaySection type="attribute" attributes={attributes} setAttributes={setAttributes} lastCounts={lastCounts} setLastCounts={setLastCounts}/>
                <SwatchSoldOutSetting type="attribute" attributes={attributes} setAttributes={setAttributes}/>
            </div>
        )}
    </>
);

const ListTab = ({attributes, setAttributes, lastCounts, setLastCounts}) => (
    <>
        <TabTop
            type="list"
            title={blocktranslate('Show the option list on the card')}
            enabled={attributes.simple_enabled}
            onToggle={(checked) => setAttributes({simple_enabled: checked})}
            preview={{
                maxVisible: attributes.simple_max_visible,
                showName: attributes.simple_show_label,
                rowName: attributes.simple_label,
                showSelected: listShowsSelected(attributes),
                selectedBelow: attributes.simple_selected_position === 'below',
                soldOutDisplay: attributes.sold_out_display,
            }}
        />

        {attributes.simple_enabled && (
            <div className="fct-swatch-tab-settings">
                <CardDisplaySection type="list" attributes={attributes} setAttributes={setAttributes} lastCounts={lastCounts} setLastCounts={setLastCounts}/>
                <SwatchSoldOutSetting type="list" attributes={attributes} setAttributes={setAttributes}/>
            </div>
        )}
    </>
);

// One tab per product type, each switching its cards' swatches on or off on its own.
const InspectorSettings = ({
    attributes,
    setAttributes,
    attributeGroups,
}) => {
    // A tab whose swatches are off says so, so its state shows without opening it.
    const tabTitle = (title, enabled) => (
        <>
            {title}
            {!enabled && <span className="fct-swatch-tab-off">{blocktranslate('Off')}</span>}
        </>
    );
    // Each tab's last typed count, kept here so a tab switch does not lose it while All is ticked.
    const [lastCounts, setLastCounts] = useState({});
    // The settings fade in on a tab switch only, not each time the block is selected.
    const lastTab = useRef(null);
    const switched = (name) => {
        const changed = lastTab.current !== null && lastTab.current !== name;
        lastTab.current = name;
        return changed;
    };
    const tabs = [
        {name: 'attribute', title: tabTitle(blocktranslate('Attribute products'), attributes.advanced_enabled)},
        {name: 'list', title: tabTitle(blocktranslate('List products'), attributes.simple_enabled)},
    ];

    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-swatches">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <TabPanel className="fct-swatch-tabs" tabs={tabs}>
                            {(tab) => (
                                <div key={tab.name} className={'fct-swatch-tab-panel' + (switched(tab.name) ? ' is-switching' : '')}>
                                    {tab.name === 'attribute' ? (
                                        <AttributeTab
                                            attributes={attributes}
                                            setAttributes={setAttributes}
                                            attributeGroups={attributeGroups}
                                            lastCounts={lastCounts}
                                            setLastCounts={setLastCounts}
                                        />
                                    ) : (
                                        <ListTab attributes={attributes} setAttributes={setAttributes} lastCounts={lastCounts} setLastCounts={setLastCounts}/>
                                    )}
                                </div>
                            )}
                        </TabPanel>

                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
