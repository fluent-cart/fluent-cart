<script setup>
import {getCurrentInstance, onMounted, ref, watch, onBeforeUnmount, computed} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {handleSuccess, handleError} from "@/Bits/common";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";

const selfRef = getCurrentInstance().ctx;

const props = defineProps({
  bindToType: {
    type: String,
  },
  bindToId: {
    type: [String,Number],
  },
  selected_labels: {
    type: Array,
    default: []
  },
  labelModalIsOpen: {
    type: Boolean,
    default: false
  },
  shouldEnableEditing: {
    type: Boolean,
    default: true
  },
})

const emit = defineEmits(['update:update-label', 'close-label-modal'])

const selectedLabels = ref([]);
const newlyAddedLabels = ref([]);
const tempSelectedLabels = ref([]);
const inputRef = ref();
const labelName = ref('');
const labels = ref([]);
const searchableLabels = ref([]);
const showSuggestions = ref(false);
const suggestionsRef = ref(false);
const labelModalIsOpen = ref(props.labelModalIsOpen);
const searchQuery = ref('');

// Watch for changes in the labelModalIsOpen prop
// Whenever the value of labelModalIsOpen prop changes, update the local labelModalIsOpen ref
watch(() => props.labelModalIsOpen, (newVal) => {
  // Update the local ref to match the new prop value
  labelModalIsOpen.value = newVal;

  // When the modal is opened, initialize tempSelectedLabels to match selectedLabels
  if (newVal) {
    tempSelectedLabels.value = [...selectedLabels.value];
  }
});

const onEnter = (event) => {
  event.stopPropagation();
  event.preventDefault();
  addLabels();
}

const fetch = () => {
  selfRef.$get("labels")
      .then((response) => {
        labels.value = response.labels;
        selectedLabels.value = props.selected_labels;
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      })
      .finally(() => {

      });
}

const addLabels = (action = '') => {
  const valueToAdd = labelName.value || searchQuery.value;

  selfRef.$post('labels', {
    value: valueToAdd,
    bind_to_type: props.bindToType,
    bind_to_id: props.bindToId
  }).then(response => {
    labelName.value = '';
    searchQuery.value = '';
    labels.value.push(response.data);
    if (action !== 'insideModal') {
      selectedLabels.value.push(response.data.id);
    } else {
      newlyAddedLabels.value.push(response.data.id);
    }
    emit('update:update-label', selectedLabels.value)
  }).catch(errors => {
    Notify.error(errors)
  })
}

// Watch for changes in the labelName ref
watch(labelName, (newValue) => {
  // Check if the new value of labelName is not empty
  if (newValue) {
    newValue = newValue.trim();
    // Filter the labels based on the new value of labelName (case-insensitive match)
    searchableLabels.value = labels.value.filter(label =>
        label.value.toLowerCase().includes(newValue.toLowerCase())
    );

    // Show the suggestions dropdown
    showSuggestions.value = true;
  } else {
    // If the new value is empty, clear the searchableLabels array
    searchableLabels.value = [];
    // Hide the suggestions dropdown
    showSuggestions.value = false;
  }
});

// Toggle selected labels on checkbox change
const toggleSelectedLabel = (labelId) => {
  // Find the index of the labelId in the selectedLabels array
  const index = selectedLabels.value.indexOf(labelId);

  if (index === -1) {
    // If the labelId is not in the selectedLabels array, add it
    selectedLabels.value.push(labelId);
  } else {
    // If the labelId is already in the selectedLabels array, remove it
    selectedLabels.value.splice(index, 1);
  }

  // Emit an event to update the parent component with the new selected labels
  emit('update:update-label', selectedLabels.value);
};

// Toggle temporary selected labels on checkbox change
const toggleTempSelectedLabel = (labelId) => {
  const index = tempSelectedLabels.value.indexOf(labelId);

  if (index === -1) {
    tempSelectedLabels.value.push(labelId);
  } else {
    tempSelectedLabels.value.splice(index, 1);
  }
};

// Toggle newly added labels on checkbox change
const toggleNewlyAddedLabel = (labelId) => {
  const index = newlyAddedLabels.value.indexOf(labelId);

  if (index === -1) {
    newlyAddedLabels.value.push(labelId);
  } else {
    newlyAddedLabels.value.splice(index, 1);
  }
};

// Save tempSelectedLabels and newlyAddedLabels to the main selectedLabels
const handleSaveSelectedLabels = () => {
  // Merge newlyAddedLabels and tempSelectedLabels into selectedLabels
  selectedLabels.value = [...tempSelectedLabels.value, ...newlyAddedLabels.value];

  // Clear newlyAddedLabels
  newlyAddedLabels.value = [];

  // Emit updated selectedLabels
  emit("update:update-label", selectedLabels.value);

  // Close the modal
  handleLabelModalClose();
};

const removeLabel = (labelId) => {
  // Find the index of the labelId in the selectedLabels array
  const index = selectedLabels.value.indexOf(labelId);

  // Remove the label from selectedLabels if it exists
  if (index !== -1) {
    selectedLabels.value.splice(index, 1);

    // Emit the updated selected labels
    emit('update:update-label', selectedLabels.value);
  }
};

const hideSuggestionsOnOutsideClick = (event) => {
  // Check if suggestions are currently being shown
  if (
      showSuggestions.value &&
      // Check if the click target is outside the input element
      !inputRef.value.$el.contains(event.target) &&
      // Check if the click target is outside the suggestions list
      !suggestionsRef.value.contains(event.target)
  ) {
    // If both conditions are true, hide the suggestions list
    showSuggestions.value = false;
    // reset labelName
    labelName.value = '';
  }
};

// Computed property to filter labels based on selectedLabels
const filteredLabels = computed(() => {
  return labels.value.filter(label => selectedLabels.value.includes(label.id));
});

// Computed property to filter labels based on newlyAddedLabels
const filterNewlyAddedLabels = computed(() => {
  return labels.value.filter(label => newlyAddedLabels.value.includes(label.id));
});

// Computed property to filter labels based on tempSelectedLabels
const toAddLabels = computed(() => {
  return labels.value
      .filter((label) => tempSelectedLabels.value.includes(label.id))
      .filter((label) => label.value.toLowerCase().includes(searchQuery.value.trim().toLowerCase()));
});

// Computed property to get available labels
const availableLabels = computed(() => {
  return labels.value
      .filter((label) => !tempSelectedLabels.value.includes(label.id))
      .filter((label) => !newlyAddedLabels.value.includes(label.id))
      .filter((label) => label.value.toLowerCase().includes(searchQuery.value.trim().toLowerCase()))
      .sort((a, b) => a.value.localeCompare(b.value));
});

const handleLabelModalClose = () => {
  emit('close-label-modal');
}

/**
 * `exactMatchExists` is a computed property that determines if the current search term
 * (`searchQuery`) or label name (`labelName`) exactly matches any label in the following lists:
 *
 * - `searchableLabels`: Labels that match the current search input.
 * - `toAddLabels`: Labels that are selected to be added.
 * - `availableLabels`: Labels that are available for selection.
 *
 * This check is case-insensitive, meaning it ignores differences in capitalization.
 *
 * The computed property returns `true` if either the `searchQuery` or `labelName` matches
 * any label from `searchableLabels`, `toAddLabels`, or `availableLabels`. If there is no
 * exact match found, it returns `false`.
 */
const exactMatchExists = computed(() => {
  const searchQueryLower = searchQuery.value.trim().toLowerCase();
  const labelNameLower = labelName.value.trim().toLowerCase();

  // Check if searchQuery or labelName matches any label in searchableLabels, toAddLabels, or availableLabels
  return (
      ((searchQueryLower || labelNameLower) === '') ||
      searchableLabels.value.some(label =>
          label.value.toLowerCase() === searchQueryLower ||
          label.value.toLowerCase() === labelNameLower
      ) ||
      toAddLabels.value.some(label =>
          label.value.toLowerCase() === searchQueryLower ||
          label.value.toLowerCase() === labelNameLower
      ) ||
      availableLabels.value.some(label =>
          label.value.toLowerCase() === searchQueryLower ||
          label.value.toLowerCase() === labelNameLower
      )
  );
});

onMounted(() => {
  fetch();
  document.addEventListener('click', hideSuggestionsOnOutsideClick);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', hideSuggestionsOnOutsideClick);
});
</script>

<template>
  <div class="fct-term-container">
    <div class="fct-term-input-wrap">
      <el-input ref="inputRef" v-model="labelName" v-on:keydown.enter="onEnter" v-if="shouldEnableEditing"/>

      <div class="fct-term-search-result" v-if="showSuggestions" ref="suggestionsRef"
           :style="labelName == '' ? 'display: none' : ''">

        <!-- Only show this button if there is no exact match with labelName and labelName is not empty -->
        <div v-if="labelName !== '' && !exactMatchExists" class="fct-term-search-result-button-wrap mb-1.5">
          <el-button type="info" plain @click="addLabels" size="small">
            <DynamicIcon name="PlusCircle"/>
            {{ $t('Add') }} <span class="search-text">"{{ labelName }}"</span>
          </el-button>
        </div>

        <ul class="fct-term-search-result-list is-scrollable" v-if="searchableLabels.length > 0">
          <li v-for="(label, index) in searchableLabels" :key="index">
            <label>
              <input type="checkbox" :value="label.id" :checked="selectedLabels.includes(label.id)"
                     @change="toggleSelectedLabel(label.id)">
              {{ label.value }}
            </label>
          </li>
        </ul>
        <p v-if="searchableLabels.length === 0" class="empty-text">{{ $t('No labels found.') }}</p>
      </div><!-- .fct-term-search-result -->
    </div><!-- .fct-term-input-wrap -->

    <ul class="fct-term-selected-list" v-if="filteredLabels.length > 0">
      <li v-for="label in filteredLabels" :key="label.id">
        <div class="fct-term-selected">
          <div class="text">{{ label.value }}</div>
          <DynamicIcon name="Cross" @click="removeLabel(label.id)" v-if="shouldEnableEditing"/>
        </div>
      </li>
    </ul>

    <el-dialog :before-close="handleLabelModalClose" :append-to-body="true" width="45%" v-model="labelModalIsOpen"
               :title="$t('Add labels')" class="fct-add-labels-modal">
      <div class="fct-term-list-header">
        <div class="fct-term-input-inline-wrap">
          <el-input v-model="searchQuery" :placeholder="$t('Search to find or create labels')">
            <template #prefix>
              <DynamicIcon name="Search"/>
            </template>
          </el-input>
        </div>

        <!-- Only show this button if there is no exact match with searchQuery and searchQuery is not empty -->
        <div v-if="searchQuery !== '' && !exactMatchExists" class="fct-term-list-header-button-wrap">
          <el-button type="info" plain @click="addLabels('insideModal')" size="small">
            <DynamicIcon name="PlusCircle"/>
            {{ $t('Add') }} <span class="search-text">"{{ searchQuery }}"</span>
          </el-button>
        </div>
      </div><!-- .fct-term-list-header -->

      <div class="fct-term-list-wrap is-scrollable">
        <ul v-if="filterNewlyAddedLabels.length > 0" class="fct-term-list newly-added-list">
          <li class="title">{{ $t("Newly added") }}</li>
          <li v-for="label in filterNewlyAddedLabels" :key="label.id">
            <label class="fct-term-label">
              <input
                  type="checkbox"
                  :value="label.id"
                  :checked="newlyAddedLabels.includes(label.id)"
                  @change="toggleNewlyAddedLabel(label.id)"
              />
              {{ label.value }}
            </label>
          </li>
        </ul>

        <ul v-if="toAddLabels.length > 0" class="fct-term-list to-add-list">
          <li class="title">{{ $t("To add") }}</li>
          <li v-for="label in toAddLabels" :key="label.id">
            <label class="fct-term-label">
              <input
                  type="checkbox"
                  :value="label.id"
                  :checked="tempSelectedLabels.includes(label.id)"
                  @change="toggleTempSelectedLabel(label.id)"
              />
              {{ label.value }}
            </label>
          </li>
        </ul>

        <ul v-if="availableLabels.length > 0" class="fct-term-list available-list">
          <li class="title">{{ $t("Available") }}</li>
          <li v-for="label in availableLabels" :key="label.id">
            <label class="fct-term-label">
              <input
                  type="checkbox"
                  :value="label.id"
                  :checked="tempSelectedLabels.includes(label.id)"
                  @change="toggleTempSelectedLabel(label.id)"
              />
              {{ label.value }}
            </label>
          </li>
        </ul>

        <p v-if="toAddLabels.length === 0 && availableLabels.length === 0 && filterNewlyAddedLabels.length === 0"
           class="empty-text">{{ $t('No labels found.') }}</p>
      </div><!-- .fct-term-list-wrap -->

      <div class="dialog-footer">
        <el-button @click="handleLabelModalClose">
          {{ $t('Cancel') }}
        </el-button>
        <el-button type="primary" @click="handleSaveSelectedLabels">
          {{ $t('Done') }}
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>
