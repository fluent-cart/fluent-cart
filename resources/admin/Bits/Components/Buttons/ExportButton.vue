<script setup>
const props = defineProps({
  exportUrl: String,
  search: Object,
  format: String,
  filters: Object,
  // order_by: String,
  // order_type: String,
  // paginate: Object
  table: Object,
})

const exportData = () => {
  if (window.ajaxurl === undefined) {
    console.log('File can not be downloaded, ajaxUrl is not defined');
    return;
  }
  location.href = window.ajaxurl + '?' + jQuery.param({
    action: 'fluent_cart_admin_ajax',
    route: props.exportUrl,
    search: props.search,
    format: props.format ?? 'csv',
    filters: props.filters,
    order_by: props.table?.sort?.sortBy,
    order_type: props.table?.sort?.sortType,
    paginate: props.table?.paginate
  });
}
</script>

<template>
  <el-button @click="exportData()" plain>
    {{ $t('Export')}}
  </el-button>
</template>