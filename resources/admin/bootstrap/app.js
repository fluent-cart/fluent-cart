import {createApp} from 'vue'
import {createRouter, createWebHashHistory} from 'vue-router';
import {routes} from '@/routes';
import DashboardApplication from "../Application.vue";
import {ElLoadingDirective} from "element-plus";
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
import {useElementPlusComponents} from "@/mixin/useElementPlusComponents";
import {animateDirective} from "../animation";
import translate from "@/utils/translator/Translator";
import NotFound from "@/Pages/NotFound.vue";
import Unauthorized from "@/Pages/Unauthorized.vue";
import AppConfig from "@/utils/Config/AppConfig";


const appRoutes = window.fluent_cart_admin.hooks.applyFilters('fluent_cart_routes', routes);


let showingNonceModal = false;



document.addEventListener('fcart_renew_rest_nonce', function (event) {

    if (showingNonceModal) {
        return;
    }
    ElMessageBox.alert(
        translate('Your session has expired due to inactivity. Please refresh the page to continue.'),
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

app.directive('loading', ElLoadingDirective)
app.directive('animate', animateDirective)

useElementPlusComponents(app);

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

const locale = AppConfig.get('el_strings');
const datei18 = AppConfig.get('datei18');

locale.el.datepicker.weeks = datei18.weekdaysShort;
locale.el.datepicker.months = datei18.monthsShort;

app.use(ElementPlus, {
    locale: locale
})
app.mount('#fluent_cart_plugin_app');
