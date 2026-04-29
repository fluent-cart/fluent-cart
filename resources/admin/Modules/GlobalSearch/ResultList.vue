<template>
  <NavigableList
      v-if="query || recentActions.length < 1"
      :items="searchResults"
      :on-action="performAction"
      :no-results-text="translate('No matches found')"
      ref="navigableList"
  >
    <template #default="{ item, index, isSelected }">
      <span>
          {{ item.item.title }}
          <br>
          <span v-if="item.item?.data?.show_description === true">{{ item.item.data.description }}</span>
        </span>
      <div class="action-icon">
        <DynamicIcon name="Enter"/>
      </div>
    </template>
  </NavigableList>
  <template v-if="!query && recentActions.length > 0">
    <span class="recent-search-title">{{ translate('Recent Actions') }}</span>
    <NavigableList
        :items="recentActions"
        :on-action="performAction"
        :no-results-text="translate('No matches found')"
        ref="navigableList"
    >
      <template #default="{ item, index, isSelected }">

      <span>
          {{ item.item.title }}
          <br>
          <span v-if="item.item?.data?.show_description === true">{{ item.item.data.description }}</span>
        </span>
        <div class="action-icon">
          <DynamicIcon name="Enter"/>
        </div>
      </template>
    </NavigableList>
  </template>


</template>

<script setup>
import {ref, onMounted, watch, nextTick, getCurrentInstance, useTemplateRef} from 'vue';
import {defineProps} from 'vue';
import {useRouter} from "vue-router";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import NavigableList from "@/Bits/Components/NavigableList.vue";
import Storage from "@/utils/Storage";
import translate from "@/utils/translator/Translator";
import Arr from "@/utils/support/Arr";
import SearchData from "@/Modules/GlobalSearch/SearchData";

const router = useRouter();
const selfRef = getCurrentInstance().ctx;
// Define props
const props = defineProps({
  query: {
    type: String,
    required: true,
  },
  searchResults: {
    type: Array,
    required: true,
    default: () => []
  },
  onActionPerformed: {
    type: Function,
    required: true,
  },
  focusInput: {
    type: Function,
    required: true,
  },
  searchData: {
    type: Array,
    required: true,
    default: () => []
  }
});

const selectedIndex = ref(0); // We'll update this in the watch effect
const searchResult = ref({});

const navigableList = ref();
const STORAGE_KEY = 'recent_actions';

const performAction = (item, index) => {

  const selectedItem = item.item;

  const data = selectedItem.data;
  const type = resolve(data.type);
  if (type === 'link') {
    let url = resolve(data.url);
    resolve(data.new_tab) ? window.open(url, '_blank') : window.location.href = url;
  } else if (type === 'action') {
    resolve(data.action)
  }

  storeRecentAction(item);

  props.onActionPerformed();
};

const storeRecentAction = (item) => {
  const selectedItemId = item.item.id;
  let history = Storage.get(STORAGE_KEY) || [];

  // Remove if already exists
  history = history.filter(id => id !== selectedItemId);

  // Add to the front
  history.unshift(selectedItemId);

  // Keep only the first 5
  history = history.slice(0, 5);

  Storage.set(STORAGE_KEY, history);
};


const resolve = (param) => {
  if (typeof param === 'function') {
    return call(param);
  }
  return param;
}

const call = async (callable) => {
  return await callable({
    query: props.query,
    router: router
  });
}

const resetSelection = () => {
  navigableList.value?.resetSelection();
}

const focusList = () => {
  navigableList.value?.focusList(0);

};

const performFirstAction = () => {
  navigableList.value?.performFirstAction();

}


watch(() => props.searchResults, async () => {
  searchResult.value = {};
  searchResult.value = props.searchResults ?? {};

  // Set initial selectedIndex to the maximum length
  selectedIndex.value = Object.keys(searchResult.value).length - 1;

  let tempResult = searchResult.value;

  for (const key of Object.keys(tempResult)) {
    const result = tempResult[key];
    if (result.item.title_resolver) {
      tempResult[key]['item']['title'] = await resolve(result.item.title_resolver);
    }
  }
  searchResult.value = {...tempResult};


});
const recentActions = ref([])
const fetchRecentActions = async () => {
  const recentActionsIds = Storage.get(STORAGE_KEY) || [];

  let actions = Arr.whereIn(props.searchData, 'id', recentActionsIds);
  let newActions = [];

  for (const action of actions) {
    if (typeof action.title_resolver === 'function') {
      action.title = await resolve(action.title_resolver);
    }
    newActions.push({
      item: action
    });
  }

  recentActions.value = newActions;
};
onMounted(() => {
  fetchRecentActions();
})

defineExpose({
  focusList, resetSelection, performFirstAction, fetchRecentActions
})
</script>

<style scoped>
.fct-global-search-result-list li:focus {
  outline: none;
}
</style>

