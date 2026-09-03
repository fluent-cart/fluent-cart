/**
 * Dark Mode Toggle for Taxonomy Pages
 * Populates #theme-button-container on WordPress native pages (edit-tags.php)
 * Uses same localStorage key and logic as Vue Theme.js
 */

const DarkModeToggle = (() => {
    const STORAGE_KEY = 'fluent_theme_mode';
    const LEGACY_STORAGE_KEY = 'fcart_admin_theme';
    const DARK_CLASS = 'fluent_theme_dark';
    const MODE_LIGHT = 'light';
    const MODE_DARK = 'dark';
    const MODE_SYSTEM = 'system';

    const channel = (typeof BroadcastChannel !== 'undefined')
        ? new BroadcastChannel('fluent_theme_changed:' + window.location.origin)
        : null;

    // Get current theme from localStorage
    const getSavedTheme = () => {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) return saved;

        const legacy = localStorage.getItem(LEGACY_STORAGE_KEY);
        if (legacy) {
            localStorage.setItem(STORAGE_KEY, legacy);
            localStorage.removeItem(LEGACY_STORAGE_KEY);
            return legacy;
        }
        return null;
    };

    // Get system preference (dark or light)
    const getSystemTheme = () => {
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return MODE_DARK;
        }
        return MODE_LIGHT;
    };

    // Get current effective theme
    const getCurrentTheme = () => {
        const allowed = [MODE_LIGHT, MODE_DARK, MODE_SYSTEM];
        const saved = getSavedTheme();
        if (allowed.includes(saved)) {
            return saved;
        }
        return MODE_SYSTEM;
    };

    // Get icon SVG for theme mode
    const getIconSVG = (mode) => {
        if (mode === MODE_LIGHT) {
            return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" class=""><path d="M10 14.5C8.80653 14.5 7.66193 14.0259 6.81802 13.182C5.97411 12.3381 5.5 11.1935 5.5 10C5.5 8.80653 5.97411 7.66193 6.81802 6.81802C7.66193 5.97411 8.80653 5.5 10 5.5C11.1935 5.5 12.3381 5.97411 13.182 6.81802C14.0259 7.66193 14.5 8.80653 14.5 10C14.5 11.1935 14.0259 12.3381 13.182 13.182C12.3381 14.0259 11.1935 14.5 10 14.5ZM10 13C10.7956 13 11.5587 12.6839 12.1213 12.1213C12.6839 11.5587 13 10.7956 13 10C13 9.20435 12.6839 8.44129 12.1213 7.87868C11.5587 7.31607 10.7956 7 10 7C9.20435 7 8.44129 7.31607 7.87868 7.87868C7.31607 8.44129 7 9.20435 7 10C7 10.7956 7.31607 11.5587 7.87868 12.1213C8.44129 12.6839 9.20435 13 10 13ZM9.25 1.75H10.75V4H9.25V1.75ZM9.25 16H10.75V18.25H9.25V16ZM3.63625 4.69675L4.69675 3.63625L6.2875 5.227L5.227 6.2875L3.63625 4.6975V4.69675ZM13.7125 14.773L14.773 13.7125L16.3638 15.3032L15.3032 16.3638L13.7125 14.773ZM15.3032 3.6355L16.3638 4.69675L14.773 6.2875L13.7125 5.227L15.3032 3.63625V3.6355ZM5.227 13.7125L6.2875 14.773L4.69675 16.3638L3.63625 15.3032L5.227 13.7125ZM18.25 9.25V10.75H16V9.25H18.25ZM4 9.25V10.75H1.75V9.25H4Z" fill="currentColor"></path></svg>`;
        } else if (mode === MODE_DARK) {
            return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" class=""><path d="M8.5 6.25C8.49985 7.29298 8.81035 8.31237 9.39192 9.17816C9.97348 10.0439 10.7997 10.7169 11.7653 11.1112C12.7309 11.5055 13.7921 11.6032 14.8134 11.3919C15.8348 11.1807 16.7701 10.67 17.5 9.925V10C17.5 14.1423 14.1423 17.5 10 17.5C5.85775 17.5 2.5 14.1423 2.5 10C2.5 5.85775 5.85775 2.5 10 2.5H10.075C9.57553 2.98834 9.17886 3.57172 8.90836 4.21576C8.63786 4.8598 8.49902 5.55146 8.5 6.25ZM4 10C3.99945 11.3387 4.44665 12.6392 5.27042 13.6945C6.09419 14.7497 7.24723 15.4992 8.54606 15.8236C9.84489 16.148 11.2149 16.0287 12.4381 15.4847C13.6614 14.9407 14.6675 14.0033 15.2965 12.8215C14.1771 13.0852 13.0088 13.0586 11.9026 12.744C10.7964 12.4295 9.78888 11.8376 8.97566 11.0243C8.16244 10.2111 7.57048 9.20361 7.25596 8.09738C6.94144 6.99116 6.91477 5.82292 7.1785 4.7035C6.21818 5.2151 5.41509 5.97825 4.85519 6.91123C4.2953 7.84422 3.99968 8.91191 4 10Z" fill="currentColor"></path></svg>`;
        } else {
            return `<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" class=""><path d="M6.875 4.375V6.25H4.375V7.5H6.875V9.375H8.125V4.375H6.875ZM9.375 7.5H15.625V6.25H9.375V7.5ZM13.125 10.625V12.5H15.625V13.75H13.125V15.625H11.875V10.625H13.125ZM10.625 13.75H4.375V12.5H10.625V13.75Z" fill="currentColor"></path></svg>`;
        }
    };

    // Create dropdown menu HTML
    const createDropdownHTML = (currentTheme) => {
        const options = [
            { value: MODE_LIGHT, label: 'Light', icon: MODE_LIGHT },
            { value: MODE_DARK, label: 'Dark', icon: MODE_DARK },
            { value: MODE_SYSTEM, label: 'System', icon: MODE_SYSTEM }
        ];

        const items = options.map(opt => `
            <li role="none">
                <button type="button" class="fct-theme-dropdown__item ${currentTheme === opt.value ? 'is-active' : ''}"
                        data-theme="${opt.value}" role="menuitem">
                    ${getIconSVG(opt.icon)}
                    <span>${opt.label}</span>
                </button>
            </li>
        `).join('');

        return `<ul class="fct-theme-dropdown" role="menu">${items}</ul>`;
    };

    // Apply theme to document
    const applyTheme = (mode, _broadcast = true) => {
        const actualMode = mode;

        if (mode === MODE_SYSTEM) {
            mode = getSystemTheme();
        }

        // Clean up the class the inline head script placed on <html> (body wasn't
        // available yet when that script ran). The spec requires the class on <body>.
        document.documentElement.classList.remove(DARK_CLASS);

        if (mode === MODE_DARK) {
            document.body.classList.add(DARK_CLASS);
        } else {
            document.body.classList.remove(DARK_CLASS);
        }

        // Update data-fct-theme so the CSS-driven pre-rendered icon stays in sync.
        document.documentElement.setAttribute('data-fct-theme', actualMode);

        // Save preference
        if (actualMode === MODE_SYSTEM) {
            localStorage.setItem(STORAGE_KEY, `${actualMode}:${mode}`);
        } else {
            localStorage.setItem(STORAGE_KEY, actualMode);
        }

        if (_broadcast && channel) {
            channel.postMessage({ mode: actualMode });
        }
    };

    if (channel) {
        channel.onmessage = function(event) {
            if (event.data && event.data.mode) {
                applyTheme(event.data.mode, false);
            }
        };
    }

    // Initialize toggle button
    const init = () => {
        const container = document.getElementById('theme-button-container');
        if (!container) return;

        const currentTheme = getCurrentTheme();

        // Use the PHP pre-rendered button if present; otherwise inject a fallback.
        let button = container.querySelector('.theme-selected-button');
        if (!button) {
            container.innerHTML = `
                <div class="fct-theme-button-container">
                    <button class="theme-selected-button" type="button" aria-haspopup="true"
                            aria-label="Toggle color theme">
                        ${getIconSVG(currentTheme)}
                    </button>
                </div>
            `;
            button = container.querySelector('.theme-selected-button');
        }

        let dropdownWrapper = null;

        const showDropdown = () => {
            if (dropdownWrapper) {
                const dropdown = dropdownWrapper.querySelector('.fct-theme-dropdown');
                dropdown.style.animation = 'fct-dropdown-hide 0.15s ease-out forwards';
                setTimeout(() => {
                    dropdownWrapper.remove();
                    dropdownWrapper = null;
                }, 150);
                return;
            }

            dropdownWrapper = document.createElement('div');
            dropdownWrapper.id = 'fct-theme-dropdown-container';
            dropdownWrapper.innerHTML = createDropdownHTML(getCurrentTheme());
            document.body.appendChild(dropdownWrapper);

            const dropdown = dropdownWrapper.querySelector('.fct-theme-dropdown');

            const rect = button.getBoundingClientRect();
            dropdown.style.position = 'fixed';
            dropdown.style.top = (rect.bottom + 5) + 'px';
            dropdown.style.right = (window.innerWidth - rect.right) + 'px';
            dropdown.style.zIndex = '2000';
            dropdown.style.minWidth = '150px';

            dropdown.querySelectorAll('.fct-theme-dropdown__item').forEach(item => {
                item.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    const theme = item.getAttribute('data-theme');
                    applyTheme(theme);
                    showDropdown();
                    button.focus();
                });
            });

            // Focus the first (or active) menu item so keyboard users can act immediately.
            const activeItem = dropdown.querySelector('.fct-theme-dropdown__item.is-active')
                || dropdown.querySelector('.fct-theme-dropdown__item');
            if (activeItem) activeItem.focus();

            const onKeyDown = (e) => {
                if (e.key === 'Escape') {
                    document.removeEventListener('keydown', onKeyDown);
                    showDropdown();
                    button.focus();
                }
            };
            document.addEventListener('keydown', onKeyDown);

            document.addEventListener('click', (e) => {
                if (dropdown && e.target !== button && !dropdown.contains(e.target)) {
                    showDropdown();
                }
            }, { once: true });
        };

        button.addEventListener('click', (e) => {
            e.stopPropagation();
            showDropdown();
        });

        applyTheme(currentTheme);

        if (window.matchMedia) {
            const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            mediaQuery.addEventListener('change', () => {
                if (getCurrentTheme() === MODE_SYSTEM) {
                    applyTheme(MODE_SYSTEM);
                }
            });
        }
    };

    return { init };
})();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        DarkModeToggle.init();
    });
} else {
    DarkModeToggle.init();
}
