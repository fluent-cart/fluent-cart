<template>
    <div>
        <fct-choice v-model="conditionals.status" v-if="!disabled">
            {{ labels.status_label }}
        </fct-choice>

        <fct-choice v-else disabled @click="comingSoon = true">
            {{ labels.status_label }}
        </fct-choice>

        <div v-if="conditionals.status">
            {{ labels.notification_if_start }}

            <!-- label is the bare value: the picked value showed "all" while the list shows "All". -->
            <fct-select class="fluent_cart_inline_small" v-model="conditionals.type" :options="[{label: 'all', value: 'all', text: 'All'}, {label: 'any', value: 'any', text: 'Any'}]">
                <template #option="{option}">{{ option.text }}</template>
            </fct-select>

            {{ labels.notification_if_end }}

            <!-- Was el-row :gutter="10" with el-col :md spans (Element Plus md = 992px). -->
            <div v-for="(logic, key) in items" :key="key"
                    class="flex flex-wrap box-border -mx-[5px]" style="margin-top: 15px;"
            >
                <div class="box-border px-[5px] w-full min-[992px]:w-1/3">
                    <fct-select size="default" v-model="items[key].field" style="width: 100%" @change="items[key].value = ''"
                                :options="Object.keys(fields || {}).map((fieldKey) => ({label: fields[fieldKey].admin_label, value: Array.isArray(fields) ? Number(fieldKey) : fieldKey}))"
                    />
                </div>

                <div class="box-border px-[5px] w-full min-[992px]:w-[20.8333%]">
                    <fct-select size="default" v-model="items[key].operator" :options="operatorOptions(logic)"/>
                </div>

                <div class="box-border px-[5px] w-full min-[992px]:w-1/3">
                    <template v-if="items[key].operator == 'length_equal' || items[key].operator == 'length_less_than' || items[key].operator == 'length_greater_than'">
                        <fct-input size="default" type="number" :controls="false" step="1" placeholder="Enter length in number" v-model="items[key].value" />
                    </template>
                    <template v-else>
                        <fct-select size="default" v-if="fields[logic.field] && Object.keys(fields[logic.field].options).length"
                                   v-model="items[key].value" style="width: 100%"
                                   :options="Object.entries(fields[logic.field].options).map(([value, label]) => ({value: Array.isArray(fields[logic.field].options) ? Number(value) : value, label}))"
                        />
                        <fct-input size="default" v-else placeholder="Enter a value" v-model="items[key].value"/>
                    </template>
                </div>

                <div class="box-border px-[5px] w-full min-[992px]:w-1/8 action-btns ">
                    <i class="el-icon-plus" @click="add(key)"></i>

                    <i class="el-icon-minus" @click="remove(key)"
                       v-if="items.length > 1"
                    ></i>
                </div>
            </div>
        </div>

    </div>
</template>

<script>

export default {
    name: 'FilterFields',
    props: {
        conditionals: {
            type: Object,
            required: true,
            default: {}
        },
        fields: {
            type: Object,
            required: true,
            default: {}
        },
        editorShortcodes: {
            type: Array,
            default: () => []
        },
        disabled: {
            type: Boolean,
            required: true,
            default: false,
        },
        labels: {
            default: () => ({
                status_label: 'Enable conditional logic',
                notification_if_start: 'Send this notification if',
                notification_if_end: 'of the following match:'
            })
        }
    },
    data() {
        return {
            defaultRules: {
                field: null,
                operator: '=',
                value: null
            },
            comingSoon: false,
        }
    },
    computed: {
        items() {
            return this.conditionals.conditions;
        }
    },
    mounted() {
        if (!this.conditionals.conditions.length) {
            this.conditionals.conditions.push({...this.defaultRules});
        }
    },
    methods: {
        operatorOptions(logic) {
            const field = this.fields[logic.field];
            const general = [{value: '=', label: 'equals'}, {value: '!=', label: 'not equal'}];
            if (field && !Object.keys(field.options).length) {
                general.push(
                    {value: '>', label: 'greater than'},
                    {value: '<', label: 'less than'},
                    {value: '>=', label: 'greater than or equal'},
                    {value: '<=', label: 'less than or equal'},
                    {value: 'contains', label: 'contains'},
                    {value: 'doNotContains', label: 'do not contains'},
                    {value: 'startsWith', label: 'starts with'},
                    {value: 'endsWith', label: 'ends with'}
                );
            }
            return [
                {label: 'General Operators', options: general},
                {label: 'Advanced Operators', options: [
                    {value: 'length_equal', label: 'Equal to Data Length'},
                    {value: 'length_less_than', label: 'Less than to Data length'},
                    {value: 'length_greater_than', label: 'Greater than to Data Length'}
                ]}
            ];
        },
        add(index) {
            this.items.splice(index + 1, 0, {...this.defaultRules});
        },
        remove(index) {
            this.items.splice(index, 1);
        }
    }
};
</script>
