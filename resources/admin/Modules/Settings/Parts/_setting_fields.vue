<template>


  <div class="setting-fields" :class="[
      {'mb-4': !disableGutter}
  ]">
    <div class="setting-fields-inner" :class="{'inline-field' : isInlineComponent}">
      <label class="setting-label">{{ field.label }}</label>

      <fct-switch active-value="yes" inactive-value="no" v-model="settings[index]" v-if="field.type === 'switch'"/>

      <fct-input :type="field.type === 'email' ? 'email' : 'text'" :placeholder="field.placeholder" v-model="settings[index]"
                v-if="field.type === 'input' || field.type === 'email'"/>


      <fct-select
          v-if="field.type === 'search_select'"
          v-model="settings[index]"
          filterable
          remote
          :placeholder="translate('Please enter a keyword')"
          :remote-method="query => getSelectOptions({query, index})"
          :loading="searching"
          :options="Object.values(field.options || {})"
      />

      <fct-select v-model="settings[index]" filterable placeholder="Select" v-if="field.type === 'select'" :options="Object.values(field.options || {})"/>

      <fct-choice v-model="settings[index]" v-if="field.type === 'checkbox_group'" :options="toOptions(field.options)"/>

      <div v-if="field.type === 'media'">
        <MediaInput :multiple="!!field.multiple" v-model="settings[index]"/>
      </div>

    </div>

    <div v-if="field.note" class="form-note" v-html="field.note"></div>
  </div>
</template>

<script setup>
import {computed, getCurrentInstance, onMounted, ref, watch} from "vue";
import translate from "@/utils/translator/Translator";
import MediaInput from "@/Bits/Components/Inputs/MediaInput.vue";
import FormRenderer from "@/Bits/Components/Form/Renderer/FormRenderer.vue";

const selfRef = getCurrentInstance().ctx;


defineOptions({
  name: "Fields"
})

const props = defineProps({
  field: {
    type: Object
  },
  settings: {
    type: Object
  },
  disableGutter: {
    default: true,
    type: Boolean
  },
  index: Number | String
})

watch(() => props.settings, (newValue) => {
  searching.value = !!searching.value
}, {
  deep: true
})
const searching = ref(false)

const getSelectOptions = (data) => {
  searching.value = true;
  selfRef.$get('settings/search_options', {
    search_by: data.query,
    search_for: data.index
  })
      .then(response => {
        props.field.value[data.index].options = response.data.options;
        searching.value = false;
      })
}

// Same values v-for gave: array index or object key.
const toOptions = (options) => Object.entries(options || {}).map(([value, label]) => ({value: Array.isArray(options) ? Number(value) : value, label}));

const isInlineComponent = computed(() => {
  return ['switch', 'checkbox_group'].includes(props.field.type);
})

</script>
