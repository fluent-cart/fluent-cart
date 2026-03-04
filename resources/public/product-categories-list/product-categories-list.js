document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-fct-categories-go-btn]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var select = btn.closest('[data-fct-categories-dropdown-wrap]').querySelector('[data-fct-categories-dropdown]');
            if (select && select.value) {
                window.location.href = select.value;
            }
        });
    });
});