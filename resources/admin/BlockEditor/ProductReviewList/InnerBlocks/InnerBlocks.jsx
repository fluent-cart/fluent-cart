import ReviewItemBlock from "./ReviewItemBlock.jsx";
import ReviewListCountBlock from "./ReviewListCountBlock.jsx";
import ReviewListFilterBlock from "./ReviewListFilterBlock.jsx";
import ReviewListSortingBlock from "./ReviewListSortingBlock.jsx";
import ReviewPaginationBlock from "./ReviewPaginationBlock.jsx";
import ReviewAvatarBlock from "./ReviewAvatarBlock.jsx";
import ReviewAuthorNameBlock from "./ReviewAuthorNameBlock.jsx";
import ReviewVerifiedBadgeBlock from "./ReviewVerifiedBadgeBlock.jsx";
import ReviewVariationTitleBlock from "./ReviewVariationTitleBlock.jsx";
import ReviewRatingBlock from "./ReviewRatingBlock.jsx";
import ReviewDateBlock from "./ReviewDateBlock.jsx";
import ReviewTitleBlock from "./ReviewTitleBlock.jsx";
import ReviewContentBlock from "./ReviewContentBlock.jsx";
import ReviewPhotosBlock from "./ReviewPhotosBlock.jsx";
import ReviewVotesBlock from "./ReviewVotesBlock.jsx";
import ReviewReplyBlock from "./ReviewReplyBlock.jsx";

import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { registerBlockType } = wp.blocks;

/**
 * The review blocks register from the same list the server does.
 *
 * Slugs, titles, icons and parentage are described once in
 * ProductReviewList\InnerBlocks\InnerBlocks::getInnerBlocks() and localized here, so
 * the two halves cannot disagree about what a block is called or where it may
 * be placed. This side supplies only what PHP cannot: the editor component.
 */
const blockEditorData = window['fluent_cart_review_inner_blocks'];

const componentsMap = {
    ReviewItemBlock,
    ReviewListCountBlock,
    ReviewListFilterBlock,
    ReviewListSortingBlock,
    ReviewPaginationBlock,
    ReviewAvatarBlock,
    ReviewAuthorNameBlock,
    ReviewVerifiedBadgeBlock,
    ReviewVariationTitleBlock,
    ReviewRatingBlock,
    ReviewDateBlock,
    ReviewTitleBlock,
    ReviewContentBlock,
    ReviewPhotosBlock,
    ReviewVotesBlock,
    ReviewReplyBlock,
};

if (blockEditorData?.blocks?.length) {
    blockEditorData.blocks.forEach((block) => {
        const Component = componentsMap[block.component];

        if (!Component) {
            return;
        }

        registerBlockType(block.slug, {
            apiVersion: 3,
            category: 'fluent-cart',
            title: block.title,
            name: block.slug,
            icon: block.icon || null,
            // ancestor, not parent — see InnerBlocks::register(). The row
            // template nests most fields inside core/group, and parent only
            // matches the immediate one.
            ancestor: block.ancestor?.length ? block.ancestor : null,
            edit: Component.edit || (() => blocktranslate('No edit found')),
            save: Component.save || (() => null),
            supports: Component.supports || {},
            usesContext: Component.usesContext || [],
            attributes: Component.attributes || {},
        });
    });
}
