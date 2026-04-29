<script setup>
import {getCurrentInstance, onMounted, ref, computed} from "vue";
import Helper from "@/Bits/Components/Form/utils/Helper";
import Switch from "@/Bits/Components/Form/Components/Base/Inputs/Switch.vue";
import Checkbox from "@/Bits/Components/Form/Components/Base/Inputs/Checkbox.vue";
import Radio from "@/Bits/Components/Form/Components/Base/Inputs/RadioGroup.vue";
import Input from "@/Bits/Components/Form/Components/Base/Inputs/Input.vue";
import ColorPicker from "@/Bits/Components/Form/Components/Base/Inputs/ColorPicker.vue";
import Date from "@/Bits/Components/Form/Components/Base/Inputs/Date.vue";
import Time from "@/Bits/Components/Form/Components/Base/Inputs/Time.vue";
import Select from "@/Bits/Components/Form/Components/Base/Inputs/Select.vue";
import RemoteSearchableSelect from "@/Bits/Components/Form/Components/Base/Inputs/RemoteSearchableSelect.vue";
import Media from "@/Bits/Components/Form/Components/Base/Inputs/Media.vue";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";

const props = defineProps({
  form: {
    type: Object,
    required: true,
  },
  field: {
    type: Object,
    required: true,
  },
  value: {
    type: Object,
    required: true,
  },
  statePath: {
    type: String,
    required: true,
  },
  fieldKey: {
    type: String,
    required: true,
  },
  fullStatePath: {
    type: String,
    required: true,
  },
});

const ready = ref(false);
const selfRef = getCurrentInstance().ctx;
const getLabel = (field) => {
  if (typeof field.label === "function") {
    return field.label(props.value, props.statePath, props.form);
  }
  return field.label;
};

const callback = (callback, data) => {
  if (typeof callback === "function") {
    return callback({
      data: props.value,
      form: props.form,
      statePath: props.statePath,
      field: props.field,
      event: Array.isArray(data) && data.length === 1 ? data[0] : data,
    });
  }
};

const isAttributeReady = ref(false);
let attribute = {};

onMounted(async () => {
  attribute = await Helper.attributesBuilders(
      props.field,
      callback,
      props.form
  );
  isAttributeReady.value = true;
});

const fieldTypeComponents = {
  switch: Switch,
  checkbox: Checkbox,
  radio: Radio,
  input: Input,
  text: Input,
  email: Input,
  number: Input,
  textarea: Input,
  color: ColorPicker,
  date: Date,
  time: Time,
  select: Select,
  media: Media,
  remote_select: RemoteSearchableSelect,
};

const currentComponent = computed(() => {
  return fieldTypeComponents[props.field.type] || null;
});

const commonProps = computed(() => ({
  modelValue: props.value[props.fieldKey],
  form: props.form,
  statePath: props.statePath,
  field: props.field,
  fieldKey: props.fieldKey,
  value: props.value[props.fieldKey],
  nesting: false,
  label: getLabel(props.field),
  attribute: attribute,
  callback: callback,
}));
</script>

<template>
  <template v-if="field.type === 'wp-editor'">
    <WpEditor
        :shortCodes="field.shortcodes.data"
        :modelValue="props.value[props.fieldKey]"
        @update="
        (value) => {
          props.value[props.fieldKey] = value;
        }
      "
    />
  </template>

  <template v-else-if="currentComponent && isAttributeReady">
    <component
        @change="(value)=>{
          form.triggerChange(value)
        }"

        @input="(event)=>{
          form.triggerChange(value[fieldKey])
        }"
        :is="currentComponent"
        v-model="value[fieldKey]"
        v-bind="commonProps"
    />
  </template>
</template>