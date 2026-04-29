// import * as VueInstance from 'vue';
// import translate from "@/utils/translator/Translator";
// const instance = VueInstance.getCurrentInstance();

const hooks = window.wp.hooks.createHooks();
window.fluentCartAdminHooks = hooks;

window.fluent_cart_admin = {
    'hooks': hooks,
    // vue: VueInstance.readonly(VueInstance),
    // components: instance.appContext.components,
    // $t: translate,
    // translate: translate
};