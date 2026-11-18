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
  <fct-popover trigger="click" placement="bottom-start" width="240" popper-class="filter-popover">
    <div class="filter-popover-item">
      <h3 class="filter-popover-title">{{ $t('Columns') }}</h3>
      <template v-for="(column, columnKey) in table.columns" :key="columnKey" >
        <fct-choice v-if="column.toggleable !== false" v-model="column.visible" @change="(visibility)=>{
          table.onColumnVisibilityChange(columnKey,visibility)
        }">{{ column.title }}</fct-choice>
      </template>
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
