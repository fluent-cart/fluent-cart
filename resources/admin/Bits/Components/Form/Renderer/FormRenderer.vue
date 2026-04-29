<script setup>
import {getCurrentInstance, ref} from "vue";
import FieldNote from "@/Bits/Components/Form/Components/Base/Inputs/FieldNote.vue";
import ValidationError from "@/Bits/Components/Form/Error/ValidationErrorNew.vue";
import Resolver from "@/Bits/Components/Form/Components/Resolver.vue";
import LayoutRenderer from "@/Bits/Components/Form/Renderer/LayoutRenderer.vue";
import InputRenderer from "@/Bits/Components/Form/Renderer/InputRenderer.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";

const props = defineProps({
  form: {
    type: Object,
    required: true
  },
  schema: {
    type: Object
  },
  value: {
    type: Object
  },
  statePath: {
    type: String
  }
})

const fullStatePath = props.statePath ?? '';

const formLayouts = ['grid', 'section', 'tab', 'tab-pane'];

const ready = ref(false)
const selfRef = getCurrentInstance().ctx;
const getLabel = (field, fieldKey) => {
  if (typeof field.label === "function") {

    let statePath = isNestingDisabled(field) ? fullStatePath : fieldKey + '.';
    let value = isNestingDisabled(field) ? props.value : props.value[fieldKey]

    return field.label(
        value,
        statePath,
        props.form,
    );
  }
  return field.label;
}

const isNestingDisabled = (field) => {
  return !(field.disable_nesting === false);
}


</script>

<template>

  <template v-for="(field, fieldKey) of schema" :key="fieldKey">
    <template v-if="form.isVisible(field, statePath+fieldKey)">

      <template v-if="field.type === 'html'">
        <div :class="`setting-html-wrapper ${field.wrapperClass ?? ''}`" v-html="field.value">

        </div>
      </template>

      <template v-else>
        <template v-if="field.component">

          <div :class="field.wrapperClass ?? ''">
            <LabelHint v-if="field.component && field.label" :title="getLabel(field, fieldKey)" :content="field.tooltip"
                       class="setting-label"
                       :placement="field.tooltip_placement">
              <span v-if="field.labelOptional">&#40;{{ field.labelOptional }}&#41;</span>
            </LabelHint>
            <resolver
                v-model="value[fieldKey]"
                :name="field.component"
                :form="form"
                :statePath="isNestingDisabled(field)? fullStatePath : fieldKey+'.'"
                :field="field"
                :field-key="fieldKey"
                :value="isNestingDisabled(field)? value: value[fieldKey]"
                :nesting="isNestingDisabled(field)"
            >
              <template #fieldNote>
                <field-note v-if="field.note && field.hide_note !== true" :note="field.note"/>
              </template>
              <template #validationError>
                <validation-error :form="form" :error-key="fullStatePath+fieldKey"/>
              </template>
            </resolver>
          </div>
        </template>


        <LayoutRenderer
            v-if="formLayouts.includes(field.type)"
            :form="form"
            :field="field"
            :value="value"
            :full-state-path="fullStatePath"
            :statePath="isNestingDisabled(field)? fullStatePath : fieldKey+'.'"
            :field-key="fieldKey"
        />

        <template v-if="
            typeof field.type !== 'undefined' &&
            !formLayouts.includes(field.type) &&
            !['custom','component','html','hidden'].includes(field.type)
          "
        >

          <div :class="`setting-fields-inner ${field.wrapperClass ?? ''}`">

            <LabelHint class="setting-label" :title="getLabel(field, fieldKey)" :content="field.tooltip"
                       :placement="field.tooltip_placement"
                       v-if=" field.type !== 'checkbox' && !field.component && field.label !== false">
              <span v-if="field.labelOptional">&#40;{{ field.labelOptional }}&#41;</span>
            </LabelHint>

            <InputRenderer
                :form="form"
                :field="field"
                :value="value"
                :full-state-path="fullStatePath"
                :state-path="statePath"
                :field-key="fieldKey"
            />

            <field-note v-if="field.note && !field.component" :note="field.note"/>

            <validation-error v-if="!field.component" :form="form" :error-key="fullStatePath+fieldKey"/>

          </div>
        </template>
      </template>
    </template>
  </template>
</template>
