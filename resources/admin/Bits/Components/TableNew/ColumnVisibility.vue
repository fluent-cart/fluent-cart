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
      <h3 class="filter-popover-title">{{ $t('Columns') }}</h3>
      <el-checkbox-group @change="()=>{
                table.handleColumnVisibilityChange()
              }" class="fct-checkbox-blocks" v-model="table.data.columns">
        <el-checkbox v-for="column in table.getToggleableColumns()"
                     :key="column.value"
                     :value="column.value"
                     :label="column.label"/>
      </el-checkbox-group>
    </div>
    <template #reference>
                  <span>
                    <el-tooltip
                        effect="light"
                        :content="$t('Toggle columns')"
                        placement="top"
                        popper-class="fct-tooltip"
                    >
                      <IconButton tag="button">
                        <DynamicIcon name="ColumnIcon"/>
                      </IconButton>
                    </el-tooltip>
                  </span>
    </template>
  </el-popover>
</template>

<style scoped>

</style>
