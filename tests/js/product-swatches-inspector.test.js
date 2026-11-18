import {beforeAll, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

let InspectorSettings;
let nextRadioIndex;
let showsOtherGroups;
let mergeKeepingUnlisted;
let controls;

const record = (type) => (props) => {
    controls.push({type, ...props});
    return null;
};

beforeAll(async () => {
    vi.doMock('@/BlockEditor/Components/CustomSelect', () => ({default: record('select')}));
    globalThis.React = React;
    globalThis.window = {fluent_cart_block_translation: {}};
    globalThis.wp = {
        element: React,
        // The count box reads each tab's default from the registered block, as in the editor.
        blocks: {getBlockType: () => ({attributes: {max_visible: {default: 3}, simple_max_visible: {default: 4}}})},
        blockEditor: {InspectorControls: ({children}) => React.createElement('div', null, children)},
        components: {
            ToggleControl: record('toggle'),
            FormToggle: record('switch'),
            TextControl: record('text'),
            // Renders every tab, each under its own heading.
            TabPanel: ({tabs, children}) => React.createElement('div', null, tabs.map((tab) => (
                React.createElement('section', {key: tab.name}, React.createElement('h2', null, tab.title), children(tab))
            ))),
        },
    };
    ({default: InspectorSettings} = await import('@/BlockEditor/ProductSwatches/Components/InspectorSettings.jsx'));
    ({nextRadioIndex} = await import('@/BlockEditor/Components/radioGroupKeyboardNavigation.js'));
    ({showsOtherGroups, mergeKeepingUnlisted} = await import('@/BlockEditor/ProductSwatches/Components/SwatchAttributeListSetting.jsx'));
});

const GROUPS = [
    // The preview keeps 12 values; the count is the store's real one.
    {id: 1, title: 'Color', terms: Array.from({length: 12}, (_, index) => ({title: 'c' + index})), term_count: 16},
    {id: 2, title: 'Size', terms: [{title: 'S'}, {title: 'M'}]},
    {id: 3, title: 'Material', terms: [{title: 'Cotton'}]},
];

const render = (attributes, attributeGroups = []) => {
    controls = [];
    const changes = [];
    const html = renderToStaticMarkup(React.createElement(InspectorSettings, {
        attributes: {
            advanced_enabled: true, simple_enabled: true, max_visible: 3, simple_max_visible: 4,
            group_ids: [], show_group_name: false, simple_show_label: true, simple_label: '', show_label: false,
            ...attributes,
        },
        setAttributes: (patch) => changes.push(patch),
        attributeGroups,
    }));
    // Every switch is the input its title labels, e.g. fct-swatch-switch-attribute or fct-swatch-selected-list.
    const switchOf = (id) => controls.find((c) => c.type === 'switch' && c.id === id);
    const flip = (id, checked) => switchOf(id).onChange({target: {checked}});
    return {html, changes, switchOf, flip, switches: controls.filter((c) => c.type === 'switch').map((c) => c.id)};
};

const ALL_SWITCHES = [
    'fct-swatch-switch-attribute', 'fct-swatch-option-name-attribute', 'fct-swatch-selected-attribute',
    'fct-swatch-switch-list', 'fct-swatch-option-name-list', 'fct-swatch-selected-list',
];

describe('Product Swatches inspector', () => {
    it('shows one tab per product type, with the settings named as in the design', () => {
        const {html, switches} = render({});

        expect(html).toContain('<h2>Attribute products</h2>');
        expect(html).toContain('<h2>List products</h2>');
        expect(html).toContain('<label for="fct-swatch-switch-attribute" class="fct-swatch-switch-title is-large">Show attributes on the card</label>');
        expect(html).toContain('<label for="fct-swatch-switch-list" class="fct-swatch-switch-title is-large">Show the option list on the card</label>');
        // Both tabs name their switches the same way, each with its own setting.
        expect(switches).toEqual(ALL_SWITCHES);
        expect(html.match(/class="fct-swatch-switch-title">Show label<\/label>/g)).toHaveLength(2);
        expect(html.match(/class="fct-swatch-switch-title">Show the selected choice<\/label>/g)).toHaveLength(2);
        expect(html.match(/class="fct-swatch-group-heading">How it shows on the card<\/h3>/g)).toHaveLength(2);
        expect(html.match(/<label id="fct-swatch-count-(attribute|list)-title" for="fct-swatch-count-(attribute|list)" class="fct-swatch-switch-title">Show up to<\/label>/g)).toHaveLength(2);
        expect(html).toContain('<label for="fct-swatch-option-name-list-text" class="fct-swatch-section-label">Label name</label>');
        expect(controls.find((c) => c.type === 'text').id).toBe('fct-swatch-option-name-list-text');
    });

    it('writes each switch to its own setting', () => {
        const {changes, flip} = render({show_label: false, simple_show_selected: false});

        flip('fct-swatch-switch-attribute', false);
        flip('fct-swatch-switch-list', false);
        flip('fct-swatch-option-name-attribute', true);
        flip('fct-swatch-option-name-list', false);
        flip('fct-swatch-selected-attribute', true);
        flip('fct-swatch-selected-list', true);

        expect(changes).toEqual([
            {advanced_enabled: false}, {simple_enabled: false}, {show_group_name: true}, {simple_show_label: false},
            // show_label now belongs to attribute products, so the list keeps the value it showed.
            {show_label: true, simple_show_selected: false}, {simple_show_selected: true},
        ]);
    });

    it('lets list products follow show_label until they have a value of their own', () => {
        expect(render({show_label: true}).switchOf('fct-swatch-selected-list').checked).toBe(true);
        expect(render({show_label: true, simple_show_selected: false}).switchOf('fct-swatch-selected-list').checked).toBe(false);
        expect(render({show_label: false, simple_show_selected: true}).switchOf('fct-swatch-selected-list').checked).toBe(true);

        // Turning the attribute switch on pins the list to what it showed before.
        const {changes, flip} = render({show_label: false});
        flip('fct-swatch-selected-attribute', true);
        expect(changes).toEqual([{show_label: true, simple_show_selected: false}]);
    });

    it('hides a tab\'s other settings while its switch is off, and marks the tab Off', () => {
        const {html, switches} = render({advanced_enabled: false, simple_show_label: false});

        expect(html.match(/Off: no options on the card\./g)).toHaveLength(1);
        expect(html).toContain('<h2>Attribute products<span class="fct-swatch-tab-off">Off</span></h2>');
        expect(html).toContain('<h2>List products</h2>');
        expect(switches).toEqual(['fct-swatch-switch-attribute', 'fct-swatch-switch-list', 'fct-swatch-option-name-list', 'fct-swatch-selected-list']);
        // The name text field shows only while the list's option name is on.
        expect(controls.some((c) => c.type === 'text')).toBe(false);
    });

    it('offers name presets and puts the example in bold', () => {
        const {html} = render({simple_show_label: true, simple_label: 'Pack', show_label: true});

        expect(html).toContain('e.g. <b>Color</b> above the row<');
        expect(html).toContain('e.g. <b>Pack</b> above the row<');
        // The label name and its presets sit in the grey box under the switch.
        expect(html).toContain('class="fct-swatch-option-name-text fct-swatch-setting-box"');
        expect(html).toContain('<button type="button" class="fct-swatch-option-name-preset" aria-pressed="true">Pack</button>');
        expect(html.match(/class="fct-swatch-option-name-preset" aria-pressed="false">(Plan|Size|Bundle)</g)).toHaveLength(3);
        // Like the card, the example names the row only while its label shows.
        expect(html).toContain('e.g. <b>Navy</b>');
        expect(render({show_group_name: true, show_label: true}).html).toContain('e.g. <b>Color · Navy</b>');
        expect(html).toContain('e.g. <b>Pack · 6 pods</b>');
    });

    it('shows the selected choice after the name or in one line below the rows', () => {
        const position = (html, type) => html.split('for="fct-swatch-selected-' + type + '"')[1]
            .match(/aria-checked="true"[^>]*>.*?fct-swatch-position-label">([^<]+)/)[1];

        const after = render({show_label: true, show_group_name: true, simple_show_selected: false}).html;
        expect(position(after, 'attribute')).toBe('After the label');
        expect(after).toContain('>Where it appears</span>');
        expect(after).toContain('<span class="fct-swatch-preview-title" aria-hidden="true">Color · Navy</span>');
        expect(after).not.toContain('fct-swatch-preview-selected');

        const below = render({show_label: true, show_group_name: true, selected_position: 'below', simple_show_selected: false}).html;
        expect(position(below, 'attribute')).toBe('Below the options');
        expect(below).toContain('<span class="fct-swatch-preview-title" aria-hidden="true">Color</span>');
        expect(below).toContain('<span class="fct-swatch-preview-selected" aria-hidden="true">Selected: Navy · S</span>');
        // The list keeps its own position.
        expect(render({simple_show_selected: true, simple_selected_position: 'below'}).html).toContain('Selected: 6 pods');
    });

    it('draws each tab\'s preview card from its own settings', () => {
        const plain = render({max_visible: 3, simple_max_visible: 4, simple_show_label: false, show_label: false}).html;
        // 6 colours and 4 sizes past 3, 5 options past 4; no captions while names and choice are off.
        expect(plain).toContain('fct-swatch-preview-more"><span aria-hidden="true">+3</span>');
        expect(plain.match(/fct-swatch-preview-more"><span aria-hidden="true">\+1<\/span>/g)).toHaveLength(2);
        expect(plain).not.toContain('fct-swatch-preview-title');

        const named = render({show_group_name: true, simple_show_label: true, simple_label: 'Pack', show_label: true}).html;
        // Like a real card, each row starts on its first shown value.
        expect(named).toContain('<span class="fct-swatch-preview-title" aria-hidden="true">Color · Navy</span>');
        expect(named).toContain('<span class="fct-swatch-preview-title" aria-hidden="true">Size · S</span>');
        expect(named).toContain('<span class="fct-swatch-preview-title" aria-hidden="true">Pack · 6 pods</span>');
    });

    it('clamps How many to show like the renderer, so a missing value still shows one option', () => {
        for (const value of [undefined, '', -2]) {
            const {html} = render({max_visible: value, simple_max_visible: value, simple_show_label: false, show_label: false});
            expect(html).toContain('fct-swatch-preview-more"><span aria-hidden="true">+5</span>');
            expect(html).toContain('fct-swatch-preview-more"><span aria-hidden="true">+4</span>');
        }
    });

    it('lets the keyboard try the preview: one tab stop per row, on its picked option', () => {
        const {html} = render({max_visible: 3, simple_max_visible: 4});

        expect(html).toContain('role="group" aria-label="Card preview">');
        // The design drops the hint, the switch description and the caption under the preview.
        expect(html).not.toContain('fct-swatch-preview-caption');
        expect(html).not.toContain('fct-swatch-tab-hint');
        expect(html).not.toContain('id="fct-swatch-switch-attribute-description"');
        expect(html).toContain('role="radiogroup" aria-label="Color"');
        expect(html).toContain('role="radiogroup" aria-label="Size"');
        // Color, Size and the pack row: one checked, focusable option each.
        expect(html.match(/role="radio" tabindex="0"/g)).toHaveLength(3);
        expect(html.match(/class="fct-swatch-preview-option[^"]*"[^>]*aria-checked="true"/g)).toHaveLength(3);
        expect(html).toContain('<span class="screen-reader-text">3 more</span>');
        expect(html).toContain('aria-label="Navy"');
    });

    it('moves the pick with the arrow keys, Home and End, wrapping at the ends and flipped right to left', () => {
        const original = globalThis.getComputedStyle;
        globalThis.getComputedStyle = (element) => ({direction: element.dir});
        const key = (name, dir = 'ltr') => ({key: name, currentTarget: {dir}});

        expect(nextRadioIndex(key('ArrowRight'), 0, 3)).toBe(1);
        expect(nextRadioIndex(key('ArrowDown'), 2, 3)).toBe(0);
        expect(nextRadioIndex(key('ArrowLeft'), 0, 3)).toBe(2);
        expect(nextRadioIndex(key('ArrowUp'), 1, 3)).toBe(0);
        expect(nextRadioIndex(key('Home'), 2, 3)).toBe(0);
        expect(nextRadioIndex(key('End'), 0, 3)).toBe(2);
        expect(nextRadioIndex(key('ArrowRight', 'rtl'), 1, 3)).toBe(0);
        expect(nextRadioIndex(key('ArrowLeft', 'rtl'), 1, 3)).toBe(2);
        // Up and down follow the list order in either direction.
        expect(nextRadioIndex(key('ArrowDown', 'rtl'), 1, 3)).toBe(2);
        expect(nextRadioIndex(key('Enter'), 1, 3)).toBeNull();
        globalThis.getComputedStyle = original;
    });

    it('shows up to a number, or All, with the number kept while All is ticked', () => {
        const countControls = (html, type) => {
            const input = html.match(new RegExp('<input id="fct-swatch-count-' + type + '"[^>]*>'))[0];
            const all = html.split('id="fct-swatch-count-' + type + '"')[1].match(/<label class="fct-swatch-count-all"><input type="checkbox"[^>]*?( checked="")?\/?>/);
            return {value: input.match(/value="(\d+)"/)[1], disabled: input.includes('disabled=""'), all: !!all[1], max: input.match(/max="(\d+)"/)[1]};
        };

        const saved = render({max_visible: 3, simple_max_visible: 4}).html;
        expect(countControls(saved, 'attribute')).toEqual({value: '3', disabled: false, all: false, max: '12'});
        expect(countControls(saved, 'list')).toEqual({value: '4', disabled: false, all: false, max: '12'});
        expect(saved.match(/Extras show as &quot;\+2&quot;, &quot;\+3&quot;…/g)).toHaveLength(2);

        // All saves 0: the box greys out, showing the tab's usual number.
        const all = render({max_visible: 0, simple_max_visible: 0}).html;
        expect(countControls(all, 'attribute')).toEqual({value: '3', disabled: true, all: true, max: '12'});
        expect(countControls(all, 'list')).toEqual({value: '4', disabled: true, all: true, max: '12'});
        expect(all.match(/Every option shows on the card</g)).toHaveLength(2);
        // The All checkbox is read with the title and help it belongs to.
        expect(all).toContain('aria-describedby="fct-swatch-count-attribute-title fct-swatch-count-attribute-help"');
    });

    it('draws each position sample from the tab\'s count and label', () => {
        const samples = (html) => [...html.matchAll(/fct-swatch-position-sample" aria-hidden="true">(.*?)<\/span><span class="fct-swatch-position-label">/g)]
            .map((m) => m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim());

        // Color has 6 sample values: 3 shown leaves "+3"; with the label on, "Color · Navy".
        expect(samples(render({show_label: true, show_group_name: true, max_visible: 3, simple_show_selected: false}).html))
            .toEqual(['Color · Navy +3', 'Color +3 Selected: Navy · S']);
        // All shows every value, so no "+N".
        expect(samples(render({show_label: true, show_group_name: false, max_visible: 0, simple_show_selected: false}).html))
            .toEqual(['Navy', 'Selected: Navy · S']);
    });

    it('shows every option in the preview when All is picked, with no +N', () => {
        const {html} = render({max_visible: 0, simple_max_visible: 0, simple_show_label: false, show_label: false});
        const preview = html.split('fct-swatch-preview-card')[1];

        expect(html).not.toContain('fct-swatch-preview-more');
        // Every colour and size in the attribute tab's preview.
        const rows = preview.split('fct-swatch-preview-options').slice(1).map((row) => (row.split('</div>')[0].match(/role="radio"/g) || []).length);
        expect(rows.slice(0, 2)).toEqual([6, 4]);
    });

    it('lists the picked attributes in their order, ticked, with arrows to reorder', () => {
        const {html} = render({group_ids: [2, 1], show_other_groups: false}, GROUPS);
        const rows = [...html.matchAll(/<li class="fct-swatch-attribute-row"><input id="fct-swatch-attribute-(\d+)" type="checkbox"( checked="")?/g)]
            .map((m) => m[1] + (m[2] ? '*' : ''));

        expect(rows).toEqual(['2*', '1*']);
        expect(html).toContain('aria-label="Move Size up" disabled=""');
        expect(html).toContain('aria-label="Move Color down" disabled=""');
        expect(html).toContain('<span class="fct-swatch-attribute-sample" aria-hidden="true">16 values</span>');
        expect(html).toContain('<span class="fct-swatch-attribute-sample" aria-hidden="true">2 values</span>');
        expect(html).toContain('aria-expanded="false" aria-controls="fct-swatch-attribute-pool">+ Add another attribute</button>');
        expect(html).not.toContain('Tick at least one');
        // The warning's live region is always there, empty, so its text is announced when it appears.
        expect(html).toContain('<span role="status" class="fct-swatch-attribute-warning"></span>');
    });

    it('keeps saved picks the sidebar does not list', () => {
        const {html, changes} = render({group_ids: [150, 2], show_other_groups: false}, GROUPS);
        const rows = [...html.matchAll(/id="fct-swatch-attribute-(\d+)" type="checkbox"/g)].map((m) => m[1]);

        expect(rows).toEqual(['2']);
        expect(changes).toEqual([]);
        expect(html).not.toContain('Tick at least one');
    });

    it('keeps the old "no pick shows every attribute" for blocks saved before the checkbox', () => {
        expect(showsOtherGroups({group_ids: []})).toBe(true);
        expect(showsOtherGroups({group_ids: [2]})).toBe(false);
        expect(showsOtherGroups({group_ids: [2], show_other_groups: true})).toBe(true);
        expect(showsOtherGroups({group_ids: [], show_other_groups: false})).toBe(false);

        const saved = render({group_ids: []}, GROUPS).html;
        expect(saved).toMatch(/<input id="fct-swatch-attribute-other" type="checkbox"[^>]* checked=""/);
        expect(saved).not.toContain('fct-swatch-attribute-row');
    });

    it('warns when nothing is ticked and other attributes are off', () => {
        const {html} = render({group_ids: [], show_other_groups: false}, GROUPS);
        expect(html).toContain('<span role="status" class="fct-swatch-attribute-warning">Tick at least one, or cards will show no options.</span>');
    });

    it('offers No change, Cross out and Hide for sold-out options in both tabs', () => {
        const choices = (html) => [...html.matchAll(/aria-checked="(true|false)"[^>]*class="fct-swatch-sold-out-choice".*?fct-swatch-sold-out-label">([^<]+)/g)]
            .map((m) => m[2] + (m[1] === 'true' ? '*' : ''));

        // Unset keeps today's swatches.
        expect(choices(render({}).html)).toEqual(['No change*', 'Cross out', 'Hide', 'No change*', 'Cross out', 'Hide']);
        expect(choices(render({sold_out_display: 'hide'}).html)).toEqual(['No change', 'Cross out', 'Hide*', 'No change', 'Cross out', 'Hide*']);
        expect(render({}).html.match(/role="radiogroup" aria-labelledby="fct-swatch-sold-out-(attribute|list)"/g)).toHaveLength(2);
    });

    it('crosses out or hides the sold-out samples in the preview card', () => {
        const base = {max_visible: 0, simple_max_visible: 0, simple_show_label: false, show_label: false};
        const optionCount = (html) => html.split('fct-swatch-preview-card')[1].split('fct-swatch-preview-options').slice(1)
            .map((row) => (row.split('</div>')[0].match(/role="radio"/g) || []).length);

        const crossed = render({...base, sold_out_display: 'cross'}).html;
        expect(crossed).toContain('aria-label="Rust, sold out" aria-checked="false" aria-disabled="true"');
        expect(crossed).toContain('class="fct-swatch-preview-option is-sold-out" aria-label="XL, sold out"');
        expect(optionCount(crossed).slice(0, 2)).toEqual([6, 4]);

        const hidden = render({...base, sold_out_display: 'hide'}).html;
        expect(hidden).not.toContain('sold out');
        // Rust and XL drop out of the attribute card.
        expect(optionCount(hidden).slice(0, 2)).toEqual([5, 3]);

        expect(render(base).html).not.toContain('sold out');
    });

    it('counts the position samples like the preview, without hidden sold-out values', () => {
        const more = (html) => [...html.matchAll(/class="fct-swatch-position-dots">(.*?)<\/span><\/span>/g)].map((m) => (m[1].match(/is-more">\+(\d+)/) || [])[1] || '0');
        // Color has 6 sample values, one sold out (Rust): 3 shown leaves 3, or 2 once Hide drops Rust.
        expect(more(render({show_label: true, max_visible: 3, simple_show_selected: false}).html)).toEqual(['3', '3']);
        expect(more(render({show_label: true, max_visible: 3, sold_out_display: 'hide', simple_show_selected: false}).html)).toEqual(['2', '2']);
        // All hides nothing, so no "+N"; the sample draws at most five dots, as in the design.
        const all = render({show_label: true, max_visible: 0, simple_show_selected: false}).html;
        expect(all.split('fct-swatch-position-dots')[1].split('</span></span>')[0].match(/class="is-dot"/g)).toHaveLength(5);
        expect(more(all)).toEqual(['0', '0']);
        // With Hide, the sold-out Rust is not among the dots.
        const hidden = render({show_label: true, max_visible: 0, sold_out_display: 'hide', simple_show_selected: false}).html;
        expect(hidden.split('fct-swatch-position-dots')[1].split('</span></span>')[0]).not.toContain('#b45a3c');
    });

    it('keeps unticked attributes in the list, unticked, below the ticked ones', () => {
        const {html} = render({group_ids: [2], hidden_group_ids: [3, 1], show_other_groups: true}, GROUPS);
        const rows = [...html.matchAll(/<li class="fct-swatch-attribute-row"><input id="fct-swatch-attribute-(\d+)" type="checkbox"( checked="")?/g)]
            .map((m) => m[1] + (m[2] ? '*' : ''));

        expect(rows).toEqual(['2*', '3', '1']);
        // Their arrows are off: their order does not change the cards.
        expect(html).toContain('aria-label="Move Material up" disabled=""');
    });

    it('lays the listed attributes back around saved ones the sidebar does not list', () => {
        const listed = (id) => id < 100;
        // 150 is not listed: it keeps its slot when 2 and 1 swap.
        expect(mergeKeepingUnlisted([150, 2, 1], [1, 2], listed)).toEqual([150, 1, 2]);
        expect(mergeKeepingUnlisted([2, 150, 1], [1, 2], listed)).toEqual([1, 150, 2]);
        // A removed one frees its slot; an added one goes at the end.
        expect(mergeKeepingUnlisted([2, 150, 1], [1], listed)).toEqual([1, 150]);
        expect(mergeKeepingUnlisted([150, 2], [2, 3], listed)).toEqual([150, 2, 3]);
    });
});

