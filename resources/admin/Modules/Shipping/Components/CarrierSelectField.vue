<template>
  <div>
    <span :id="labelId" class="fct-carrier-label">{{ field.label }}</span>
    <div ref="rootEl" class="fct-carrier-dd">
      <button
          type="button"
          class="fct-carrier-dd__btn"
          aria-haspopup="listbox"
          :aria-expanded="String(open)"
          :aria-labelledby="labelId + ' ' + valueId"
          @click="toggle"
          @keydown.down.prevent="openList"
          @keydown.up.prevent="openList"
      >
        <span :id="valueId" class="fct-carrier-dd__val">
          <span class="fct-carrier-mono" :class="{ 'is-all': current && current.value === '' }" aria-hidden="true">{{ current ? mono(current) : '' }}</span>
          {{ current ? current.label : '' }}
        </span>
        <svg class="fct-carrier-dd__chev" aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
      </button>

      <div v-if="open" class="fct-carrier-dd__pop">
        <div class="fct-carrier-dd__search">
          <fct-input
              ref="searchEl"
              v-model="query"
              :placeholder="field.search_placeholder || translate('Search')"
              :aria-label="field.search_placeholder || translate('Search')"
              :aria-controls="listId"
              :aria-activedescendant="activeId"
              autocomplete="off"
              @keydown="onSearchKey"
          />
        </div>
        <ul :id="listId" class="fct-carrier-dd__list" role="listbox" :aria-labelledby="labelId">
          <template v-for="row in rows" :key="row.key">
            <li v-if="row.kind === 'group'" class="fct-carrier-dd__group" role="presentation">{{ row.label }}</li>
            <li v-else-if="row.kind === 'empty'" class="fct-carrier-dd__empty" role="presentation">{{ row.label }}</li>
            <li
                v-else
                :id="optionId(row.index)"
                class="fct-carrier-dd__opt"
                :class="{ 'is-active': row.index === active }"
                role="option"
                :aria-selected="String(isSelected(row.option))"
                @mousedown.prevent
                @mousemove="active = row.index"
                @click="pick(row.option)"
            >
              <span class="fct-carrier-mono" :class="{ 'is-all': row.option.value === '' }" aria-hidden="true">{{ mono(row.option) }}</span>
              <span class="fct-carrier-dd__name">{{ row.option.label }}</span>
              <small v-if="row.option.sub">{{ row.option.sub }}</small>
              <svg class="fct-carrier-dd__check" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12l5 5 9-10"/></svg>
            </li>
          </template>
        </ul>
        <div v-if="field.footer || field.refresh" class="fct-carrier-dd__foot">
          <span><span v-if="refreshing" class="fct-carrier-spin" aria-hidden="true"></span>{{ refreshing ? (field.refresh.working || translate('Loading')) : field.footer }}</span>
          <button v-if="field.refresh" type="button" class="fct-carrier-link" :disabled="!!running || field.refresh.disabled" @click="$emit('action', field.refresh)">
            {{ field.refresh.label }}
          </button>
        </div>
      </div>
    </div>
    <p v-if="field.hint" class="fct-carrier-hint">{{ field.hint }}</p>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import translate from '@/utils/translator/Translator';

const props = defineProps({
  field: {
    type: Object,
    required: true
  },
  modelValue: {
    type: [String, Number],
    default: undefined
  },
  running: {
    type: String,
    default: null
  }
});

const emit = defineEmits(['update:modelValue', 'action']);

const open = ref(false);
const query = ref('');
const active = ref(0);
const rootEl = ref(null);
const searchEl = ref(null);

const labelId = computed(() => 'fct-carrier-dd-label-' + props.field.key);
const valueId = computed(() => 'fct-carrier-dd-value-' + props.field.key);
const listId = computed(() => 'fct-carrier-dd-list-' + props.field.key);
const optionId = (index) => 'fct-carrier-dd-opt-' + props.field.key + '-' + index;
const activeId = computed(() => (pickable.value.length ? optionId(active.value) : null));

const refreshing = computed(() => !!props.field.refresh && props.running === props.field.refresh.action);

const allOptions = computed(() => [
  ...(props.field.options || []),
  ...(props.field.groups || []).flatMap((group) => group.options || []),
]);

const current = computed(() => allOptions.value.find((option) => String(option.value) === String(props.modelValue ?? '')) || null);

const matches = (option) => !query.value || String(option.label).toLowerCase().includes(query.value.trim().toLowerCase());

// Rows in display order; options carry their index among the pickable ones for keyboard moves.
const rows = computed(() => {
  const list = [];
  let index = 0;
  const add = (option, key) => list.push({kind: 'option', key, option, index: index++});

  (props.field.options || []).filter(matches).forEach((option) => add(option, 'o-' + option.value));
  (props.field.groups || []).forEach((group, groupIndex) => {
    const options = (group.options || []).filter(matches);
    if (!options.length && query.value) {
      return;
    }
    list.push({kind: 'group', key: 'g-' + groupIndex, label: group.label});
    if (!options.length && group.empty) {
      list.push({kind: 'empty', key: 'e-' + groupIndex, label: group.empty});
    }
    options.forEach((option) => add(option, 'g-' + groupIndex + '-' + option.value));
  });

  if (query.value && !index) {
    /* translators: %1$s: search text */
    list.push({kind: 'empty', key: 'none', label: translate('No carrier matches “%1$s”.', query.value.trim())});
  }

  return list;
});

const pickable = computed(() => rows.value.filter((row) => row.kind === 'option'));

const isSelected = (option) => String(option.value) === String(props.modelValue ?? '');

const mono = (option) => {
  if (option.badge) {
    return option.badge;
  }
  if (option.value === '') {
    return '$';
  }
  const words = String(option.label).replace(/[^A-Za-z ]/g, '').split(' ').filter(Boolean);
  return (words.length > 1 ? words[0][0] + words[1][0] : String(option.label).slice(0, 2)).toUpperCase();
};

const onDocumentDown = (event) => {
  if (rootEl.value && !rootEl.value.contains(event.target)) {
    close();
  }
};

const openList = () => {
  open.value = true;
  query.value = '';
  active.value = Math.max(0, pickable.value.findIndex((row) => isSelected(row.option)));
  document.addEventListener('mousedown', onDocumentDown);
  nextTick(() => searchEl.value && searchEl.value.focus());
};

const close = () => {
  open.value = false;
  document.removeEventListener('mousedown', onDocumentDown);
};

const toggle = () => (open.value ? close() : openList());

const pick = (option) => {
  emit('update:modelValue', option.value);
  close();
};

const onSearchKey = (event) => {
  const last = pickable.value.length - 1;
  if (event.key === 'ArrowDown') {
    event.preventDefault();
    active.value = Math.min(last, active.value + 1);
  } else if (event.key === 'ArrowUp') {
    event.preventDefault();
    active.value = Math.max(0, active.value - 1);
  } else if (event.key === 'Enter') {
    event.preventDefault();
    const row = pickable.value[active.value];
    if (row) {
      pick(row.option);
    }
  } else if (event.key === 'Escape' || event.key === 'Tab') {
    close();
  }
};

watch(query, () => { active.value = 0; });

onBeforeUnmount(close);
</script>
