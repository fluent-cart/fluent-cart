<script setup>
import {onMounted, ref, getCurrentInstance} from "vue";
import Utils from "@/utils/Utils";
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
const searchQuery = ref('');

const options = ref(props.itemConfig.options ?? []);
const loading = ref(false)

const fetchData = async () => {

  if(searchQuery.value.toString().length === 0 && options.value.length > 0) {
    return;
  }

  loading.value = true;
  Rest.get("advance_filter/get-filter-options", {
    'search': searchQuery.value,
    'remote_data_key': props.itemConfig.remote_data_key,
    'include_ids': model.value,
    'limit': props.itemConfig.limit
  })
      .then((response) => {
        options.value = response.options;
      })
      .catch((errors) => {
        Notify.error(errors);
      })
      .finally(() => {
        loading.value = false
      });
}

const filter = (value) => {
  searchQuery.value = value;
  fetchData()
}


onMounted(() => {
  if (!(typeof options.value === 'object' && Object.values(options.value).length > 0)) {
    fetchData();
  }

});
</script>

<template>
  <div class="relative">
    <el-tree-select
        v-model="model"
        :data="options"
        multiple
        filterable
        :check-strictly="itemConfig.check_strictly || false"
        show-checkbox
        :reserve-keyword="false"
        :filter-method="Utils.debounce(filter, 500)"
        :render-after-expand="true"
        size="small"
        @change="()=>{
          table.applyAdvancedFilter();
        }"
    />
    <div class="absolute top-0 left-0 w-full h-full " v-if="loading">
      <div
          v-loading="true" class="h-full w-full">
      </div>
    </div>
  </div>
</template>

