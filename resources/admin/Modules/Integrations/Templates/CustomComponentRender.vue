<script type="text/babel">
import { compile, h } from 'vue';

export default {
    name: 'CustomComponentRender',
    props: {
        field: {
            type: Object,
            required: true
        },
        settings: {
            type: Object,
            required: true
        }
    },
    data() {
        return {
            message: 'This is a custom component render'
        }
    },
    render() {
        if (this.field && this.field.render_template) {
            const template = `<div>${this.field.render_template}</div>`;
            const renderFn = compile(template).bind(this);
            return h(renderFn, { settings: this.settings, app: this });
        }
        return null;
    }
}
</script>
