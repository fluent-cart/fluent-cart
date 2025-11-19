<script setup >
import Table from "@/utils/table-new/Table";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {defineProps} from "vue";


const props = defineProps({
  table: Object
});
</script>

<template>
  <el-popover
      trigger="click"
      placement="bottom-start"
      width="240"
      popper-class="filter-popover"
  >
    <div class="filter-popover-item">
      <h3 class="filter-popover-title">{{ $t('Sort By') }}</h3>
      <el-radio-group @change="()=>{
                table.handleSortChange()
              }" class="fct-radios-blocks"
                      v-model="table.data.sorting.sortBy">
        <el-radio v-for="column in table.getSortableColumns()" :key="column.value" :value="column.value"
                  :label="column.label">
        </el-radio>
      </el-radio-group>
      <hr>
      <el-radio-group @change="()=>{
                table.handleSortChange()
              }" size="small" v-model="table.data.sorting.sortType">
        <el-radio-button value="ASC">{{ $t('Ascending') }}</el-radio-button>
        <el-radio-button value="DESC">{{ $t('Descending') }}</el-radio-button>
      </el-radio-group>
    </div>
    <template #reference>
                  <span>
                    <el-tooltip
                        effect="light"
                        :content="$t('Sort')"
                        placement="top"
                        popper-class="fct-tooltip"
                    >
                      <IconButton tag="button">
                        <DynamicIcon name="ArrowUpDown"/>
                      </IconButton>
                    </el-tooltip>
                  </span>
    </template>
  </el-popover>
</template>

<style scoped>

</style>
