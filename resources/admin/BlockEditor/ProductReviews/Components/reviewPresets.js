/**
 * The layouts a review section can be built from.
 *
 * Declared in PHP, at FluentCart\App\Services\Reviews\LayoutPresets, and read
 * here. They used to be built in this file, which was fine while the block
 * editor was the only thing that composed them — it no longer is, and eleven
 * layouts copied per consumer is exactly the second description of a layout
 * that this arrangement exists to avoid.
 *
 * What stays here is the part that is only true of the editor: recognising
 * which preset a tree of blocks already matches, and replacing that tree when
 * one is chosen.
 *
 * Each template is an inner-blocks template — [name, attributes, innerBlocks] —
 * the shape wp.blocks.createBlocksFromInnerBlocksTemplate() takes.
 */

import {
    blockToTemplate,
    hasReviewWritingBlocks,
    isBareReviewList,
    summarySectionTemplate,
    withoutReviewWritingBlocks,
    withRetainedAttributes,
    withWriteReviewButton,
} from "@/BlockEditor/ProductReviews/Components/reviewSectionShape";

/**
 * Read per call rather than captured at import: the localized payload is
 * printed before this script runs, but a missing global must leave the picker
 * empty rather than take the whole block registration down with it.
 */
const presetData = () => (window.fluent_cart_product_reviews_data || {}).presets || {};

const presets = new Proxy({}, {
    get: (unused, name) => presetData()[name],
    has: (unused, name) => Object.prototype.hasOwnProperty.call(presetData(), name),
    ownKeys: () => Object.keys(presetData()),
    getOwnPropertyDescriptor: () => ({enumerable: true, configurable: true}),
});

/**
 * The one a block falls back to: the first defined, rather than a name written
 * out here, so renaming or removing a preset cannot leave this pointing at
 * nothing.
 */
export const defaultPreset = () => Object.keys(presets)[0];

export const presetOptions = () => Object.entries(presets).map(
    ([value, {label}]) => ({value, label})
);

/**
 * Whether a layout belongs to Pro.
 *
 * Declared on the preset rather than listed in the picker, so the card, the
 * click handler and the arrow keys cannot disagree about which are locked --
 * and moving one between free and Pro is a flag, not a hunt.
 */
export const presetIsPro = (name) => {
    const preset = presets[name];

    return !!(preset && preset.pro);
};

export const presetCategory = (name) => {
    const preset = presets[name];

    return preset ? preset.category : 'list';
};

export const presetHelp = (name) => {
    const preset = presets[name] || presets[defaultPreset()];

    return preset ? preset.help : '';
};

const templateBlockName = (template) => Array.isArray(template) ? template[0] : '';
const templateAttributes = (template) => (Array.isArray(template) && template[1] && typeof template[1] === 'object') ? template[1] : {};
const templateChildren = (template) => Array.isArray(template) && Array.isArray(template[2]) ? template[2] : [];

/**
 * Settings a preset starts you on rather than settings that make it what it is.
 *
 * Matching used to compare every attribute, so a merchant who picked Card Grid
 * and then set three to a row was told they had a Custom layout -- the preset
 * had not stopped applying, but the picker no longer admitted to it, and the
 * only way back was to reapply the preset and lose the change. A preset is the
 * arrangement of blocks; how many to a row, how many to a page and which
 * arrows it uses are tuning on top of it.
 *
 * Every preset stays distinct without these, so none of them is recognised as
 * another. Checked rather than assumed: the two photograph grids differ in
 * their cards, not merely in their column count -- Photo Grid writes the
 * review under a flush photograph, Photo Wall writes the name and stars over
 * the face of one.
 */
const TUNING_ATTRIBUTES = [
    'gridColumns',
    'perPage',
    'sliderSettings',
    'defaultSortBy',
    'defaultSortOrder',
    'paginationType',
];

const LIST_BLOCK = 'fluent-cart/product-review-list';
const PAGER_BLOCK = 'fluent-cart/review-list-pagination';

/** The first block of that name anywhere in a block tree. */
const findBlock = (blocks, name) => {
    for (const block of (blocks || [])) {
        if (block?.name === name) {
            return block;
        }

        const found = findBlock(block?.innerBlocks, name);

        if (found) {
            return found;
        }
    }

    return null;
};

/** The same search over a template, which nests under index 2. */
const findTemplate = (template, name) => {
    for (const node of (template || [])) {
        if (templateBlockName(node) === name) {
            return node;
        }

        const found = findTemplate(templateChildren(node), name);

        if (found) {
            return found;
        }
    }

    return null;
};

/**
 * The tuning a merchant actually chose, as opposed to the tuning their current
 * preset simply starts on.
 *
 * The difference matters. Carrying every tuning attribute across would make the
 * first preset's numbers follow the merchant everywhere -- move from Compact,
 * which starts at three reviews to a page, and every later layout would inherit
 * three whether or not anyone asked for it. So a value is only carried when it
 * differs from what the preset it is leaving would have set, which is the one
 * reading of "the merchant changed this" available from the blocks alone.
 *
 * @param {Array} blocks the section's current child blocks
 * @returns {Object} attributes to carry, empty when nothing was tuned
 */
const blockDefault = (name, key) => {
    const blockType = wp.blocks.getBlockType?.(name);
    const definition = blockType?.attributes?.[key];

    return definition && Object.prototype.hasOwnProperty.call(definition, 'default')
        ? definition.default
        : undefined;
};

/**
 * Which block each tuning attribute actually lives on.
 *
 * Five of the six are the list's, and reading all six off the list quietly
 * lost the sixth: paginationType is the pager block's, so a merchant who chose
 * bullets kept their page length across a preset switch and silently got
 * numbers back.
 */
const TUNING_OWNER = {
    gridColumns: LIST_BLOCK,
    perPage: LIST_BLOCK,
    sliderSettings: LIST_BLOCK,
    defaultSortBy: LIST_BLOCK,
    defaultSortOrder: LIST_BLOCK,
    paginationType: PAGER_BLOCK,
};

/**
 * @param {Array} blocks the section's current child blocks
 * @returns {Object} block name -> attributes to carry, empty when none
 */
const tunedAttributes = (blocks) => {
    const from = presets[presetForBlocks(blocks)];
    const tuned = {};

    for (const key of TUNING_ATTRIBUTES) {
        const owner = TUNING_OWNER[key];
        const block = owner ? findBlock(blocks, owner) : null;
        const value = block?.attributes?.[key];

        if (value === undefined) {
            continue;
        }

        const defaults = from
            ? templateAttributes(findTemplate(from.template, owner))
            : {};
        const expected = Object.prototype.hasOwnProperty.call(defaults, key)
            ? defaults[key]
            : blockDefault(owner, key);

        if (JSON.stringify(value) !== JSON.stringify(expected)) {
            tuned[owner] = {...(tuned[owner] || {}), [key]: value};
        }
    }

    return tuned;
};

const blockMatchesTemplate = (block, template) => {
    if (!block || templateBlockName(template) !== block.name) {
        return false;
    }

    const expected = templateAttributes(template);
    for (const key of Object.keys(expected)) {
        if (TUNING_ATTRIBUTES.indexOf(key) > -1) {
            continue;
        }

        if (JSON.stringify(block.attributes?.[key]) !== JSON.stringify(expected[key])) {
            return false;
        }
    }

    const children = block.innerBlocks || [];
    const expectedChildren = templateChildren(template);

    return children.length === expectedChildren.length && expectedChildren.every(
        (child, index) => blockMatchesTemplate(children[index], child)
    );
};

/**
 * A preset as built for All products and Selected products (two or more):
 * without Write a Review or the form, since a review needs one product.
 */
const presetTemplate = (preset, multiProduct = false) => (
    multiProduct ? withoutReviewWritingBlocks(preset.template) : preset.template
);

/**
 * Return the matching preset for the current child blocks, or `custom` when
 * a merchant has edited the generated layout.
 */
export const presetForBlocks = (blocks, multiProduct = false) => {
    // The container's original scaffold intentionally leaves the Review List
    // empty. Only that complete scaffold is the default. Looking for any empty
    // review list nested in columns would relabel a merchant's custom layout
    // as Classic as soon as they added an empty list to it.
    if (isUntouchedDefaultScaffold(blocks)) {
        return defaultPreset();
    }

    // The forms without Write a Review only count for several products, so an
    // existing single-product layout that happens to match one stays Custom.
    for (const withoutWriting of (multiProduct ? [false, true] : [false])) {
        for (const [name, preset] of Object.entries(presets)) {
            const template = presetTemplate(preset, withoutWriting);
            if (blocks.length === template.length && template.every((block, index) => blockMatchesTemplate(blocks[index], block))) {
                return name;
            }
        }
    }

    return 'custom';
};

const isUntouchedDefaultScaffold = (blocks) => {
    if (!Array.isArray(blocks) || blocks.length !== 1) {
        return false;
    }

    const [columns] = blocks;
    const columnsChildren = columns?.innerBlocks || [];

    if (columns?.name !== 'core/columns' || columnsChildren.length !== 2) {
        return false;
    }

    const [summaryColumn, reviewColumn] = columnsChildren;
    const summaryChildren = summaryColumn?.innerBlocks || [];
    const reviewChildren = reviewColumn?.innerBlocks || [];

    if (summaryColumn?.name !== 'core/column' || summaryChildren.length !== 1
        || reviewColumn?.name !== 'core/column' || reviewChildren.length !== 1) {
        return false;
    }

    const [summaryGroup] = summaryChildren;
    const [reviewList] = reviewChildren;
    const summaryGroupChildren = summaryGroup?.innerBlocks || [];

    return summaryGroup?.name === 'fluent-cart/product-review-summary-group'
        && summaryGroupChildren.length === 2
        && summaryGroupChildren[0]?.name === 'fluent-cart/product-review-summary'
        && summaryGroupChildren[1]?.name === 'fluent-cart/write-a-review-button'
        && reviewList?.name === 'fluent-cart/product-review-list'
        && !(reviewList.innerBlocks || []).length;
};

/**
 * Build the section out of a preset's blocks.
 *
 * Replaces what is there, which is the point: a layout is its blocks, so a
 * different layout means different blocks. Whatever was arranged inside the
 * section goes with it, which is why the control says so before it is used.
 *
 * @param {string} name
 * @param {string} rootClientId
 * @param {{multiProduct?: boolean}} options multiProduct builds it without Write a Review or the form
 * @returns {boolean} whether a preset by that name existed.
 */
export const applyPreset = (name, rootClientId, {multiProduct = false} = {}) => {
    const preset = presets[name];

    if (!preset || !rootClientId) {
        return false;
    }

    const current = wp.data.select('core/block-editor').getBlocks(rootClientId);
    const tuning = tunedAttributes(current);

    // Data now, not a function: PHP hands the template over already built.
    const blocks = wp.blocks.createBlocksFromInnerBlocksTemplate(presetTemplate(preset, multiProduct));

    // The settings a merchant changed follow them to the new layout. Matching
    // already ignores these six, so a list tuned to four columns still reads as
    // Card Grid -- but applying the next preset rebuilt the block from its
    // template and put the columns back, which with selection-follows-focus
    // meant one arrow key silently discarded them.
    // Each attribute goes back to the block that owns it. A layout without
    // that block simply drops it -- switching to one with no pager is a
    // choice to have no pager, not a page style to carry anywhere.
    for (const [blockName, attributes] of Object.entries(tuning)) {
        const target = findBlock(blocks, blockName);

        if (target) {
            target.attributes = {...target.attributes, ...attributes};
        }
    }

    wp.data.dispatch('core/block-editor').replaceInnerBlocks(rootClientId, blocks, false);

    return true;
};

/**
 * Fit a section that is not a preset to its product source: several products
 * drop Write a Review and the form; one product puts the button back in the
 * summary group, or a bare list back beside a summary.
 *
 * @param {string} rootClientId
 * @param {boolean} multiProduct
 * @returns {boolean} whether the section changed
 */
export const fitCustomSectionToSource = (rootClientId, multiProduct) => {
    const templates = wp.data.select('core/block-editor').getBlocks(rootClientId).map(blockToTemplate);
    let nextTemplates = null;

    if (multiProduct) {
        nextTemplates = hasReviewWritingBlocks(templates) ? withoutReviewWritingBlocks(templates) : null;
    } else if (isBareReviewList(templates)) {
        nextTemplates = summarySectionTemplate(templates[0]);
    } else {
        nextTemplates = withWriteReviewButton(templates);
    }

    if (!nextTemplates) {
        return false;
    }

    wp.data.dispatch('core/block-editor').replaceInnerBlocks(
        rootClientId,
        wp.blocks.createBlocksFromInnerBlocksTemplate(nextTemplates),
        false
    );

    return true;
};

/**
 * Switching a preset layout between one product and several. Going to several
 * products changes the section in place; going back rebuilds the preset so a
 * column dropped on the way returns, with each remaining block's settings kept.
 *
 * @param {string} name
 * @param {string} rootClientId
 * @param {boolean} multiProduct
 * @returns {boolean} whether the section changed
 */
export const fitPresetToSource = (name, rootClientId, multiProduct) => {
    const preset = presets[name];

    if (multiProduct || !preset) {
        return fitCustomSectionToSource(rootClientId, multiProduct);
    }

    const current = wp.data.select('core/block-editor').getBlocks(rootClientId).map(blockToTemplate);

    wp.data.dispatch('core/block-editor').replaceInnerBlocks(
        rootClientId,
        wp.blocks.createBlocksFromInnerBlocksTemplate(withRetainedAttributes(presetTemplate(preset, false), current)),
        false
    );

    return true;
};

/**
 * The same fit for a standalone Summary Group, whose own children are the summary
 * and the button.
 *
 * @param {string} groupClientId
 * @param {boolean} multiProduct
 * @returns {boolean} whether the group changed
 */
export const fitSummaryGroupToSource = (groupClientId, multiProduct) => {
    const children = wp.data.select('core/block-editor').getBlocks(groupClientId).map(blockToTemplate);
    const group = [['fluent-cart/product-review-summary-group', {}, children]];
    let nextGroup = null;

    if (multiProduct) {
        nextGroup = hasReviewWritingBlocks(children) ? withoutReviewWritingBlocks(group) : null;
    } else {
        nextGroup = withWriteReviewButton(group);
    }

    if (!nextGroup) {
        return false;
    }

    const nextChildren = nextGroup.length ? (nextGroup[0][2] || []) : [];

    wp.data.dispatch('core/block-editor').replaceInnerBlocks(
        groupClientId,
        wp.blocks.createBlocksFromInnerBlocksTemplate(nextChildren),
        false
    );

    return true;
};

export default presets;
