// `@fluent-cart/vue` — core's Vue instance for add-on bundles. Only names a
// shipped add-on imports (its compiled templates included); Pro's
// licensing-module-bundle test fails when its build needs one missing here.
export {
    // Composition API
    computed,
    defineAsyncComponent,
    defineComponent,
    getCurrentInstance,
    nextTick,
    onMounted,
    onUnmounted,
    ref,
    unref,
    watch,
    // Template compiler helpers
    Fragment,
    createBlock,
    createCommentVNode,
    createElementBlock,
    createElementVNode,
    createTextVNode,
    createVNode,
    normalizeClass,
    normalizeStyle,
    openBlock,
    renderList,
    resolveComponent,
    resolveDynamicComponent,
    toDisplayString,
    withCtx,
    withKeys,
    withModifiers,
} from 'vue';
