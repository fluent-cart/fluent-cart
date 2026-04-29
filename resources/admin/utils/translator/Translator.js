import AppConfig from "@/utils/Config/AppConfig";

export default function translate(string) {

    if (
        AppConfig.get('app_config.env') === 'dev' &&
        !AppConfig.get('trans')[string]) {
        //console.warn('Missing translation:', string);
    }


    string = AppConfig.get('trans')[string] || string;

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
