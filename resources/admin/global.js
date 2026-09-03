document.addEventListener('DOMContentLoaded', function () {
    const data_fct_menu_toggle = document.querySelector('[data-fct-menu-toggle]');
    if (!data_fct_menu_toggle) return;

    const parent = data_fct_menu_toggle.parentNode;
    const menuWrapper = parent.querySelector('[data-fct-offcanvas-menu]');
    const menuCloseButton = parent.querySelector('[data-fct-offcanvas-menu-close]');
    const overlay = parent.querySelector('[data-fct-offcanvas-menu-overlay]');

    const closeMenu = () => {
        if (overlay) overlay.classList.remove('active');
        if (menuWrapper) menuWrapper.classList.remove('open');
        document.body.style.overflow = '';
    };

    data_fct_menu_toggle.addEventListener('click', () => {
        if (!menuWrapper || !overlay) return;
        overlay.classList.toggle('active');
        menuWrapper.classList.toggle('open');
        document.body.style.overflow = 'hidden';
    });

    if (menuCloseButton) menuCloseButton.addEventListener('click', closeMenu);
    if (overlay) overlay.addEventListener('click', closeMenu);
});
