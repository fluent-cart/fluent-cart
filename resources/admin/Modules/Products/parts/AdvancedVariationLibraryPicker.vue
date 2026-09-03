<script setup>
import {ref, computed, watch} from "vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  modelValue: {type: Boolean, default: false},
  groups: {type: Array, default: () => []},
  usedGroupIds: {type: Array, default: () => []},
});

const emit = defineEmits(['update:modelValue', 'select', 'create-new']);

const search = ref('');

// Reset search every time the dialog re-opens — stale filter from a prior open
// would silently hide groups the merchant expects to see.
watch(() => props.modelValue, (open) => {
  if (open) {
    search.value = '';
  }
});

// Available groups = all groups minus the ones already added on this product. Already-
// used groups never appear in the picker; if the merchant wants to add Size again, it's
// because they removed it first.
const availableGroups = computed(() => {
  return props.groups.filter(g => !props.usedGroupIds.includes(g.id));
});

// Search the groups list by title, case-insensitive. Matches anywhere in the title
// (not just prefix) so "size" matches "Cloth Size" too.
const filteredGroups = computed(() => {
  if (!search.value.trim()) return availableGroups.value;
  const needle = search.value.trim().toLowerCase();
  return availableGroups.value.filter(g => (g.title || '').toLowerCase().includes(needle));
});

const groupType = (group) => {
  return (group && group.settings && group.settings.type) ? group.settings.type : 'options';
};

const handleSelect = (group) => {
  emit('select', group);
  emit('update:modelValue', false);
};

const handleCreateNew = () => {
  emit('create-new');
  emit('update:modelValue', false);
};
</script>

<template>
  <el-dialog
      :model-value="modelValue"
      @update:model-value="emit('update:modelValue', $event)"
      :title="translate('Add option from library')"
      :append-to-body="true"
      width="560px"
      class="fct-library-picker-dialog"
  >
    <div class="fct-library-picker">
      <!-- Groups list — the full library, system + custom, with term counts and a
           click-anywhere row that selects the group. Search is client-side because
           the library endpoint already returned every group. -->
      <div class="fct-library-picker__section">
        <div class="fct-library-picker__section-head">
          <span class="fct-library-picker__section-label">{{ translate('Attribute Groups') }}</span>
        </div>

        <div class="fct-library-picker__search">
          <el-input
              v-model="search"
              :placeholder="translate('Search attribute groups...')"
              clearable
          >
            <template #prefix>
              <DynamicIcon name="Search"/>
            </template>
          </el-input>
        </div>

        <div v-if="filteredGroups.length === 0" class="fct-library-picker__empty">
          {{ search ? translate('No groups match "%1$s"', search) : translate('No attribute groups available.') }}
        </div>

        <ul v-else class="fct-library-picker__list">
          <li
              v-for="g in filteredGroups"
              :key="'list-' + g.id"
              class="fct-library-picker__row"
              role="button"
              tabindex="0"
              @click="handleSelect(g)"
              @keydown.enter.space.prevent="handleSelect(g)"
          >
            <span
                v-if="groupType(g) === 'color'"
                class="fct-library-picker__row-icon fct-library-picker__row-icon--color"
            ></span>
            <span v-else class="fct-library-picker__row-icon">
              {{ (g.title || '?').charAt(0).toUpperCase() }}
            </span>
            <span class="fct-library-picker__row-title">{{ g.title }}</span>
            <span class="fct-library-picker__row-meta">
              {{
                /* translators: %1$s: number of terms in the attribute group */
                translate('%1$s terms', (g.terms_count != null ? g.terms_count : (g.terms ? g.terms.length : 0)))
              }}
            </span>
          </li>
        </ul>

        <!-- Inline SVG so the icon doesn't depend on the DynamicIcon registry, which
             doesn't ship a "Plus" entry. Matches the glyph used by the "+ Add option"
             button in AdvancedVariationConfig so the two affordances feel consistent. -->
        <button type="button" class="fct-library-picker__create-new" @click="handleCreateNew">
          <svg fill="currentColor" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true"><path d="M4.25 8a.75.75 0 0 1 .75-.75h2.25v-2.25a.75.75 0 0 1 1.5 0v2.25h2.25a.75.75 0 0 1 0 1.5h-2.25v2.25a.75.75 0 0 1-1.5 0v-2.25h-2.25a.75.75 0 0 1-.75-.75"/><path fill-rule="evenodd" d="M8 15a7 7 0 1 0 0-14 7 7 0 0 0 0 14m0-1.5a5.5 5.5 0 1 0 0-11 5.5 5.5 0 1 0 0 11"/></svg>
          <span>{{ translate('Create new option') }}</span>
        </button>
      </div>
    </div>
  </el-dialog>
</template>
