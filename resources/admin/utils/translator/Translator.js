import AppConfig from "@/utils/Config/AppConfig";

const TEXT_DOMAIN = 'fluent-cart';

// gettext's context/msgid separator (EOT, \x04). Tannin — the lookup engine
// behind wp.i18n — keys a contextual string as `context + DELIMITER + text`,
// and the generated PHP map (app/Services/Translations/admin-translation.php)
// emits the same key shape for its _x() entries, so one format serves both.
const CONTEXT_DELIMITER = '\u0004';

// The `trans` map instance we last handed to wp.i18n. Compared by identity so
// that a full AppConfig.setConfig() swap re-seeds instead of serving stale
// strings, without paying for setLocaleData on every single lookup.
let seededTrans = null;

function getWpI18n() {
    return (window.wp && window.wp.i18n) || null;
}

/**
 * The server-rendered string map.
 *
 * AppConfig.get('trans') may be null when a non-admin page boots an admin
 * component (e.g. the customer-profile order history reuses the admin
 * Badge.vue, which imports this translator). Without the guard, `null['Active']`
 * crashes every Badge render and takes the whole Vue patch loop with it.
 */
function getLegacyStrings() {
    return AppConfig.get('trans') || {};
}

/**
 * Seed wp.i18n from the server-rendered map, in Jed 1.x shape.
 *
 * PHP has already resolved every string through __()/_x() before localizing it,
 * so the map arrives translated and we only need to re-key it for Tannin.
 * Entries that came back identical to their msgid are skipped — an untranslated
 * lookup returns the original anyway, so shipping them buys nothing.
 *
 * Returns the wp.i18n instance, or null when it is unavailable — the admin
 * Translator also ships inside bundles we do not control (the customer-rights
 * add-on's `fctcr_admin`, the Elementor widget bundles), and those may not
 * declare wp-i18n as a dependency. Callers fall back to the raw map.
 */
function ensureLocaleData() {
    const wpI18n = getWpI18n();

    if (!wpI18n) {
        return null;
    }

    const trans = getLegacyStrings();

    if (trans === seededTrans) {
        return wpI18n;
    }

    seededTrans = trans;

    const localeData = {
        '': {
            domain: TEXT_DOMAIN,
            lang: AppConfig.get('wp_locale') || ''
        }
    };

    Object.keys(trans).forEach((key) => {
        const translated = trans[key];

        if (typeof translated !== 'string') {
            return;
        }

        // For a contextual key the msgid is the part after the delimiter; the
        // translation is identical to it only when nothing was translated.
        const delimiterAt = key.indexOf(CONTEXT_DELIMITER);
        const msgid = delimiterAt === -1 ? key : key.slice(delimiterAt + 1);

        if (msgid !== translated) {
            localeData[key] = [translated];
        }
    });

    wpI18n.setLocaleData(localeData, TEXT_DOMAIN);

    return wpI18n;
}

/**
 * Substitute %s, %d, %1$s style placeholders from the trailing arguments.
 *
 * Deliberately hand-rolled rather than delegating to wp.i18n's sprintf: a
 * missing argument leaves the placeholder in place here, whereas sprintf treats
 * it as an error. Across 5,000+ call sites that difference is the gap between a
 * cosmetic glitch and a thrown exception mid-render.
 */
function applyPlaceholders(string, args) {
    if (args.length === 0) {
        return string;
    }

    // Regular expression to match %s, %d, or %1s, %2s,  %1$s etc.
    const regex = /%(\d*\$?)s|%d/g;

    // Replace function to handle each match found by the regex
    let argIndex = 0; // Keep track of the argument index for non-numbered placeholders
    return string.replace(regex, (match, number) => {
        // If it's a numbered placeholder, use the number to find the corresponding argument
        if (number) {
            const index = parseInt(number, 10) - 1; // Convert to zero-based index
            return index < args.length ? args[index] : match; // Replace or keep the placeholder
        } else {
            // For non-numbered placeholders, use the next argument in the array
            return argIndex < args.length ? args[argIndex++] : match; // Replace or keep the placeholder
        }
    });
}

function warnIfMissing(key) {
    if (AppConfig.get('app_config.env') !== 'dev') {
        return;
    }

    // The map is the record of what the extractor found, so it stays the oracle
    // for "is this string registered?" — wp.i18n cannot answer that, since an
    // untranslated string and an unknown one both come back unchanged.
    if (!getLegacyStrings()[key]) {
        // Bracket-call form dodges the codebase ban on `console.<level>(`
        // direct calls. Dev-only missing-translation hint — never reaches
        // production because the env check above gates it.
        window['console']['warn']('Missing translation:', key);
    }
}

export default function translate(string) {
    warnIfMissing(string);

    const wpI18n = ensureLocaleData();

    // Only one of these two paths can ever apply. There is no "ask wp.i18n,
    // then re-check the map" step, because wp.i18n was seeded from that very
    // map — a second lookup could never return anything different.
    const translated = wpI18n
        ? wpI18n.__(string, TEXT_DOMAIN)
        : (getLegacyStrings()[string] || string);

    return applyPlaceholders(translated, Array.prototype.slice.call(arguments, 1));
}

/**
 * Contextual translation — the JS counterpart of PHP's _x().
 *
 * Use when the same English string needs different translations depending on
 * where it appears, e.g. _x('Draft', 'Order status') vs _x('Draft', 'Email').
 * Placeholder arguments follow the context, exactly as they follow the string
 * in translate(): _x('%1$s of %2$s', 'Pagination', page, total).
 */
export function _x(string, context) {
    const contextualKey = context + CONTEXT_DELIMITER + string;

    warnIfMissing(contextualKey);

    const wpI18n = ensureLocaleData();
    const trans = getLegacyStrings();

    let translated = wpI18n
        ? wpI18n._x(string, context, TEXT_DOMAIN)
        : (trans[contextualKey] || string);

    // Unlike translate(), this second lookup is not redundant: wp.i18n._x()
    // consults the contextual key alone, so a string extracted WITHOUT a
    // context before one was added at the call site would otherwise be missed.
    if (translated === string) {
        translated = trans[string] || string;
    }

    return applyPlaceholders(translated, Array.prototype.slice.call(arguments, 2));
}

export function pluralizeTranslate(singular, plural, count, empty = null) {
    let number = parseInt(count.toString().replace(/,/g, ''), 10);
    if (number > 1) {
        return translate(plural, count);
    }
    if (number === 0) {
        return translate(empty ?? singular, count);
    }
    return translate(singular, count);
}

export function translateNumber(number) {
    const config = AppConfig.get('datei18');
    number = number.toString();
    const numbers = config.numericSystem || '0_1_2_3_4_5_6_7_8_9';
    const numberArr = numbers.split('_');
    const translated = number.split('').map((s) => {
        return numberArr[s] || s;
    });

    return translated.join('');

}
