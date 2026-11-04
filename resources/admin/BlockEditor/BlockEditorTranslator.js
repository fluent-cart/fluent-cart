export default function blocktranslate(string) {

    const translations = window['fluent_cart_block_translation'];

    if (typeof translations !== 'object') {
        return string;
    }

    if (!translations[string]) {
    }


    string = translations[string] || string;

    return applyPlaceholders(string, Array.prototype.slice.call(arguments, 1));
}

// Fill %s, %d, %1$s style placeholders; a missing argument keeps its placeholder.
function applyPlaceholders(string, args) {
    if (args.length === 0) {
        return string;
    }

    let argIndex = 0;
    return string.replace(/%(\d*\$?)s|%d/g, (match, number) => {
        if (number) {
            const index = parseInt(number, 10) - 1;
            return index < args.length ? args[index] : match;
        }
        return argIndex < args.length ? args[argIndex++] : match;
    });
}

/**
 * Contextual lookup, the counterpart of PHP's _x(). The map keys these entries
 * as "context\x04text", the same shape the admin translator reads; placeholder
 * arguments follow the context.
 */
export function _x(string, context) {
    const translations = window['fluent_cart_block_translation'];
    const args = Array.prototype.slice.call(arguments, 2);

    if (typeof translations !== 'object' || !translations) {
        return applyPlaceholders(string, args);
    }

    const translated = translations[context + '\u0004' + string] || string;

    // Falls back to the plain entry until translators fill in the contextual one
    return applyPlaceholders(translated === string ? (translations[string] || string) : translated, args);
}


export function pluralizeTranslate(singular, plural, count, empty = null) {
    let number = parseInt(count.toString().replace(/,/g, ''), 10);
    if (number > 1) {
        return blocktranslate(plural, count);
    }
    if (number === 0) {
        return blocktranslate(empty ?? singular, count);
    }
    return blocktranslate(singular, count);
}
