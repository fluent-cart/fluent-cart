// Builds window.fluentcart.$t / _x over one string map; kept apart from
// FluentCartApp.js so a test can load it without Vue and the cart bundle.
export default function makeTranslator(strings) {
    return {
        $t: function (string) {
            return applyPlaceholders(strings[string] || string, Array.prototype.slice.call(arguments, 1));
        },

        // Counterpart of PHP's _x(): keys are "context\x04text"; placeholder arguments follow the context.
        _x: function (string, context) {
            // Falls back to the plain entry until translators fill in the contextual one.
            const translated = strings[context + '\u0004' + string] || strings[string] || string;

            return applyPlaceholders(translated, Array.prototype.slice.call(arguments, 2));
        }
    };
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
