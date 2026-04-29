export default function blocktranslate(string) {

    const translations = window['fluent_cart_block_translation'];

    if (typeof translations !== 'object') {
        console.warn('Missing translation data of:' + string);
        return string;
    }

    if (!translations[string]) {
        console.warn('Missing translation:', string);
    }


    string = translations[string] || string;

    // Prepare the arguments, excluding the first one (the string itself)
    const args = Array.prototype.slice.call(arguments, 1);

    if (args.length === 0) {
        return string;
    }

    // Regular expression to match %s, %d, or %1s, %2s,  %1$s etc.
    const regex = /%(\d*\$?)s|%d/g;

    // Replace function to handle each match found by the regex
    let argIndex = 0; // Keep track of the argument index for non-numbered placeholders
    string = string.replace(regex, (match, number) => {
        // If it's a numbered placeholder, use the number to find the corresponding argument
        if (number) {
            const index = parseInt(number, 10) - 1; // Convert to zero-based index
            return index < args.length ? args[index] : match; // Replace or keep the placeholder
        } else {
            // For non-numbered placeholders, use the next argument in the array
            return argIndex < args.length ? args[argIndex++] : match; // Replace or keep the placeholder
        }
    });

    return string;
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
