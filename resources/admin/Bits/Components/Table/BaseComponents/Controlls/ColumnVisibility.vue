<script setup>
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  table: {
    type: Object,
    required: true
  }
})
</script>

<template>
  <el-popover trigger="click" placement="bottom-start" width="240" popper-class="filter-popover">
    <div class="filter-popover-item">
      <h3 class="filter-popover-title">{{ $t('Columns') }}</h3>
      <template v-for="(column, columnKey) in table.columns" :key="columnKey" >
        <el-checkbox v-if="column.toggleable !== false" v-model="column.visible" :label="column.title" @change="(visibility)=>{
          table.onColumnVisibilityChange(columnKey,visibility)
        }"/>
      </template>
    </div>
    <template #reference>
            <span>
              <el-tooltip
                  effect="dark"
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
