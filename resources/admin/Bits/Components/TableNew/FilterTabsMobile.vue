<template>
  <div class="fct-filter-tabs-mobile">
    <el-select
        v-model="selectedTab"
        @change="(viewKey) => {
          onChange(viewKey)
        }"
        popper-class="fct-fluid-tab-select"
        size="small"
    >
      <el-option
          v-for="(viewLabel, viewKey) in table.getTabs()"
          :key="viewKey"
          :label="viewLabel.title || viewLabel"
          :value="viewKey"
      >
        <template v-if="typeof viewLabel == 'object'">
          <div>
            <div class="select-label">
              {{ viewLabel.title }}
            </div>
            <div class="select-desc">{{ viewLabel.description }}</div>
          </div>
        </template>
        <template v-else>
          {{viewLabel}}
        </template>
      </el-option>
    </el-select>
  </div>
</template>

<script setup>
import {defineProps, ref} from "vue";

const props = defineProps({
  table: Object
});

const selectedTab = ref(props.table.getSelectedTab());

const onChange = (viewKey) => {
  if(!props.table.isUsingAdvanceFilter()){
    props.table.handleTabChanged(viewKey);
  }
}
</script>

