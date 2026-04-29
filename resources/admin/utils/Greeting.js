import translate from "@/utils/translator/Translator";

class Greeting {
    constructor(date = new Date()) {
        this.date = date;
    }

    getHours() {
        return this.date.getHours();
    }

    static greeting() {

        const hour = (new Greeting()).getHours();

        if (hour >= 5 && hour < 12) {
            return translate('Good morning');
        } else if (hour >= 12 && hour < 17) {
            return translate('Good afternoon');
        } else if (hour >= 17 && hour < 21) {
            return translate('Good evening');
        } else {
            const messages = [
                translate("Step into the moonlight!"),
                translate("Evening vibes!"),
                translate("The night's still young!"),
            ];

            return messages[Math.floor(Math.random() * messages.length)];
        }
    }
}

// Example usage:
export default Greeting;
