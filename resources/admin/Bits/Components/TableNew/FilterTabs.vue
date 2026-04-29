<script setup>
import {computed, ref, onMounted, nextTick, watch} from "vue";
import * as Fluid from "@/Bits/Components/FluidTab/FluidTab.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import {ElMessageBox} from "element-plus";

const props = defineProps({
  table: Object
});

const selectedExtraTab = ref('');

const tabsData = computed(() => {
  const allTabs = props.table.getTabs();
  const staticEntries = Object.entries(allTabs);

  // Append saved views as tab entries
  const savedViews = props.table.getSavedViews();
  const customEntries = savedViews.map(view => [
    view.slug,
    {title: view.name, isCustomView: true, viewId: view.slug, description: view.description || '', is_public: view.is_public, owner_id: view.owner_id}
  ]);

  const tabEntries = [...staticEntries, ...customEntries];

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
  const activeSavedViewId = props.table.getActiveSavedViewId();
  if (activeSavedViewId && tabsData.value.excludedTabs.hasOwnProperty(activeSavedViewId)) {
    selectedExtraTab.value = activeSavedViewId;
  } else if (tabsData.value.excludedTabs.hasOwnProperty(props.table.getSelectedTab())) {
    selectedExtraTab.value = props.table.getSelectedTab();
  }
})

// Reposition tab bar when active saved view changes
watch(() => props.table.data.activeSavedViewId, (newId) => {
  nextTick(() => {
    if (fluidTab.value) {
      if (newId && tabsData.value.excludedTabs.hasOwnProperty(newId)) {
        selectedExtraTab.value = newId;
        fluidTab.value.setActiveByIndex(-1);
      } else {
        fluidTab.value.setActiveByActiveClass();
      }
    }
  });
})

const isTabActive = (viewKey, viewLabel) => {
  const activeSavedViewId = props.table.getActiveSavedViewId();
  if (viewLabel && viewLabel.isCustomView) {
    return activeSavedViewId === viewLabel.viewId;
  }
  return !activeSavedViewId && props.table.getSelectedTab() === viewKey;
};

const handleTabClick = (viewKey, viewLabel) => {
  if (props.table.isUsingAdvanceFilter() && !(viewLabel && viewLabel.isCustomView)) return;

  if (viewLabel && viewLabel.isCustomView) {
    props.table.applySavedView(viewLabel.viewId);
    if (!tabsData.value.excludedTabs.hasOwnProperty(viewLabel.viewId)) {
      selectedExtraTab.value = '';
    }
  } else {
    props.table.handleTabChanged(viewKey);
    if (!tabsData.value.excludedTabs.hasOwnProperty(props.table.getSelectedTab())) {
      selectedExtraTab.value = '';
    }
  }
};

const handleExtraTabChange = (viewKey) => {
  const tabLabel = tabsData.value.excludedTabs[viewKey];
  if (tabLabel && tabLabel.isCustomView) {
    props.table.applySavedView(tabLabel.viewId);
  } else if (!props.table.isUsingAdvanceFilter()) {
    props.table.handleTabChanged(viewKey);
  }
};

const repositionTabIndicator = () => {
  setTimeout(() => {
    const activeSavedViewId = props.table.getActiveSavedViewId();
    let selectedIndex = -1;
    if (!activeSavedViewId) {
      const index = Object.keys(tabsData.value.visibleTabs).indexOf(props.table.getSelectedTab());
      selectedIndex = index !== -1 ? index + 1 : -1;
    }
    fluidTab.value.setActiveByIndex(selectedIndex);
  }, 50);
};

const renameSavedView = async (viewId, currentName) => {
  try {
    const {value} = await ElMessageBox.prompt(
        translate('Enter a new name'),
        translate('Rename View'),
        {
          confirmButtonText: translate('Save'),
          cancelButtonText: translate('Cancel'),
          inputValue: currentName,
          inputValidator: (val) => !!val?.trim() || translate('Name is required'),
        }
    );
    if (value && value.trim()) {
      props.table.renameSavedView(viewId, value.trim());
    }
  } catch {
    // cancelled
  }
};

const deleteSavedView = async (viewId) => {
  try {
    await ElMessageBox.confirm(
        translate('Are you sure you want to delete this view?'),
        translate('Delete View'),
        {
          confirmButtonText: translate('Delete'),
          cancelButtonText: translate('Cancel'),
          type: 'warning',
        }
    );
    props.table.deleteSavedView(viewId);
    if (selectedExtraTab.value === viewId) {
      selectedExtraTab.value = '';
    }
  } catch {
    // cancelled
  }
};
</script>

<template>
  <Fluid.Tab :class="table.isUsingAdvanceFilter() && !table.getActiveSavedViewId() ? 'is-disabled' : ''"
              ref="fluidTab">
    <Fluid.Item
        v-for="(viewLabel, viewKey) in tabsData.visibleTabs"
        :key="viewKey"
        :class="isTabActive(viewKey, viewLabel) ? 'active' : ''"
        @click="() => handleTabClick(viewKey, viewLabel)"
    >
      <template v-if="viewLabel && viewLabel.isCustomView">
        <el-tooltip
            :content="viewLabel.description || ''"
            :disabled="!viewLabel.description"
            placement="top"
            effect="light"
            popper-class="fct-tooltip"
        >
          <span class="inline-flex items-center gap-1">
            <span>{{ viewLabel.title }}</span>
            <svg v-if="viewLabel.is_public" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="opacity-40"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            <el-dropdown
                v-if="table.isViewOwner(viewLabel)"
                trigger="click"
                @command="(cmd) => {
                  if (cmd === 'rename') renameSavedView(viewLabel.viewId, viewLabel.title);
                  else if (cmd === 'update') table.updateSavedView(viewLabel.viewId);
                  else if (cmd === 'delete') deleteSavedView(viewLabel.viewId);
                }"
                @click.stop
            >
              <span class="inline-flex items-center text-system-light cursor-pointer opacity-50 hover:opacity-100 dark:text-gray-400 [&_svg]:w-3 [&_svg]:h-3" @click.stop>
                <DynamicIcon name="ChevronDown"/>
              </span>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="rename">{{ $t('Rename') }}</el-dropdown-item>
                  <el-dropdown-item command="update">{{ $t('Update filters') }}</el-dropdown-item>
                  <el-dropdown-item command="delete" divided>{{ $t('Delete') }}</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </span>
        </el-tooltip>
      </template>
      <template v-else>
        {{ typeof viewLabel === 'object' ? viewLabel.title : viewLabel }}
      </template>
    </Fluid.Item>


    <Fluid.Item v-if="Object.keys(tabsData.excludedTabs).length > 0" class="fct-fluid-tab-select-wrap">
      <el-select
          :placeholder="translate('More views')"
          v-model="selectedExtraTab"
          @change="handleExtraTabChange"
          @visible-change="(visible) =>{
            if(!visible){
              repositionTabIndicator();
            }
          }"
          class="w-[120px]"
          :class="{'active':selectedExtraTab}"
          popper-class="fct-fluid-tab-select">
        <template v-for="(viewLabel, viewKey) in tabsData.excludedTabs" :key="viewKey">
          <el-option
              :label="(typeof viewLabel === 'object' ? viewLabel.title : viewLabel)"
              :value="viewKey"
          >
            <template v-if="viewLabel && viewLabel.isCustomView">
              <div style="display:flex;align-items:center;justify-content:space-between;width:100%;">
                <div>
                  <div class="select-label">{{ viewLabel.title }}</div>
                  <div v-if="viewLabel.description" class="select-desc">{{ viewLabel.description }}</div>
                </div>
                <span
                    v-if="table.isViewOwner(viewLabel)"
                    style="display:inline-flex;align-items:center;cursor:pointer;padding:2px;"
                    @click.stop="deleteSavedView(viewLabel.viewId)"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                       style="opacity:0.6;">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                  </svg>
                </span>
              </div>
            </template>
            <template v-else-if="typeof viewLabel === 'object'">
              <div>
                <div class="select-label">{{ viewLabel.title }}</div>
                <div class="select-desc">{{ viewLabel.description }}</div>
              </div>
            </template>
            <template v-else>
              {{ viewLabel }}
            </template>
          </el-option>
        </template>
      </el-select>
    </Fluid.Item>
  </Fluid.Tab>

</template>

