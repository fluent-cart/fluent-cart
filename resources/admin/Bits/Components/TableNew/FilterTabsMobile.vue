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

      <!-- Saved Views -->
      <el-option
          v-for="view in table.getSavedViews()"
          :key="view.slug"
          :label="view.name"
          :value="view.slug"
      >
        <div class="flex items-center justify-between w-full">
          <span class="text-sm text-system-dark dark:text-gray-50">{{ view.name }}</span>
          <span
              v-if="table.isProActive() && table.isViewOwner(view)"
              style="display:inline-flex;align-items:center;cursor:pointer;padding:2px;"
              @click.stop="confirmDeleteView(view.slug)"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 style="opacity:0.6;">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
            </svg>
          </span>
        </div>
      </el-option>
    </el-select>
  </div>
</template>

<script setup>
import {ref, computed} from "vue";
import {ElMessageBox} from "element-plus";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  table: Object
});

const confirmDeleteView = async (slug) => {
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
    props.table.deleteSavedView(slug);
  } catch {
    // cancelled
  }
};

const selectedTab = computed({
  get() {
    const activeSavedViewId = props.table.getActiveSavedViewId();
    if (activeSavedViewId) return activeSavedViewId;
    return props.table.getSelectedTab();
  },
  set() {
    // handled by onChange
  }
});

const onChange = (viewKey) => {
  const isSavedView = props.table.getSavedViews().some(v => v.slug === viewKey);
  if (isSavedView) {
    props.table.applySavedView(viewKey);
  } else if (!props.table.isUsingAdvanceFilter()) {
    props.table.handleTabChanged(viewKey);
  }
};
</script>

