import { ReviewAvatarPlaceholder } from "@/BlockEditor/Icons";

const { useBlockProps } = wp.blockEditor;

/**
 * The reviewer's avatar.
 *
 * The same element ReviewThreadMarkup::avatarHtml() emits, placeholder glyph
 * and all, so the editor and the page style it from one rule rather than two
 * that drift. A sample review has no photo, so the placeholder is what shows —
 * which is also what a real review without one shows.
 */
const ReviewAvatarBlock = {
    edit: () => {
        const blockProps = useBlockProps({ className: 'fct-review-block-avatar' });

        return (
            <div {...blockProps}>
                <div className="fct-review-avatar-circle" data-review-avatar>
                    <ReviewAvatarPlaceholder />
                </div>
            </div>
        );
    },
    save: () => null,
    supports: {
        html: false,
        spacing: { margin: true, padding: true },
    },
    usesContext: [
        'fluent-cart/review',
    ],
};

export default ReviewAvatarBlock;
