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
      <h3 class="filter-popover-title">{{ $t('Columns') }}</h3>
      <fct-choice @change="()=>{
                table.handleColumnVisibilityChange()
              }" class="fct-checkbox-blocks" v-model="table.data.columns"
                  :options="table.getToggleableColumns()"/>
    </div>
    <template #reference>
                  <span>
                    <fct-tooltip
                        :content="$t('Toggle columns')"
                        placement="top"
                    >
                      <IconButton tag="button">
                        <DynamicIcon name="ColumnIcon"/>
                      </IconButton>
                    </fct-tooltip>
                  </span>
    </template>
  </fct-popover>
</template>

<style scoped>

</style>
