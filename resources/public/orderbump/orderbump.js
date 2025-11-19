//after page loaded
import Url from "@/utils/support/Url";

document.addEventListener('DOMContentLoaded', function () {

    //get button by data attribute  ata-fct_order_bump="yes"
    const buttons = document.querySelectorAll('[data-fct_order_bump]');

    if (buttons) {
        buttons.forEach(button => {
            button.addEventListener('input', function (event) {
                event.preventDefault();
                //dispatch fluentCartNotifyCartDrawerItemChanged event
                //retrive data-upgrade_to and data-replace_id value
                let upgradeTo = button.getAttribute('data-upgrade_to');
                let upgradeForm = button.getAttribute('data-upgrade_form');
                let bumpId = button.getAttribute('data-fct_bump_id');


                //determine is checked
                const isChecked = event.target.checked;

                if (!isChecked) {
                    upgradeForm = button.getAttribute('data-upgrade_to');
                    upgradeTo = button.getAttribute('data-upgrade_form');
                }

                if ((!upgradeTo || !upgradeForm) && !bumpId) {
                    return;
                }

                window.fluent_cart_checkout_ui_service?.disableCheckoutButton?.();

                let baseUrl = window.fluentcart_checkout_vars.ajaxurl + `?action=fluent_cart_checkout_routes&fc_checkout_action=apply_order_bump`;
                const searchParams = new URLSearchParams(window.location.search);
                const params = {};
                if (searchParams.has('fct_cart_hash')) {
                    params['fct_cart_hash'] = searchParams.get('fct_cart_hash');
                }
                params['upgrade_to'] = upgradeTo;
                params['upgrade_form'] = upgradeForm;
                params['is_upgraded'] = isChecked ? 'yes' : 'no';
                params['bump_id'] = bumpId;

                const url = Url.appendQueryParams(baseUrl, params);

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': window.fluentCartRestVars.rest.nonce,
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include'
                })
                    .then((response) => {
                        let responseJson = response.json();
                        let isOk = response.ok;
                        return responseJson.then(data => ({isOk, data}));
                    })
                    .then((data) => {
                        if (!data.isOk) {
                            throw new Error(data.data.message || 'Something went wrong');
                        }

                        if(data.data?.shipping_changed) {
                            // reload the page
                            window.location.reload();
                            return;
                        }

                        window.dispatchEvent(new Event('fluentCartNotifyCartDrawerItemChanged'));
                    })
                    .catch(error => {
                        // revert the checkbox state
                        button.checked = !isChecked;
                        window.fluent_cart_checkout_ui_service?.enableCheckoutButton?.();
                    });
            });
        });
    }
});
