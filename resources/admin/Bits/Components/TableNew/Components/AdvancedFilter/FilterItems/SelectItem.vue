<script setup>
import {ref, computed, onMounted} from 'vue';
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";

const props = defineProps({
  table: {
    type: Object,
    required: true
  },
  itemConfig: {
    type: Object,
    required: true
  }
})

const model = defineModel();

// Local state for remote options
const remoteOptions = ref({});
const loading = ref(false);

// Computed options that uses either static or remote options
const currentOptions = computed(() => {
  if (props.itemConfig.remote) {
    return remoteOptions.value;
  }
  return props.itemConfig.options || {};
});

const handleRemoteSearch = async (query) => {
  if (props.itemConfig.remote) {
    loading.value = true;

    Rest.get("advance_filter/get-filter-options", {
      'search': query,
      'remote_data_key': props.itemConfig.remote_data_key,
      'include_ids': model.value,
      'limit': props.itemConfig.limit
    })
        .then((response) => {
          remoteOptions.value = response.options;
        })
        .catch((errors) => {
          Notify.error(errors);
          remoteOptions.value = {};
        })
        .finally(() => {
          loading.value = false
        });
  }
}

const handleChange = () => {
  props.table.applyAdvancedFilter();
}

onMounted(() => {
  handleRemoteSearch('');
})
</script>

<template>
  <template v-if="itemConfig.options || itemConfig.remote">
    <el-select
        @change="handleChange"
        :remote-method="handleRemoteSearch"
        size="small"
        v-model="model"
        :multiple="itemConfig.is_multiple"
        :placeholder="translate('Select Option')"
        :remote="true"
        :filterable="itemConfig.remote"
        :remote-show-suffix="itemConfig.remote"
        :loading="loading"
    >
      <el-option
          v-for="(optionLabel,option) in currentOptions"
          :key="option"
          :value="option"
          :label="optionLabel">
      </el-option>
    </el-select>
  </template>
</template>

<style scoped>

</style>