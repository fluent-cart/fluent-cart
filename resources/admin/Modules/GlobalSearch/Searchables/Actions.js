import translate from "@/utils/translator/Translator";
import Theme from "@/utils/Theme";

const Actions = [
    {
        'title': "Theme",
        
        'title_resolver': () => {
            let currentTheme = Theme.isDark() ? 'dark' : 'light';
            let changeTo = currentTheme === 'light' ? translate('Dark') : translate('Light');
            /* translators: %s: theme name */
            return translate("Change theme to %s", changeTo);
        },
        "data": {
            "type": 'action',
            'action': () => {
                let currentTheme = Theme.isDark() ? 'dark' : 'light';
                let changeTo = currentTheme === 'light' ? 'dark' : 'light';
                Theme.apply(changeTo, {
                    fromShortcut: true,
                });

            }
        },
    }
];

export default Actions;
