<template>
    <div class="fct-field-general">
        <input-popover :fieldType="field_type"
            :placeholder="$t('Select a Field or Type Custom value')"
            v-model="fieldValue"
            icon="el-icon-arrow-down"
            :data="editorShortcodes"
            @onValueUpdated="function(value) { 
                onValueUpdated(value) 
            }"
            :defaultValue="fieldValue"
        ></input-popover>
    </div>
</template>

<script type="text/babel">
    import inputPopover from './input-popover.vue';

    export default {
        name: 'FieldGeneral',
        components: {
            inputPopover
        },
        props: {
            value: [String, Number, Boolean, Array],
            editorShortcodes: Array,
            field_type: {
                type: String,
                default: 'text'
            },
            defaultValue: {
                type: [String, Number ],
                default: ''
            }
            
        },
        data() {
            return {
                fieldValue: this.defaultValue
            }
        },
        watch: {
            fieldValue() {
                this.$emit('update:modelValue', this.fieldValue)
            }
        },
        methods:{
            onValueUpdated(value) {
                this.fieldValue = value;
                this.$emit('onValueUpdated', this.fieldValue);
            },
        },
    }
</script>
