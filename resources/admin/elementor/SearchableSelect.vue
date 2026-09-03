<script setup>
import {ref, onMounted} from 'vue';
import {useDebounce} from "@/mixin/useDebounce";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  value: {
    type: [String, Number],
    default: ''
  },
  onChange: {
    type: Function,
    required: true
  }
});

const searchTerm = ref('');
const options = ref({});
const loading = ref(false);

const searchProducts = async (search, includeIds) => {
  loading.value = true;
  try {
    const queryParams = {
      "active_view": 'publish',
      "per_page": 10,
      "page": 1,
      "search": search,
      'filter_type': 'simple',
      'sort_by': 'ID',
      // Deliberately empty, not an oversight. This component emits
      // { value, label, product } and its only consumer,
      // elementor/ProductDetailsButton/App.vue, reads product.ID and
      // product.post_title — both columns on the product row itself. Nothing
      // downstream touches detail or variants, so asking for them would be a
      // hasOne + hasMany per page of results for data no one renders.
      'with': []
    };

    if (includeIds) {
      queryParams['include_ids'] = [includeIds];
    }
    Rest.get('products', queryParams)
        .then(response => {
          const newOptions = {};
          response.products.data.forEach(product => {
            newOptions[product.ID.toString()] = {
              value: product.ID.toString(),
              label: product.post_title,
              product: product
            };
          });

          options.value = newOptions;

        })
        .catch((errors) => {
        })
        .finally(() => {

        });

  } catch (error) {
  } finally {
    loading.value = false;
  }
};

const debouncedSearch = useDebounce(() => {
  searchProducts(searchTerm.value);
}, 500);

const handleSearchChange = (newSearch) => {
  searchTerm.value = newSearch;
  debouncedSearch();
};

const handleOptionClick = (option) => {
  props.onChange(option);
};

onMounted(() => {
  searchProducts('', props.value);
});
</script>

<template>
  <div>
    <div class="searchable-select-container">
      <el-select
          v-model="searchTerm"
          filterable
          remote
          :placeholder="translate('Search for products...')"
          :remote-method="handleSearchChange"
          :loading="loading"
          @change="(value) => {
            const option = Object.values(options).find(opt => opt.label === value);
            if (option) handleOptionClick(option);
          }"
      >
        <el-option
            v-for="option in Object.values(options)"
            :key="option.value"
            :label="option.label"
            :value="option.label"
            :class="{ 'option-item-selected': value === option.value }"
        />
        <template #empty>
          <div v-if="loading" class="searchable-select-loading">{{ translate('Loading...') }}</div>
          <div v-else-if="searchTerm" class="no-results">{{ translate('No products found.') }}</div>
        </template>
      </el-select>
    </div>
  </div>
</template>

<style scoped>

</style>
