import Rest from "@/utils/http/Rest";
import colors from '../../styles/tailwind/extends/color';
import {nextTick} from "vue";
import Arr from "@/utils/support/Arr";


class Theme {
    static MODE_LIGHT = 'light';
    static MODE_DARK = 'dark';
    static MODE_SYSTEM = 'system';
    static DARK_CLASS = 'fluent_theme_dark';
    static THEME_CHANGE_EVENT = 'onFluentCartThemeChange';
    static THEME_TOGGLE_EVENT = 'changeFluentCartTheme';
    static STORAGE_KEY = 'fluent_theme_mode';
    static LEGACY_STORAGE_KEY = 'fcart_admin_theme';
    static THEME_CHANNEL = 'fluent_theme_changed';
    static #channel = null;

    static {
        if (typeof BroadcastChannel !== 'undefined') {
            Theme.#channel = new BroadcastChannel(Theme.THEME_CHANNEL + ':' + window.location.origin);
            Theme.#channel.onmessage = function(event) {
                if (event.data && event.data.mode) {
                    Theme.apply(event.data.mode, {}, false);
                }
            };
        }
    }


    static get colors() {
        return colors;
    }

    static getSystemTheme() {
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return this.MODE_DARK;
        }
        return this.MODE_LIGHT;
    }

    static getCurrentTheme() {
        const allowedModes = [this.MODE_LIGHT, this.MODE_DARK, this.MODE_SYSTEM];
        if (allowedModes.includes(this.getSavedTheme())) {
            return this.getSavedTheme().toString();
        }
        return this.MODE_SYSTEM;
    }

    static getSavedTheme() {
        const savedTheme = localStorage.getItem(this.STORAGE_KEY);

        if (savedTheme) {
            return savedTheme;
        }

        const legacyTheme = localStorage.getItem(this.LEGACY_STORAGE_KEY);

        if (legacyTheme) {
            localStorage.setItem(this.STORAGE_KEY, legacyTheme);
            localStorage.removeItem(this.LEGACY_STORAGE_KEY);
            return legacyTheme;
        }

        return null;
    }

    static isDark() {
        if (this.isSystem()) {
            return this.getSystemTheme() === this.MODE_DARK;
        } else {
            return this.getCurrentTheme() === this.MODE_DARK;
        }
    }

    static isLight() {
        if (this.isSystem()) {
            return this.getSystemTheme() === this.MODE_LIGHT;
        } else {
            return this.getCurrentTheme() === this.MODE_LIGHT;
        }
    }

    static isSystem() {
        return this.getCurrentTheme().startsWith(this.MODE_SYSTEM);
    }

    static toggle() {
        const newMode = this.isLight() ? this.MODE_DARK : this.MODE_LIGHT;
        this.apply(newMode);
        return newMode;
    }

    static apply(mode, detail = {}, _broadcast = true) {
        const actualMode = mode;

        if (mode === this.MODE_SYSTEM) {
            mode = this.getSystemTheme();
        }

        // Remove the temporary class the inline head script placed on <html>.
        // The settled state lives on body/admin containers, not on <html>.
        document.documentElement.classList.remove(this.DARK_CLASS);

        const elements = [
            document.querySelector('#wpbody-content'),
            document.querySelector('.wp-toolbar'),
            document.body,
            document.querySelector('#wpfooter')
        ].filter(Boolean);


        if (mode === this.MODE_DARK) {
            elements.forEach(element => element.classList.add(this.DARK_CLASS));
        } else {
            elements.forEach(element => element.classList.remove(this.DARK_CLASS));
        }

        if (actualMode === Theme.MODE_SYSTEM) {
            /**
             * If the selected theme mode is "system", append the resolved mode (`dark` or `light`)
             * to the string (e.g., "system:dark", "system:light").
             *
             * This format helps the backend determine and apply the correct admin theme
             * before the frontend JS takes over, reducing the visual "theme blink" on the first load.
             *
             * Since PHP doesn't have access to the user's system preference,
             * we persist the resolved value alongside "system" to pre-apply the correct theme early.
             */
            this.saveThemePreference(`${actualMode}:${mode}`);
        } else {
            /**
             * Save the selected theme mode directly (e.g., "dark" or "light").
             */
            this.saveThemePreference(actualMode);
        }

        // Keep data-fct-theme in sync so the CSS-driven pre-rendered icon reflects
        // the active selection without waiting for Vue to re-render.
        document.documentElement.setAttribute('data-fct-theme', actualMode);

        this.dispatchThemeChangeEvent(mode, detail);

        if (_broadcast && Theme.#channel) {
            Theme.#channel.postMessage({ mode: actualMode });
        }
    }

    static dispatchThemeChangeEvent(mode, detail) {
        detail.theme = mode;
        window.dispatchEvent(new CustomEvent(this.THEME_CHANGE_EVENT, {
            detail
        }));
    }


    static isAdminBarEnabled(){
        return Arr.get(window, 'fluentCartAdminApp.is_admin_bar_showing', false);
    }

    static saveThemePreference(mode) {
        localStorage.setItem(this.STORAGE_KEY, mode);
        localStorage.removeItem(this.LEGACY_STORAGE_KEY);
    }


    static addDarkClass(element) {
        if (!element) return;
        if (this.isDark()) {
            element.classList.add(this.DARK_CLASS);
        }
    }

    static removeDarkClass(element) {
        if (!element) return;
        element.classList.remove(this.DARK_CLASS);
    }

    static toggleDarkClass(element) {
        if (!element) return;
        element.classList.toggle(this.DARK_CLASS);
    }

    listenForThemeChange() {
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        mediaQuery.addEventListener('change', () => {
            if (Theme.isSystem()) {
                Theme.apply(Theme.MODE_SYSTEM);
            }
        });
    }

    init() {
        this.listenForThemeChange();

        Theme.apply(Theme.getCurrentTheme(), {}, false);
    }
}

(new Theme).init();

export default Theme;
