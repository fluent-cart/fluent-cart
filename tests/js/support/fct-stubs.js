import {h} from 'vue';
import {fctComponents} from '@/Bits/Components/Fct';

// fct-* components import Element Plus directly, so a global El* stub no longer reaches them:
// register them with the same stubs swapped in by fct name.
export const fctPlugin = (stubs = {}) => ({
    install: app => Object.keys(fctComponents).forEach(name => app.component(name, stubs[name] || fctComponents[name])),
});

// fct-popover is both a popover and a popconfirm (`confirm`); route each to its own stub.
export const popoverStub = ({confirm, popover}) => ({
    inheritAttrs: false,
    props: {confirm: Boolean},
    setup: (props, {attrs, slots}) => () => h(props.confirm ? confirm : popover, attrs, slots),
});
