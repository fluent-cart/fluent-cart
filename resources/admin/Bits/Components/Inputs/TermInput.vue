<script setup>

import {getCurrentInstance, nextTick, onMounted, ref, watch, computed} from "vue";
import {inject} from 'vue'
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {handleSuccess, handleError} from "@/Bits/common";
import Animation from "@/Bits/Components/Animation.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import Utils from "@/utils/Utils";
import translate from "@/utils/translator/Translator";

const selfRef = getCurrentInstance().ctx;

const props = defineProps({
  modelValue: Array,
  taxonomy: Object,
  product_id: Number,
})

const emit = defineEmits(['update:modelValue', 'onChanged'])

const selectedTerms = ref([])
const showInput = ref(false)
const inputRef = ref()
const termName = ref('')
const terms = ref([]);
const parentTaxonomy = ref('');
const isChanges = ref(false);

onMounted(() => {
  selectedTerms.value = props.modelValue;
  terms.value = props.taxonomy.terms;

  watch(
      () => props.taxonomy.terms,
      (newVal, oldVal) => {
        terms.value = newVal;
      }
  );

  watch(
      () => props.modelValue,
      (newSelectedVal, oldSelectedVal) => {
        isChanges.value = true;
        selectedTerms.value = newSelectedVal;
      }
  );

})

const onEnter = (event) => {
  event.stopPropagation();
  event.preventDefault();
  addTerms();
}

const syncTaxonomyTerm = () => {
  if (!isChanges.value) {
    return;
  }

  Rest.post('products/sync-taxonomy-term/' + props.product_id, {
    taxonomy: props.taxonomy.name,
    terms: selectedTerms.value,
  })
      .then(response => {
        Notify.success(response.message);
        emit('update:modelValue', selectedTerms.value)
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      })
      .finally(() => {
        isChanges.value = false;
      });
}

const debounceOnRemoved = Utils.debounce((option) => {
  onRemoved(option);
}, 1200);

const onChanged = () => {
  emit('update:modelValue', selectedTerms.value)
}

const onRemoved = (option) => {
  isChanges.value = false;
  Rest.post('products/delete-taxonomy-term/' + props.product_id, {
    taxonomy: props.taxonomy.name,
    term: option,
  })
      .then(response => {
        handleSuccess(response.message)
        emit('update:modelValue', selectedTerms.value)
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      })
      .finally(() => {
        isChanges.value = false;
      });
}

let previousTerms = [...selectedTerms.value];

const onVisibilityChange = (visible) => {
  if (!visible && JSON.stringify(selectedTerms.value) !== JSON.stringify(previousTerms)) {
    syncTaxonomyTerm();
    previousTerms = [...selectedTerms.value];
  }
};

const addTerms = () => {
  isChanges.value = true;
  Rest.post('products/add-product-terms', {
    term: {
      name: termName.value,
      taxonomy: props.taxonomy.name,
      parent: parentTaxonomy.value
    }
  }).then(response => {
    showInput.value = false;
    termName.value = '';

    response.term_ids.forEach(function (term, index) {
      terms.value.push({
        'value': term,
        'label': response.names[index]
      })
      selectedTerms.value.push(term)
    })

    syncTaxonomyTerm();

  }).catch(err => {

  })
}

const toggleInputView = () => {
  showInput.value = !showInput.value;

  if (showInput.value) {
    nextTick(() => {
      inputRef.value.focus()
    })
  }
}

// Mapping of plural to singular forms
const singularForms = {
  categories: 'Category',
  types: 'Type'
};

const cleanTaxonomyLabel = computed(() => {
  // Remove the word "product" (case-insensitive) and any extra spaces from the taxonomy label
  let label = props.taxonomy.label.replace(/product\s*/i, '').trim();

  // Convert the label to its singular form if a corresponding mapping exists in the singularForms object
  Object.keys(singularForms).forEach(plural => {
    if (label.toLowerCase().includes(plural)) {
      // Replace the plural form with its singular counterpart
      label = label.replace(new RegExp(plural, 'i'), singularForms[plural]);
    }
  });

  return label;
});

const handleTagClose = (term) => {
  const index = selectedTerms.value.indexOf(term);
  if (index > -1) {
    selectedTerms.value.splice(index, 1);
  }
  isChanges.value = false;
  debounceOnRemoved(term);
};

const getTermLabel = (termValue) => {
  const findInTree = (items) => {
    for (const item of items) {
      // Check current item
      if (item.value == termValue) {
        return item.label;
      }

      // Search in children recursively
      if (item.children && item.children.length > 0) {
        const found = findInTree(item.children);
        if (found) return found;
      }
    }
    return null;
  };

  return findInTree(terms.value);
};

</script>

<template>
  <div class="fct-term-input-item">
    <el-tree-select
        v-model="selectedTerms"
        :data="terms"
        multiple
        :render-after-expand="false"
        show-checkbox
        check-strictly
        check-on-click-node
        @check="() => {isChanges = true;}"
        @blur="syncTaxonomyTerm"
        @change="onChanged"
        @remove-tag="(option) => {
          isChanges = false;
          debounceOnRemoved(option);
        }"
    >
      <template #tag>
        <el-tag
            v-for="(term, i) in selectedTerms"
            :key="i"
            closable
            @close="handleTagClose(term)"
        >
          <span class="el-tag__content">
            <span class="el-select__tags-text" v-html="getTermLabel(term)" />
          </span>
        </el-tag>
      </template>
      <template #default="{ data }">
        <span v-html="data.label"></span>
      </template>
    </el-tree-select>

    <!--    <el-select v-model="selectedTerms" multiple :placeholder="`Select Existing ${cleanTaxonomyLabel}`" @change="onChanged" @visible-change="onVisibilityChange" @remove-tag="onRemoved">-->
    <!--      <el-option-->
    <!--          v-for="term in terms"-->
    <!--          :key="term.value"-->
    <!--          :label="term.label"-->
    <!--          :value="term.value"-->
    <!--      ></el-option>-->
    <!--    </el-select>-->

    <div class="pt-1.5 text-right">
      <el-button text @click="toggleInputView" size="small">
        <span class="relative w-3 h-3">
          <Animation :visible="showInput" fade>
            <DynamicIcon name="Cross" class="absolute justify-center inset-0 w-full he-full"/>
          </Animation>
          <Animation :visible="!showInput" fade>
            <DynamicIcon name="Plus" class="absolute justify-center inset-0 w-full he-full"/>
          </Animation>
        </span>
        {{
          /* translators: %s - taxonomy label */
          translate('Add %s', props.taxonomy.labels.singular_name)
        }}
      </el-button>
    </div>

    <Animation :visible="showInput" accordion>
      <div class="fct-term-add-input-wrap">
        <el-input v-model="termName" ref="inputRef" v-on:keydown.enter="onEnter"/>
        <el-button type="info" plain @click="addTerms" class="el-button--x-small">{{ translate('Add') }}</el-button>
      </div>
    </Animation>
  </div>
</template>
