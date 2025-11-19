<template>
  <div class="navigable-list-container fct-global-search-result-container">
    <div class="no-results fct-global-search-result-not-found" v-if="items.length === 0">
      <DynamicIcon name="Empty/GlobalSearch"  />
      {{ noResultsText }}
    </div>

    <div v-else>
      <ul ref="listContainer" class="navigable-list fct-global-search-result-list">
        <li
            v-for="(item, index) in items"
            :key="index"
            :ref="el => setListRef(index, el)"
            :class="{ selected: index === selectedIndex }"
            :tabindex="index"
            @click="handleItemClick(index)"
            @keydown.arrow-up.prevent="navigate('up')"
            @keydown.arrow-down.prevent="navigate('down')"
            @keydown.enter="performAction(index)"
        >
          <slot :item="item" :index="index" :is-selected="index === selectedIndex"></slot>
        </li>
      </ul>
    </div>


  </div>
</template>

<script setup>
import {ref, watch, nextTick, useTemplateRef} from 'vue';
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  items: {
    type: Array,
    required: true,
    default: () => [],
  },
  noResultsText: {
    type: String,
    default: translate('No results found'),
  },
  onAction: {
    type: Function,
    required: true,
  },
});

const selectedIndex = ref(-1);
const listContainer = useTemplateRef('listContainer');
const listRefs = ref({});

const setListRef = (index, el) => {
  if (el) {
    listRefs.value[index] = el;
  }
};

const navigate = (direction) => {
  const length = props.items.length;
  if (length === 0) return;

  const maxIndex = length - 1;

  if (direction === 'up') {
    selectedIndex.value = selectedIndex.value <= 0 ? maxIndex : selectedIndex.value - 1;
  } else if (direction === 'down') {
    selectedIndex.value = selectedIndex.value >= maxIndex ? 0 : selectedIndex.value + 1;
  }

  scrollToSelectedElement();
};

const scrollToSelectedElement = () => {
  const container = listContainer.value;
  const element = listRefs.value[selectedIndex.value];

  if (element && container) {
    element.scrollIntoView({
      behavior: 'smooth',
      block: 'nearest',
    });

    nextTick(() => {
      element.focus();
    });
  }
};

const handleItemClick = (index) => {
  selectedIndex.value = index;
  performAction(index);
};

const performAction = (index) => {
  if (index >= 0 && index < props.items.length) {
    props.onAction(props.items[index], index);
  }
};

const resetSelection = () => {
  selectedIndex.value = -1;
};

const focusList = (index) => {
  index = index ?? props.items.length - 1;
  if (index >= 0) {
    selectedIndex.value = index;
    nextTick(() => {
      if (listRefs.value[index]) {
        listRefs.value[index].focus();
      }
    });
  }
};

const performFirstAction = () => {
  if (props.items.length > 0) {
    selectedIndex.value = 0;
    setTimeout(() => {
      performAction(0);
    }, 200);
  } else {
    selectedIndex.value = -1;
  }
};

defineExpose({
  focusList,
  resetSelection,
  performFirstAction,
});

watch(
    () => props.items,
    (newItems) => {
      resetSelection();
      if (newItems.length > 0) {
        nextTick(() => {
          //scrollToSelectedElement();
        });
      }
    },
    {immediate: true}
);

watch(selectedIndex, () => {
  //scrollToSelectedElement();
});
</script>

<style scoped>
.navigable-list-container {
  width: 100%;
}

</style>
