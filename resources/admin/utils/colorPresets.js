export const COLOR_NAME_MAP = {
    'dark navy':    '#1A2332',
    'dark slate':   '#374151',
    'slate gray':   '#6b7280',
    'light gray':   '#9CA3AF',
    'pearl':        '#D1D5DB',
    'white':        '#ffffff',
    'red':          '#ef4444',
    'orange':       '#F97316',
    'yellow':       '#eab308',
    'amber':        '#CA8A04',
    'green':        '#22c55e',
    'dark green':   '#4D7C0F',
    'teal':         '#0D9488',
    'blue':         '#3b82f6',
    'navy':         '#1E3A8A',
    'purple':       '#7C3AED',
    'pink':         '#EC4899',
    'dark red':     '#991B1B',
    'brown':        '#92400E',
    'cream':        '#FEF3C7',
    'mint':         '#5EEAD4',
    'steel':        '#475569',
    'burnt orange': '#EA580C',
    'forest':       '#15803D',
    // aliases — point to nearest swatch; deduplicated out of COLOR_SWATCHES by Set
    'black':        '#000000',
    'grey':         '#6b7280',
    'gray':         '#6b7280',
    'violet':       '#7C3AED',
    'indigo':       '#1E3A8A',
    'cyan':         '#0D9488',
    'turquoise':    '#0D9488',
    'lime':         '#15803D',
    'coral':        '#F97316',
    'maroon':       '#991B1B',
    'olive':        '#4D7C0F',
    'magenta':      '#EC4899',
    'gold':         '#d4af37',
    'silver':       '#c0c0c0',
    'beige':        '#f5f5dc',
};

export const COLOR_SWATCHES = [...new Set(Object.values(COLOR_NAME_MAP))];

// Reverse lookup: hex → canonical name. First occurrence wins so aliases
// like "indigo" → "navy" or "violet" → "purple" don't overwrite the
// canonical label. Hex keys are normalised to lowercase.
export const COLOR_HEX_TO_NAME = Object.entries(COLOR_NAME_MAP).reduce((acc, [name, hex]) => {
    const key = (hex || '').toLowerCase();
    if (key && !acc[key]) acc[key] = name;
    return acc;
}, {});

export function getSuggestedColor(title) {
    if (!title) return null;
    return COLOR_NAME_MAP[title.trim().toLowerCase()] || null;
}

export function getColorName(hex) {
    if (!hex) return null;
    return COLOR_HEX_TO_NAME[hex.trim().toLowerCase()] || null;
}
