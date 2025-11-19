<script setup>

import {getCurrentInstance, nextTick, onMounted, ref} from "vue";
import Fuse from 'fuse.js';
import SearchData from './SearchData.js';
import SearchOptions from './SearchOptions.js';
import ResultList from "@/Modules/GlobalSearch/ResultList.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import RemoteResult from "@/Modules/GlobalSearch/RemoteResult.vue";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import Animation from "@/Bits/Components/Animation.vue";

const selfRef = getCurrentInstance().ctx;

const showSearchModal = ref(false);
const searchQuery = ref('');
const searchResult = ref({});
const searchRef = ref();
const currentPlaceholder = ref('');
const placeholderPhrases = ref([
  translate('orders'),
  translate('products'),
  translate('customers'),
  translate('coupons'),
  translate('settings'),
  translate('menus'),
  translate('shortcuts...')
]);
const currentPhraseIndex = ref(0);
const typingForward = ref(true);
const typingCharIndex = ref(0);
const placeholderInterval = ref(null)
const holdTimer = ref(null);
const typingSpeed = ref(70)
const deletingSpeed = ref(40);
const holdDuration = ref(1700);

const onModalOpened = () => {
  //focusInput();
}

const onModalClosed = () => {
  if (showSearchModal.value) {
    remoteResultRef.value?.resetSelection();

  } else {
    resultListRef.value?.resetSelection();
  }
  useRemoteSearch.value = false;
  isRemoteSearching.value = false;
  reset();
}

const focusInput = () => {
  searchRef.value?.focus();
}

const reset = () => {
  useRemoteSearch.value = false;
  searchResult.value = [];
  showSearchModal.value = false;
  searchQuery.value = "";
  selectedTag.value = null;
}

const resultListRef = ref();

const buildGroupQuery = (searchQuery) => {
  let containsComma = searchQuery.includes(',');
  let groupQuery = {};
  let commonSearch = [];
  let groupedSearch = containsComma ? searchQuery.split(',') : [searchQuery];
  groupedSearch.forEach((query) => {
    if (query.includes(':')) {
      appendSearchGroup(query, groupQuery, commonSearch)
    } else {
      commonSearch.push(query);
    }
  });

  let queryOptions = [];

  Object.keys(groupQuery).forEach((groupKey) => {
    const groupCommons = groupQuery[groupKey];
    if (groupCommons.length > 0) {
      groupCommons.forEach((common) => {
        if (common.length > 0) {
          queryOptions.push({group: `'` + groupKey, title: common});
        } else {
          queryOptions.push({group: `'` + groupKey});
        }
      });
    } else {
      queryOptions.push({group: `'` + groupKey});
    }

  })

  commonSearch.forEach((common) => {
    if (common.length > 0) {
      queryOptions.push({title: common});
    }
  });
  return queryOptions;
}

const appendSearchGroup = (searchQuery, groupQuery, commonSearch) => {
  if (searchQuery.length === 1) {
    //Because there is only colon.
    return;
  }
  let exploded = searchQuery.split(':');
  let group = exploded[0]; // The Left side of colon is group key
  let common = exploded[1]; // The Right side of colon is any key but common

  if (group.length > 0) {
    if (!groupQuery.hasOwnProperty(group)) {
      //ensure the group key exist in nested keys
      groupQuery[group] = [];
    }

    if (common.length > 0) {
      groupQuery[group].push(common);
    }
  } else {
    //If there is no group, it is a wildcard search
    commonSearch.push(common)
  }
}

const useRemoteSearch = ref(false);
const selectedTag = ref(null);
const tags = ref({
  o: {
    key: 'orders',
    title: translate('Orders')
  },
  p: {
    key: 'products',
    title: translate('Products')
  },
  c: {
    key: 'customers',
    title: translate('Customers')
  },
  d: {
    key: 'coupons',
    title: translate('Coupons')
  }
});

const handleTagSelect = (tag) => {
  selectedTag.value = tag;
  useRemoteSearch.value = true;
  searchResult.value = {};
  nextTick(() => {
    remoteResultRef.value?.fetchData(searchQuery.value, tag);
    searchRef.value?.focus();
  })

}

const handleRemoveTag = () => {
  searchResult.value = [];
  selectedTag.value = null;
  searchQuery.value = "";
  useRemoteSearch.value = false;
  searchRef.value?.focus();
  isRemoteSearching.value = false;
}

const searchRemote = () => {
  remoteResultRef.value?.fetchData(searchQuery.value, selectedTag.value);
}

const isRemoteSearching = ref(false);
const onRemoteSearchStateChange = (state) => {
  isRemoteSearching.value = state;
  nextTick(() => {
    searchRef.value?.focus();
  });
}


const remoteResultRef = ref();

const keyboardShortcuts = useKeyboardShortcuts();
keyboardShortcuts.bind('/', (event) => {
  if (showSearchModal.value) {
    event.preventDefault();
    focusInput()
  }
});

keyboardShortcuts.bind(['mod+k'], (event, combo) => {
  event.preventDefault();
  let selectedTag = null;
  const binders = ['o', 'p', 'c', 'd'];
  keyboardShortcuts.bind(binders, (event) => {
    event.preventDefault();
    selectedTag = tags.value[event.key];
    if (selectedTag) {
      handleTagSelect(selectedTag);
    }
  });

  showSearchModal.value = true;
  resultListRef.value?.fetchRecentActions();


  setTimeout(() => {
    focusInput();
    keyboardShortcuts.unbind(binders);
  }, 500);

});

const startPlaceholderCycling = () => {
  if (placeholderInterval.value) return;

  const typeStep = () => {
    if (searchRef.value && searchRef.value.length > 0) {
      return;
    }

    const phrase = placeholderPhrases.value[currentPhraseIndex.value];

    if (typingForward.value) {
      if (typingCharIndex.value < phrase.length) {
        currentPlaceholder.value = phrase.slice(0, typingCharIndex.value + 1);
        typingCharIndex.value++;
      } else {
        typingForward.value = false;
        // Hold before deleting
        clearTimeout(holdTimer.value);
        holdTimer.value = setTimeout(() => {
          holdTimer.value = null;
        }, holdDuration.value);
      }
    } else {
      if (holdTimer.value) {
        return;
      }

      if (typingCharIndex.value > 0) {
        typingCharIndex.value--;
        currentPlaceholder.value = phrase.slice(0, typingCharIndex.value);
      } else {
        typingForward.value = true;
        currentPhraseIndex.value = (currentPhraseIndex.value + 1) % placeholderPhrases.value.length;
      }
    }
  };

  const loop = () => {
    typeStep();
    const delay = typingForward.value
        ? typingSpeed.value
        : (holdTimer.value ? 120 : deletingSpeed.value);
    placeholderInterval.value = setTimeout(loop, delay);
  };

  loop();
};

let fuse;
onMounted(() => {
  startPlaceholderCycling();
  fuse = new Fuse(SearchData, SearchOptions());
})
</script>

<template>

  <teleport to="#fct_admin_menu_search">
    <div class="fct-global-search-input-wrap">
      <button @click="showSearchModal = true" type="button" class="fct-setting-search-button" aria-label="Search">
          <span class="fct-setting-search-icon">
            <DynamicIcon name="Search"/>
          </span>
        <span class="fct-global-search-button-keys">
            <kbd class="fct-global-search-button-key">⌘</kbd>
            <kbd class="fct-global-search-button-key">K</kbd>
          </span>
      </button>
    </div>
  </teleport>

  <el-dialog
      v-model="showSearchModal"
      @opened="onModalOpened"
      @closed="onModalClosed"
      class="fct-global-search-container"
      :append-to-body="true"
  >
    <template #header>
      <div class="fct-global-search-input-container">
        <DynamicIcon name="Search" class="search-icon"/>
        <div v-if="selectedTag != null" class="searched-item">
          {{ selectedTag.title }}
          <DynamicIcon name="Cross" class="searched-item-remove" @click="handleRemoveTag"/>
        </div>
        <el-input
            class="mousetrap"
            :placeholder="translate('Search for %s', currentPlaceholder)"
            autofocus
            ref="searchRef"
            id="fct-global-search-input"
            :disabled="isRemoteSearching"
            @keydown.arrow-down.prevent="()=>{
              if (useRemoteSearch){
                remoteResultRef?.focusList();
                return;
              }
              resultListRef?.focusList();
            }"

            @keydown.enter.prevent="()=>{
              if (useRemoteSearch){
                remoteResultRef?.performFirstAction();
                return;
              }
              resultListRef?.performFirstAction();
            }"

            @keydown.backspace="() => {
              if (searchQuery.length === 0) {
                selectedTag = null;
                useRemoteSearch = false;
              }
            }"


            @input="()=>{
              if(useRemoteSearch){
                searchRemote();
                return;
              }
              let containsComma = searchQuery.includes(',');
              let containsColon = searchQuery.includes(':');

              if(!containsComma && !containsColon){
                searchResult.value = fuse.search(searchQuery);
              }else{
                const queryOptions = buildGroupQuery(searchQuery)
                searchResult.value = fuse.search({
                    $or: queryOptions,
                });
              }
            }"
            v-model="searchQuery"
            clearable
        />
      </div>
    </template>

    <Animation :visible="!selectedTag && searchQuery.length === 0" accordion>
      <div class="fct-global-searching-for">
        <div class="fct-global-searching-for-label">
          {{ translate('Searching for') }}
        </div>
        <div class="fct-global-searching-suggestion-list">
          <div v-for="tag in tags" :key="tag.key" @click="handleTagSelect(tag)"
               class="fct-global-searching-suggestion"
               :class="selectedTag?.key === tag.key ? 'active' : ''"
          >
            {{ tag.title }}
          </div>
        </div>
      </div>
    </Animation>

    <template v-if="useRemoteSearch">
      <RemoteResult ref="remoteResultRef" :query="searchQuery" :tag="selectedTag" @close-modal="reset"
                    @onRemoteSearchStateChange="onRemoteSearchStateChange"/>
    </template>
    <template v-else>
      <ResultList ref="resultListRef" :query="searchQuery" :search-results="searchResult.value"
                  :onActionPerformed="reset"
                  :focusInput="focusInput"
                  :searchData="SearchData"
      />
    </template>

    <div class="dialog-footer is-border">
      <ul class="fct-search-commands">
        <li>
          <span class="command-key"><DynamicIcon name="ArrowUp"/></span>
          <span class="command-key"><DynamicIcon name="ArrowDown"/></span>
          <span class="label">{{ translate('To navigate') }}</span>
        </li>
        <li>
          <span class="command-key"><DynamicIcon name="Enter"/></span>
          <span class="label">{{ translate('To select') }}</span>
        </li>
      </ul>
    </div>

  </el-dialog>
</template>
