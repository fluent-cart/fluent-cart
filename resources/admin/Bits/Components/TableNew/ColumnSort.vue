<script setup >
import Table from "@/utils/table-new/Table";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";


const props = defineProps({
  table: Object
});
</script>

<template>
  <fct-popover
      trigger="click"
      placement="bottom-start"
      width="240"
      popper-class="filter-popover"
  >
    <div class="filter-popover-item">
      <h3 class="filter-popover-title">{{ $t('Sort By') }}</h3>
      <fct-choice type="radio" @change="()=>{
                table.handleSortChange()
              }" class="fct-radios-blocks"
                  v-model="table.data.sorting.sortBy"
                  :options="table.getSortOptions()"/>
      <hr>
      <fct-choice type="radio" button @change="()=>{
                table.handleSortChange()
              }" size="small" v-model="table.data.sorting.sortType"
                  :options="[{value: 'ASC', label: $t('Ascending')}, {value: 'DESC', label: $t('Descending')}]"/>
    </div>
    <template #reference>
                  <span>
                    <fct-tooltip
                        :content="$t('Sort')"
                        placement="top"
                    >
                      <IconButton tag="button">
                        <DynamicIcon name="ArrowUpDown"/>
                      </IconButton>
                    </fct-tooltip>
                  </span>
    </template>
  </fct-popover>
</template>

<style scoped>

</style>
