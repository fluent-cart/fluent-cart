document.addEventListener('click', function (event) {

    const toggleBtn = event.target.closest('[data-fct-modal-input-toggle-btn]');
    const clickedWrap = event.target.closest('[data-fct-modal-input-control-wrap]');
    const clickedInsideControls = event.target.closest('[data-fct-modal-input-controls]');

    /* -----------------------------------------
     * CLICK INSIDE OPEN INPUT → DO NOTHING
     * ----------------------------------------- */
    if (clickedInsideControls) {
        return;
    }

    /* -----------------------------------------
     * CLICK ON TOGGLE BUTTON
     * ----------------------------------------- */
    if (toggleBtn && clickedWrap) {
        event.preventDefault();

        // Close ALL other blocks
        document.querySelectorAll('[data-fct-modal-input-control-wrap]').forEach((wrap) => {
            if (wrap !== clickedWrap) {
                const inputControls = wrap.querySelector('[data-fct-modal-input-controls]');
                const btn = wrap.querySelector('[data-fct-modal-input-toggle-btn]');

                if (inputControls) inputControls.classList.add('fct-hidden');
                if (btn) btn.classList.remove('fct-hidden');
            }
        });

        // Toggle current block
        const inputControls = clickedWrap.querySelector('[data-fct-modal-input-controls]');
        if (!inputControls) return;

        toggleBtn.classList.add('fct-hidden');
        inputControls.classList.remove('fct-hidden');

        const input = inputControls.querySelector('input');
        if (input) input.focus();

        return;
    }

    /* -----------------------------------------
     * CLICK OUTSIDE ALL BLOCKS → CLOSE ALL
     * ----------------------------------------- */
    document.querySelectorAll('[data-fct-modal-input-control-wrap]').forEach((wrap) => {
        const inputControls = wrap.querySelector('[data-fct-modal-input-controls]');
        const btn = wrap.querySelector('[data-fct-modal-input-toggle-btn]');

        if (inputControls && !inputControls.classList.contains('fct-hidden')) {
            inputControls.classList.add('fct-hidden');
            if (btn) btn.classList.remove('fct-hidden');
        }
    });
});
