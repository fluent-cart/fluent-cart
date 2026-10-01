import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

/**
 * What a review looks like, for the editor only.
 *
 * The editor has no review to render — the loop's query runs on the server at
 * request time — so every child previews against this. Written as a review a
 * shopper would actually leave rather than the word "placeholder", so an
 * editor laying out the blocks can see how the real thing will sit.
 */
export const sampleReview = () => ({
    id: 0,
    reviewer_name: blocktranslate('Dana Whitfield'),
    photo: '',
    rating: 4,
    is_verified: true,
    item_label: blocktranslate('Large / Charcoal'),
    created_at: blocktranslate('2 days ago'),
    title: blocktranslate('Comfortable from the first wear'),
    content: blocktranslate('Ordered these for daily walking and they needed no breaking in. The sizing runs true and the material has held up well after a month of use.'),
    // Enough attachments that a visible limit has something to hide, so the
    // + tile is visible on the canvas as soon as the setting is turned on.
    media_count: 5,
    reply_count: 1,
});

/**
 * The rest of the pool, for a preview that draws more than one card.
 *
 * Different lengths on purpose: a column of identical cards says nothing about
 * a layout. A two-line review beside a ten-line one is what shows whether the
 * footers still line up and whether the content clamp is doing its job. Each
 * carries a different rating, a different badge state and a different number
 * of photos, because those are the fields an editor is deciding whether to
 * keep.
 *
 * Every one of them carries at least one attachment. A photo-led card is the
 * photograph and little else, so a stand-in with none draws an empty card --
 * and a photo strip previewed against the old pool had two of its tiles blank.
 * A layout that wants a card without photographs leaves the block out; it does
 * not need a stand-in with nothing to show.
 *
 * Six in the pool including the one above: a four-column grid gets four
 * different reviews rather than the same two alternating, and a masonry
 * preview -- which draws two rows, because one row of equal cards is the one
 * thing masonry never looks like -- gets six.
 */
const otherSamples = () => ([
    {
        id: 1,
        reviewer_name: blocktranslate('Marcus Lane'),
        photo: '',
        rating: 5,
        is_verified: true,
        item_label: blocktranslate('Medium / Sand'),
        created_at: blocktranslate('1 week ago'),
        title: blocktranslate('Worth every penny'),
        content: blocktranslate('Second pair I have bought. They last.'),
        media_count: 2,
        reply_count: 0,
    },
    {
        id: 2,
        reviewer_name: blocktranslate('Priya Raman'),
        photo: '',
        rating: 3,
        is_verified: false,
        item_label: blocktranslate('Small / Navy'),
        created_at: blocktranslate('3 weeks ago'),
        title: blocktranslate('Good, with one caveat'),
        content: blocktranslate('The fit and finish are exactly as described and they have worn in nicely over the past month. My only note is the colour, which reads a shade darker in daylight than it does in the product photographs.'),
        media_count: 1,
        reply_count: 2,
    },
    {
        // The short one. A card with no title, no photos and a single line of
        // content is the narrowest a row gets, and the case where a footer
        // pushed to the bottom either lines up with its neighbours or does not.
        id: 3,
        reviewer_name: blocktranslate('Tomas Beck'),
        photo: '',
        rating: 4,
        is_verified: false,
        item_label: '',
        created_at: blocktranslate('a month ago'),
        title: '',
        content: blocktranslate('Exactly what I expected.'),
        media_count: 1,
        reply_count: 0,
    },
    {
        // The long one. Masonry and a photo wall are only themselves when the
        // cards are different heights, and a pool that tops out at a paragraph
        // draws a wall with no stagger in it.
        id: 4,
        reviewer_name: blocktranslate('Ines Okafor'),
        photo: '',
        rating: 5,
        is_verified: true,
        item_label: blocktranslate('Large / Olive'),
        created_at: blocktranslate('2 months ago'),
        title: blocktranslate('Held up to a wet winter'),
        content: blocktranslate('I have worn these most days since October, through rain and a fair amount of mud, and they have come through it better than the pair they replaced. The lining still looks new after a wash, the soles have not worn unevenly, and the laces have not frayed at the eyelets the way they usually do by now. The only thing I would change is the insole, which I swapped for my own after the first fortnight.'),
        media_count: 3,
        reply_count: 1,
    },
    {
        id: 5,
        reviewer_name: blocktranslate('Sam Oduya'),
        photo: '',
        rating: 2,
        is_verified: false,
        item_label: blocktranslate('Medium / Black'),
        created_at: blocktranslate('5 days ago'),
        title: blocktranslate('Runs a size small'),
        content: blocktranslate('Comfortable once on, but I had to send the first pair back for the next size up.'),
        media_count: 2,
        reply_count: 0,
    },
]);

/**
 * `count` stand-in reviews, cycling the pool when more are asked for than
 * there are.
 *
 * The ids are reassigned across the repeat, because they key the rendered list
 * and a duplicate key would have React reuse the wrong card.
 *
 * @param {number} count
 * @returns {Array}
 */
export const sampleReviews = (count = 1) => {
    const pool = [sampleReview(), ...otherSamples()];

    return Array.from({length: Math.max(1, count)}, (unused, index) => ({
        ...pool[index % pool.length],
        id: index,
    }));
};

export default sampleReview;
