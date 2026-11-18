<template>
  <div class="fct-filter-tabs-mobile">
    <fct-select
        v-model="selectedTab"
        @change="(viewKey) => {
          onChange(viewKey)
        }"
        popper-class="fct-fluid-tab-select"
        size="small"
        :options="[
          ...Object.entries(table.getTabs() || {}).map(([viewKey, viewLabel]) => ({label: viewLabel.title || viewLabel, value: viewKey, viewLabel})),
          ...table.getSavedViews().map((view) => ({label: view.name, value: view.slug, view}))
        ]"
    >
      <template #option="{option}">
        <div v-if="option.view" class="flex items-center justify-between w-full">
          <span class="text-sm text-system-dark dark:text-gray-50">{{ option.view.name }}</span>
          <span
              v-if="table.isProActive() && table.isViewOwner(option.view)"
              style="display:inline-flex;align-items:center;cursor:pointer;padding:2px;"
              @click.stop="confirmDeleteView(option.view.slug)"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 style="opacity:0.6;">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
            </svg>
          </span>
        </div>
        <div v-else-if="typeof option.viewLabel == 'object'">
          <div class="select-label">
            {{ option.viewLabel.title }}
          </div>
          <div class="select-desc">{{ option.viewLabel.description }}</div>
        </div>
        <template v-else>
          {{ option.viewLabel }}
        </template>
      </template>
    </fct-select>
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
