document.addEventListener('DOMContentLoaded', function () {
    const data_fct_menu_toggle = document.querySelector('[data-fct-menu-toggle]');
    const parent = data_fct_menu_toggle.parentNode;
    const menuWrapper = parent.querySelector('[data-fct-offcanvas-menu]');
    const menuCloseButton = parent.querySelector('[data-fct-offcanvas-menu-close]');
    const overlay = parent.querySelector('[data-fct-offcanvas-menu-overlay]');

    data_fct_menu_toggle.addEventListener('click', () => {
        if (menuWrapper) {
            overlay.classList.toggle('active');
            menuWrapper.classList.toggle('open');
            document.body.style.overflow = 'hidden';
        }
    });

    menuCloseButton.addEventListener('click', () => {
        if (menuWrapper) {
            overlay.classList.remove('active');
            menuWrapper.classList.remove('open');
            document.body.style.overflow = '';
        }
    });

    overlay.addEventListener('click', () => {
        if (menuWrapper) {
            overlay.classList.remove('active');
            menuWrapper.classList.remove('open');
            document.body.style.overflow = '';
        }
    });
});
