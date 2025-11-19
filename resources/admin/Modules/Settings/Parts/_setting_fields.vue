<template>


  <div class="setting-fields" :class="[
      {'mb-4': !disableGutter}
  ]">
    <div class="setting-fields-inner" :class="{'inline-field' : isInlineComponent}">
      <label class="setting-label">{{ field.label }}</label>

      <el-switch active-value="yes" inactive-value="no" v-model="settings[index]" v-if="field.type === 'switch'"/>

      <el-input :type="field.type" :placeholder="field.placeholder" v-model="settings[index]"
                v-if="field.type === 'input' || field.type === 'email'"/>


      <el-select
          v-if="field.type === 'search_select'"
          v-model="settings[index]"
          filterable
          remote
          placeholder="Please enter a keyword"
          :remote-method="query => getSelectOptions({query, index})"
          :loading="searching"
      >
        <el-option v-for="item in field.options" :key="item.value" :label="item.label" :value="item.value"/>
      </el-select>

      <el-select v-model="settings[index]" filterable placeholder="Select" v-if="field.type === 'select'">
        <el-option v-for="(opt, index) in field.options" :key="index" :label="opt.label" :value="opt.value"/>
      </el-select>

      <el-checkbox-group v-model="settings[index]" v-if="field.type === 'checkbox_group'">
        <el-checkbox v-for="(opt, ind) in field.options" :label="ind" :key="ind">{{ opt }}</el-checkbox>
      </el-checkbox-group>

      <div v-if="field.type === 'media'">
        <MediaInput :multiple="!!field.multiple" v-model="settings[index]"/>
      </div>

    </div>

    <div v-if="field.note" class="form-note" v-html="field.note"></div>
  </div>
</template>

<script setup>
import {computed, getCurrentInstance, onMounted, ref, watch} from "vue";
import MediaInput from "@/Bits/Components/Inputs/MediaInput.vue";
import FormRenderer from "@/Bits/Components/Form/Renderer/FormRenderer.vue";

const selfRef = getCurrentInstance().ctx;


defineOptions({
  name: "_fields"
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

const isInlineComponent = computed(() => {
  return ['switch', 'checkbox_group'].includes(props.field.type);
})

</script>
