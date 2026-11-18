<template>
    <div class="fluent_cart_field_routing">
      <div class="mb-4">
        <fct-choice v-model="settings[field.routing_key]" true-value="routing" false-value="simple">{{field.labels.choice_label}}</fct-choice>
      </div>

        <div>
            <fct-select
                v-if="field.simple_component == 'select'"
                :disabled="settings[field.routing_key] != 'simple'"
                filterable
                clearable
                :multiple="field.is_multiple"
                v-model="settings[field.key]"
                :placeholder="field.placeholder"
                :options="toOptions(field.options)"
            />
            <fct-input
                :disabled="settings[field.routing_key] != 'simple'"
                v-model="settings[field.key]"
                :placeholder="field.placeholder"
                v-else-if="field.simple_component == 'text'"/>
            <FiledGeneral
                v-else-if="field.simple_component == 'value_text' && settings[field.routing_key] == 'simple'"
                :editorShortcodes="editorShortcodes"
                v-model="settings[field.key]"
            />
            <p v-if="settings[field.routing_key] == 'simple' && field.inline_tip">{{field.inline_tip}}</p>
        </div>
        

        <RoutingFilterFields
            :routings="settings[field.settings_key]"
            :input_type="field.routing_input_type"
            :fields="inputs"
            :labels="field.labels"
            :input_options="field.options"
            :editorShortcodes="editorShortcodes"
            v-if="settings[field.routing_key] == 'routing'" />
  
    </div>
</template>

<script type="text/babel">
    import RoutingFilterFields from './RoutingFilterFields.vue';
    import FiledGeneral from './_FieldGeneral.vue';

    export default {
        name: 'SelectionRouting',
        components: {
            RoutingFilterFields,
            FiledGeneral
        },
        props: ['field', 'settings', 'inputs', 'editorShortcodes'],
        methods: {
            toOptions(options) {
            return Object.entries(options || {}).map(([value, label]) => ({value: Array.isArray(options) ? Number(value) : value, label}));
        }
        }
    }
</script>
<style lang="scss">
.fluent_cart_field_routing .el-select__tags
{
   .el-select__input{
        background-color: transparent !important;
        border: none !important;
        &:focus {
            border: none !important;
        }
    }
}
</style>
