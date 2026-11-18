<template>
  <div class="fluent_cart_routing_fields">
    <template v-if="!disabled">
      <table class="fluent_cart_routing_table">
        <tbody>
          <tr v-for="(routing, key) in routings" :key="key">
            <td>
              <label class="fluent_cart_inline">
                {{ labels.input_label }}
              </label>
              <fct-input
                v-if="input_type == 'text'"
                :placeholder="labels.input_placeholder"
                v-model="routing.input_value"
              />
              <fct-select
                v-else-if="input_type == 'select'"
                v-model="routing.input_value"
                :placeholder="labels.input_placeholder"
                :options="toOptions(input_options)"
              />
            </td>
            <td>If</td>
            <td>
              <FieldGeneral
                :editorShortcodes="editorShortcodes"
                v-model="routing.field"
                :defaultValue="routing.field"
                @change="routing.value = ''"
              />
              <!-- might use the below component later -->
              <!-- <el-select
                v-model="routing.field"
                style="width: 100%"
                @change="routing.value = ''"
              >
                <el-option
                  v-for="(field, key) in fields"
                  :key="key"
                  :label="field.admin_label || field.attributes.name"
                  :value="field.element"
                ></el-option>
              </el-select> -->
            </td>
            <td>
              <fct-select v-model="routing.operator" class="w-[100px]" :options="operatorOptions(routing)"/>
            </td>
            <td>
              <fct-select
                v-if="
                  fields[routing.field] &&
                  Object.keys(fields[routing.field].options).length
                "
                v-model="routing.value"
                style="width: 100%"
                :options="toOptions(fields[routing.field].options)"
              />
              <fct-input
                v-else
                :placeholder="$t('Enter a value')"
                v-model="routing.value"
              />
            </td>
            <td>
              <div class="action-btns">
                <IconButton
                  size="x-small"
                  tag="button"
                  @click.prevent="add(key)"
                >
                  <DynamicIcon name="Plus" />
                </IconButton>
                <IconButton
                  size="x-small"
                  tag="button"
                  @click.prevent="remove(key)"
                  v-if="routings.length > 1"
                >
                  <DynamicIcon name="Minus" />
                </IconButton>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>

<script type="text/babel">
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import FieldGeneral from "./_FieldGeneral.vue";

export default {
  name: "RoutingFilterFields",
  components: {
    DynamicIcon,
    IconButton,
    FieldGeneral,
  },
  props: {
    routings: {
      type: Array,
      required: true,
      default() {
        return [];
      },
    },
    fields: {
      type: Object,
      required: true,
      default() {
        return {};
      },
    },
    disabled: {
      type: Boolean,
      required: false,
      default: false,
    },
    labels: {
      default: () => ({
        input_label: "Send To",
        input_placeholder: "Email Address",
      }),
    },
    input_type: {
      type: String,
      default: "text",
    },
    input_options: {
      type: Object,
      required: false,
      default() {
        return {};
      },
    },
    editorShortcodes: {
      type: Object,
    },
  },
  data() {
    return {
      defaultRules: {
        input_value: "",
        field: "",
        operator: "=",
        value: null,
      },
      comingSoon: false,
    };
  },
  mounted() {
    if (!this.routings || !this.routings.length) {
      this.routings.push({ ...this.defaultRules });
    }
  },
  methods: {
    operatorOptions(routing) {
      const options = [
        {value: "=", label: "equal"},
        {value: "!=", label: "not equal"},
        {value: ">", label: "greater than"},
        {value: "<", label: "less than"},
        {value: ">=", label: "greater than or equal"},
        {value: "<=", label: "less than or equal"},
      ];
      const field = this.fields[routing.field];
      if (field && !Object.keys(field.options).length) {
        options.push(
          {value: ">", label: "greater than"},
          {value: "<", label: "less than"},
          {value: ">=", label: "greater than or equal"},
          {value: "<=", label: "less than or equal"},
          {value: "contains", label: "contains"},
          {value: "doNotContains", label: "do not contains"},
          {value: "startsWith", label: "starts with"},
          {value: "endsWith", label: "ends with"},
        );
      }
      return options;
    },
    toOptions(options) {
      return Object.entries(options || {}).map(([value, label]) => ({value: Array.isArray(options) ? Number(value) : value, label}));
    },
    add(index) {
      this.routings.splice(index + 1, 0, { ...this.defaultRules });
    },
    remove(index) {
      this.routings.splice(index, 1);
    },
  },
};
</script>
