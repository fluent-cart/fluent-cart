import {cardPresets, cardTemplate} from '@/BlockEditor/ShopApp/InnerBlocks/ProductCardLayoutPresets';

// A card from the inserter holds the card layouts. On its own it gets a card-sized width;
// inside a loop the width is ignored and the loop's column decides.
export const NEW_CARD_ATTRIBUTES = {template_version: 1, card_width: 300};

// An older card renders as one block. Converting it builds its child blocks from the Classic
// layout; setAttributes keeps its product, price format and width.
const CONVERTED_LAYOUT = 'classic';

export const convertToTemplateCard = () => ({template_version: 1, card_layout: CONVERTED_LAYOUT});

export const convertedCardTemplate = () => cardTemplate(cardPresets()[CONVERTED_LAYOUT]);
