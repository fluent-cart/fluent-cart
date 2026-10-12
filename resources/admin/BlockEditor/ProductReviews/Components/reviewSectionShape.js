/**
 * Shape changes for a Product Reviews section, over inner-blocks templates:
 * [name, attributes, innerBlocks].
 */
const LIST_BLOCK = 'fluent-cart/product-review-list';
const SUMMARY_GROUP_BLOCK = 'fluent-cart/product-review-summary-group';
const SUMMARY_BLOCK = 'fluent-cart/product-review-summary';
const WRITE_REVIEW_BLOCK = 'fluent-cart/write-a-review-button';

// A review belongs to one product, so All products and Selected products (two
// or more) have nothing to write one for. The rating summary combines them.
export const REVIEW_WRITING_BLOCKS = [
    WRITE_REVIEW_BLOCK,
    'fluent-cart/product-review-form',
];

const childrenOf = (template) => (Array.isArray(template[2]) ? template[2] : []);

/**
 * The block's starting layout: summary and Write a Review beside the list.
 */
export const summarySectionTemplate = (listTemplate = [LIST_BLOCK, {}]) => [
    ['core/columns', {style: {spacing: {blockGap: '24px'}}}, [
        ['core/column', {width: '320px'}, [
            [SUMMARY_GROUP_BLOCK, {}, [
                [SUMMARY_BLOCK, {}],
                [WRITE_REVIEW_BLOCK, {}],
            ]],
        ]],
        ['core/column', {}, [listTemplate]],
    ]],
];

export const blockToTemplate = (block) => [
    block.name,
    {...(block.attributes || {})},
    (block.innerBlocks || []).map(blockToTemplate),
];

export const hasReviewWritingBlocks = (templates) => templates.some(
    (template) => REVIEW_WRITING_BLOCKS.includes(template[0]) || hasReviewWritingBlocks(childrenOf(template))
);

/**
 * The section without Write a Review or the form. A wrapper emptied by that
 * (a summary group holding only the button, its column) is dropped, and
 * columns left with one column give way to its contents.
 */
export const withoutReviewWritingBlocks = (templates) => templates.flatMap((template) => {
    const [name, attributes] = template;

    if (REVIEW_WRITING_BLOCKS.includes(name)) {
        return [];
    }

    const children = childrenOf(template);
    const keptChildren = withoutReviewWritingBlocks(children);

    if (children.length && !keptChildren.length) {
        return [];
    }

    if (name === 'core/columns' && keptChildren.length === 1 && keptChildren.length < children.length) {
        return childrenOf(keptChildren[0]);
    }

    return [[name, attributes, keptChildren]];
});

/**
 * Back to one product: Write a Review returns under the summary in each
 * summary group that lost it. Returns null when there is no group to put it in.
 */
export const withWriteReviewButton = (templates) => {
    let added = false;

    const restore = (nodes) => nodes.map((template) => {
        const [name, attributes] = template;
        const children = childrenOf(template);

        if (name === SUMMARY_GROUP_BLOCK) {
            if (children.some((child) => child[0] === WRITE_REVIEW_BLOCK)) {
                return template;
            }

            added = true;
            return [name, attributes, [...children, [WRITE_REVIEW_BLOCK, {}]]];
        }

        return [name, attributes, restore(children)];
    });

    const restored = restore(templates);

    return added ? restored : null;
};

/**
 * Whether the section is only a Review List; such a section can be put back
 * beside a summary.
 */
export const isBareReviewList = (templates) => templates.length === 1 && templates[0][0] === LIST_BLOCK;

/**
 * A rebuilt layout with the merchant's settings put back: each block takes the
 * attributes of the block of the same name, in the same order, from the current
 * section. A block the section did not have keeps the template's own settings.
 */
export const withRetainedAttributes = (templates, currentTemplates) => {
    const currentByName = {};
    const collect = (nodes) => nodes.forEach((template) => {
        (currentByName[template[0]] = currentByName[template[0]] || []).push(template[1] || {});
        collect(childrenOf(template));
    });
    collect(currentTemplates);

    const taken = {};
    const retain = (nodes) => nodes.map((template) => {
        const [name, attributes] = template;
        const index = taken[name] || 0;
        taken[name] = index + 1;

        const current = (currentByName[name] || [])[index];

        return [name, current ? {...(attributes || {}), ...current} : {...(attributes || {})}, retain(childrenOf(template))];
    });

    return retain(templates);
};
