<script setup>
import Table from "@/utils/table-new/Table";
import {defineProps, computed, ref, onMounted, nextTick} from "vue";
import * as Fluid from "@/Bits/Components/FluidTab/FluidTab.js";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  table: Object
});

const selectedExtraTab = ref('');

const tabsData = computed(() => {
  const allTabs = props.table.getTabs();
  const tabEntries = Object.entries(allTabs);
  const selectedTab = props.table.getSelectedTab();

  // Find the selected tab entry
  const selectedTabEntry = tabEntries.find(([key]) => key === selectedTab);

  // Get first 4 tabs
  let firstFour = tabEntries.slice(0, 4);

  // Create visible tabs object
  const visibleTabs = Object.fromEntries(firstFour);

  // Create excluded tabs object (remaining tabs not in visible)
  const excludedTabs = Object.fromEntries(
      tabEntries.filter(([key]) => !visibleTabs.hasOwnProperty(key))
  );

  return {
    visibleTabs,
    excludedTabs
  };
});

const fluidTab = ref(null);

onMounted(() => {
  if (tabsData.value.excludedTabs.hasOwnProperty(props.table.getSelectedTab())) {
    selectedExtraTab.value = props.table.getSelectedTab();
  }
})

const hudai = ()=>{
  setTimeout(() => {
    const index = Object.keys(tabsData.value.visibleTabs).indexOf(props.table.getSelectedTab());
    let selectedIndex = index !== -1 ? index + 1 : -1; // or null, or 0, depending on your needs
    fluidTab.value.setActiveByIndex(selectedIndex);
  }, 50);
}
</script>

<template>
  <Fluid.Tab :class="table.isUsingAdvanceFilter() ? 'is-disabled' : ''" ref="fluidTab">
    <Fluid.Item
        v-for="(viewLabel, viewKey) in tabsData.visibleTabs"
        :key="viewKey"
        :label="viewLabel"
        :class="table.getSelectedTab() === viewKey ? 'active' : ''"
        @click="()=>{
                  if(!table.isUsingAdvanceFilter()){
                    table.handleTabChanged(viewKey)
                    if (!tabsData.excludedTabs.hasOwnProperty(props.table.getSelectedTab())) {
                      selectedExtraTab = '';
                    }
                  }
              }"
    />


    <Fluid.Item v-if="Object.keys(tabsData.excludedTabs).length > 0" class="fct-fluid-tab-select-wrap">
      <el-select
          :placeholder="translate('More views')"
          v-model="selectedExtraTab"
          @change="(viewKey)=>{
        if(!table.isUsingAdvanceFilter()){
            table.handleTabChanged(viewKey)
          }
      }"
          @visible-change="(visible) =>{
            if(!visible){
              hudai();
            }
          }"
          class="w-[120px]"
          :class="{'active':selectedExtraTab}"
          popper-class="fct-fluid-tab-select">
        <el-option
            v-for="(viewLabel, viewKey) in tabsData.excludedTabs"
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
            {{ viewLabel }}
          </template>
        </el-option>
      </el-select>
    </Fluid.Item>
  </Fluid.Tab>

</template>
