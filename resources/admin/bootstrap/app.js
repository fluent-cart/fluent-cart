import {createApp} from 'vue'
import {createRouter, createWebHashHistory} from 'vue-router';
import {routes} from '@/routes';
import DashboardApplication from "../Application.vue";
import {ElMessageBox} from 'element-plus';
import ElementPlus from 'element-plus';
import {
    Upload,
    Top,
    Bottom,
    Edit,
    Delete,
    Tools,
    Back,
    Plus,
    CircleCheck,
    SuccessFilled,
    ZoomIn,
    Check
} from '@element-plus/icons-vue';

import {useAppMixin} from "@/mixin/useAppMixin";
import {useRouteMiddleware} from "@/mixin/useRouteMiddleware";
import {useNavigationMenuUpdateService} from "@/mixin/useNavigationMenuUpdateService";
import {animateDirective} from "../animation";
import translate from "@/utils/translator/Translator";
import NotFound from "@/Pages/NotFound.vue";
import Unauthorized from "@/Pages/Unauthorized.vue";
import AppConfig from "@/utils/Config/AppConfig";
import Rest from "@/utils/http/Rest";
import {addLicensingFallbackRoutes} from "@/Modules/Licensing/legacyLicensingRoutes.js";
import CustomerRightsNeedsUpdate from "@/Modules/Withdrawals/CustomerRightsNeedsUpdate.vue";
import {registerFctComponents} from "@/Bits/Components/Fct";


// Late priority: fills licensing routes only when Pro registered none (old Pro, no Pro).
window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fluent_cart/licensing_fallback', addLicensingFallbackRoutes, 999);

// Customer Rights now ships the withdrawal screen; an older release still active
// gets an "update" screen there instead of a 404.
if (AppConfig.get('app_config.customerRightsUpdate')) {
    window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fluent_cart/customer_rights_update', (routes) => {
        routes.withdrawal_requests = routes.withdrawal_requests || {
            name: 'withdrawal_requests',
            path: '/withdrawal-requests',
            component: CustomerRightsNeedsUpdate,
            meta: {active_menu: 'withdrawal_requests', title: translate('Withdrawal Requests'), permission: 'orders/view'},
        };
        return routes;
    }, 999);
}

const appRoutes = window.fluent_cart_admin.hooks.applyFilters('fluent_cart_routes', routes);


let showingNonceModal = false;



document.addEventListener('fcart_renew_rest_nonce', function (event) {

    if (showingNonceModal) {
        return;
    }
    ElMessageBox.alert(
        translate('Session_Session_Expired'),
        translate('Session Expired'),
        {
            // if you want to disable its autofocus
            // autofocus: false,
            confirmButtonText: translate('Reload'),
            callback: (action) => {
                showingNonceModal = false;
                window.location.reload()
            },
        }
    )
});


const elPlusIcons = [
    Top,
    Bottom,
    Edit,
    Delete,
    Tools,
    Back,
    Upload,
    Plus,
    CircleCheck,
    SuccessFilled,
    ZoomIn,
    Check
];

function convertToText(obj) {
    const string = [];
    if (typeof (obj) === 'object' && (obj.join === undefined)) {
        for (const prop in obj) {
            string.push(convertToText(obj[prop]));
        }
    } else if (typeof (obj) === 'object' && !(obj.join === undefined)) {
        for (const prop in obj) {
            string.push(convertToText(obj[prop]));
        }
    } else if (typeof (obj) === 'function') {

    } else if (typeof (obj) === 'string') {
        string.push(obj)
    }

    return string.join('<br />')
}

const app = createApp(DashboardApplication);

app.config.globalProperties.appVars = AppConfig.get();
app.provide('appVars', AppConfig.get());
app.mixin(useAppMixin());

app.directive('animate', animateDirective)

const router = createRouter({
        routes: [
            ...Object.values(appRoutes),
            {
                path: '/unauthorized',
                name: 'unauthorized',
                component: Unauthorized,
                props: true
            },

            {
                path: '/:path(.*)*',
                name: 'NotFound',
                component: NotFound,
                props: true
            }
        ],
        history: createWebHashHistory(),
        scrollBehavior(to, from, savedPosition) {
            if (savedPosition) {
                return savedPosition;
            } else {
                return {
                    top: 0,
                    behavior: "smooth",
                }
            }
        },
    },
);


app.use(router);
useRouteMiddleware(router);
useNavigationMenuUpdateService(router);

elPlusIcons.forEach(component => {
    app.component(component.name, component)
})
registerFctComponents(app);

const locale = AppConfig.get('el_strings');
const datei18 = AppConfig.get('datei18');

locale.el.datepicker.weeks = datei18.weekdaysShort;
locale.el.datepicker.months = datei18.monthsShort;

app.use(ElementPlus, {
    locale: locale
})
app.mount('#fluent_cart_plugin_app');

// Pending one-shot data backfills: silently call the runner until the server
// reports completed. Each call is budget-bounded server-side; 'locked' means
// another tab/request is already on it. No UI — failures simply retry on the
// next admin page load because has_data_migrations stays true until done.
if (AppConfig.get('has_data_migrations')) {
    let backfillCalls = 0;
    const runDataBackfills = () => {
        Rest.post('data-backfills/run')
            .then((res) => {
                if (res && res.status === 'running' && ++backfillCalls < 100) {
                    runDataBackfills();
                }
            })
            .catch(() => {
                // background maintenance — retried on the next admin page load
            });
    };
    runDataBackfills();
}
