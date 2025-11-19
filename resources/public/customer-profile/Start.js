import {createApp, nextTick} from 'vue'
import Dashboard from "./Vue/Dashboard.vue";
import PurchaseHistory from "./Vue/PurchaseHistory.vue";
import Licenses from "./Vue/Licenses.vue";
import Downloads from "./Vue/Downloads.vue";
import ManageLicense from "./Vue/ManageLicense.vue";
import Subscriptions from "./Vue/subcriptions/Subscriptions.vue";
import SingleSubscription from "./Vue/subcriptions/SingleSubscription.vue";
import PurchaseHistoryRoute from "./Vue/PurchaseHistoryRoute.vue";
import UserProfile from "./Vue/UserProfile/UserProfile.vue";

import SingleOrder from "./Vue/SingleOrder.vue";
import App from "./Vue/App.vue";
import {createRouter, createWebHistory} from "vue-router";
import Rest from "@/utils/http/Rest";
import ElementPlus, {ElMessageBox, ElNotification, ElLoadingDirective} from 'element-plus';
import {useElementPlusComponents} from "./mixin/useElementPlusComponents";
import Translate from "./translator/Translator";
import {formatDate} from "@/Bits/common";


const routes = [
    {
        path: '/',
        name: 'dashboard',
        component: Dashboard,
        meta: {
            active_menu: 'dashboard',
            title: 'dashboard'
        },
    },
    {
        path: '/purchase-history',
        name: 'purchase-history',
        component: PurchaseHistory,
        meta: {
            active_menu: 'purchase-history',
            title: 'Purchase History'
        },
    },
    {
        path: '/order',
        component: PurchaseHistoryRoute,
        children: [
            {
                name: 'view_order',
                path: ':order_id',
                component: SingleOrder,
                props: true,
                meta: {
                    active_menu: 'purchase-history',
                    title: 'View Order'
                }
            }
        ]
    },
    {
        path: '/subscriptions',
        name: 'subscriptions',
        component: Subscriptions,
        meta: {
            active_menu: 'subscriptions',
            title: 'Subscriptions'
        },
    },
    {
        name: 'view_subscription',
        path: '/subscription/:subscription_uuid',
        component: SingleSubscription,
        props: true,
        meta: {
            active_menu: 'subscriptions',
            title: 'View Subscription'
        }
    },
    {
        path: '/downloads',
        name: 'downloads',
        component: Downloads,
        meta: {
            active_menu: 'downloads',
            title: 'Downloads'
        },
    },
    {
        path: '/licenses',
        name: 'licenses',
        component: Licenses,
        meta: {
            active_menu: 'licenses',
            title: 'Licenses'
        },
    },
    {
        name: 'manage_license',
        path: '/licenses/:license_key/view',
        component: ManageLicense,
        props: true,
        meta: {
            active_menu: 'licenses',
            title: 'Manage License'
        }
    },
    {
        name: 'profile',
        path: '/profile',
        component: UserProfile,
        meta: {
            active_menu: 'profile',
            title: 'Profile'
        }
    }
];


const router = createRouter(
    {
        routes: routes,
        history: createWebHistory(window.fluentcart_customer_profile_vars.app_slug)
    },
);

// Global afterEach hook to update the document's title based on the route's meta.title.
// If a route has a meta.title, it sets that as the document title.
// Otherwise, it falls back to a default title when no meta.title is provided.
router.afterEach((to) => {
    const defaultTitle = 'Customer Dashboard';
    const activeMenu = to.meta?.active_menu;

    document.querySelectorAll('.fct-customer-navs li').forEach((el) => {
        el.classList.remove('active_customer_menu');
    });

    if (activeMenu) {
        const activeItem = document.querySelector('.fct-customer-nav-item-' + activeMenu);
        if (activeItem) {
            activeItem.classList.add('active_customer_menu');
        }
    }
});


// Replace jQuery selector with native JavaScript
const containers = document.querySelectorAll('[data-fluent-cart-customer-profile-app]');
if (containers && containers.length > 0) {

    const container = containers[0];

    const app = createApp({});

    useElementPlusComponents(app);

    app.config.globalProperties.$notify = ElNotification;
    app.config.globalProperties.$confirm = ElMessageBox.confirm;
    app.config.globalProperties.appVars = window.fluentcart_customer_profile_vars;

    app.directive('loading', ElLoadingDirective);
    app.mixin({
        methods: {
            $get: Rest.get,
            $post: Rest.post,
            $put: Rest.put,
            $del: Rest.delete,
            formatNumber(amount, currency = true, hideEmpty = false) {
                if (!amount && hideEmpty) {
                    return '';
                }

                if (!amount) {
                    amount = '0.00';
                } else {
                    amount = (amount / 100).toFixed(2);
                }

                if (!currency) {
                    return amount;
                }

                let locale = window.fluentcart_customer_profile_vars.wp_locale.replace('_', '-');
                amount = new Intl.NumberFormat(locale).format(amount);

                let currency_position = window.fluentcart_customer_profile_vars.shop.currency_position;
                let currency_sign = window.fluentcart_customer_profile_vars.shop.currency_sign;

                if (currency_position === 'before') {
                    return currency_sign + amount;
                }

                return amount + currency_sign;
            },
            $t: Translate,
            handleError(response) {
                let msg = '';
                if (typeof response === 'string') {
                    msg = response;
                } else if (response && response.message) {
                    msg = response.message;
                }
                if (!msg) {
                    msg = 'Something is wrong!';
                }
                this.$notify({
                    type: 'error',
                    title: 'Error',
                    offset: 30,
                    message: msg,
                    dangerouslyUseHTMLString: true,
                    duration: 1000
                });
            },
            handleSuccess(response) {
                let msg = 'Success';
                if (typeof response === 'string') {
                    msg = response;
                } else if (response && response.message) {
                    msg = response.message;
                } else {
                    msg = convertToText(response);
                }
                if (!msg) {
                    msg = 'Success!';
                }

                this.$notify({
                    type: 'success',
                    title: 'Success',
                    offset: 30,
                    message: msg,
                    dangerouslyUseHTMLString: true,
                    duration: 1000
                });
            },
            registerCopyAction() {
                let ref = this;
                nextTick(() => {
                    // Replace jQuery with native JavaScript
                    const copyableElements = document.querySelectorAll('.copyable-content');
                    copyableElements.forEach(element => {
                        element.addEventListener('click', function () {
                            // Use modern Clipboard API if available, fallback to legacy method
                            const textToCopy = this.textContent;

                            if (navigator.clipboard && window.isSecureContext) {
                                // Modern approach using Clipboard API
                                navigator.clipboard.writeText(textToCopy).then(() => {
                                    // ref.handleSuccess('Copied to clipboard');
                                }).catch(err => {
                                    console.error('Failed to copy text: ', err);
                                    // Fallback to legacy method
                                    fallbackCopyTextToClipboard(textToCopy);
                                });
                            } else {
                                // Fallback for older browsers or non-secure contexts
                                fallbackCopyTextToClipboard(textToCopy);
                            }
                        });
                    });

                    // Fallback function for legacy copy method
                    function fallbackCopyTextToClipboard(text) {
                        let inputElem = document.createElement('input');
                        inputElem.value = text;
                        document.body.appendChild(inputElem);
                        inputElem.select();
                        inputElem.setSelectionRange(0, 99999); // For mobile devices
                        try {
                            document.execCommand('copy');
                            // ref.handleSuccess('Copied to clipboard');
                        } catch (err) {
                            console.error('Fallback: Could not copy text: ', err);
                        }
                        document.body.removeChild(inputElem);
                    }
                })
            },
            getAddress(object, type = 'billing', shouldExcludeName = false) {
                if (!object) return '';

                // Initialize an empty array for the address components
                let address = [];

                // Only add the name if shouldExcludeName is false
                if (!shouldExcludeName && object['name']) {
                    address.push(object['name']);
                }

                // Push remaining components to the address array
                if (object['address_1']) address.push(object['address_1']);
                if (object['address_2']) address.push(object['address_2']);
                if (object['city']) address.push(object['city']);
                if (object['state']) address.push(object['state']);
                if (object['postcode']) address.push(object['postcode']);
                if (object['country']) address.push(object['country']);
                if (object['phone']) address.push(object['phone']);

                // Join the address components with a comma and a space
                let formattedAddress = address.join(', ');

                // Return the formatted address or a fallback message
                return formattedAddress || 'No ' + type + ' address provided';
            },
            readableFileSize(bytes) {
                // Converts bytes to a human-readable format (e.g., KB, MB, GB)
                // Example: 1024 -> "1 KB"
                // Example: 1048576 -> "1 MB"

                if (!bytes && bytes !== 0) return '';
                const units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
                const i = Math.floor(Math.log2(bytes) / 10);
                const size = bytes / 1024 ** i;
                return `${+size.toFixed(2)} ${units[i]}`;
            },
            formatDate: formatDate,
        }
    });


    const locale = window.fluentcart_customer_profile_vars.el_strings;
    const datei18 = window.fluentcart_customer_profile_vars.datei18;

    locale.el.datepicker.weeks = Object.values(datei18.weekdaysShort);
    locale.el.datepicker.months = Object.values(datei18.monthsShort);

    app.use(ElementPlus, {
        locale: locale
    })

    app.use(router).component('app', App).mount(container);
}


document.addEventListener('DOMContentLoaded', () => {

    let appUrl = window.fluentcart_customer_profile_vars.app_url;

    document.querySelectorAll('a.fct_route').forEach(link => {
        // add event listener to each link
        link.addEventListener('click', (e) => {
            const url = link.getAttribute('href');
            if (!url) {
                return;
            }
            const relativeUrl = url.replace(appUrl, '');
            try {
                const resolved = router.resolve(relativeUrl);
                if (resolved.matched.length > 0) {
                    e.preventDefault();
                    router.push(relativeUrl);
                    return true // Successfully navigated
                }
            } catch (error) {
                console.error('Navigation error:', error);
            }
        });
    });
});
