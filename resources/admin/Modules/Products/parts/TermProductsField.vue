<template>
  <div class="fct-term-products-field">
    <FctSelect
        id="fct-term-products"
        v-model="selectedIds"
        multiple
        filterable
        remote
        :remote-method="search"
        :loading="loading"
        :placeholder="config.strings.placeholder"
        :no-data-text="loadError ? config.strings.load_error : config.strings.no_results"
        :no-match-text="config.strings.no_results"
        :options="options.map(product => ({label: product.title, value: product.id}))"
    />

    <input type="hidden" name="fct_products_add" :value="addedIds.join(',')">
    <input type="hidden" name="fct_products_remove" :value="removedIds.join(',')">

    <p class="description">
      {{ config.strings.help }}
      <a v-if="config.more_url" :href="config.more_url">{{ config.more_text }}</a>
    </p>
  </div>
</template>

<script setup>
import {computed, onBeforeUnmount, onMounted, ref} from 'vue';
import Rest from '@/utils/http/Rest';
import FctSelect from '@/Bits/Components/Fct/Select.vue';

const props = defineProps({
  config: {type: Object, required: true}
});

const initialIds = props.config.products.map(product => product.id);
const selectedIds = ref([...initialIds]);
const results = ref([]);
const known = ref(Object.fromEntries(props.config.products.map(product => [product.id, product.title])));
const loading = ref(false);
const loadError = ref(false);
let searchToken = 0;

const addedIds = computed(() => selectedIds.value.filter(id => !initialIds.includes(id)));
const removedIds = computed(() => initialIds.filter(id => !selectedIds.value.includes(id)));

// Selected products stay in the list so their chips keep a label while a search hides them.
const options = computed(() => {
  const ids = new Set(results.value.map(product => product.id));
  const selected = selectedIds.value
      .filter(id => !ids.has(id))
      .map(id => ({id, title: known.value[id] || '#' + id}));
  return [...results.value, ...selected];
});

const search = (query) => {
  const token = ++searchToken;
  loading.value = true;
  loadError.value = false;
  Rest.get('fluent-products', {
    search: query,
    status: 'publish,draft,pending,private,future',
    context: 'edit',
    per_page: 20,
    _fields: 'id,title'
  }, props.config.wp_rest_url)
      .then(response => {
        if (token !== searchToken) {
          return;
        }
        results.value = response.map(post => ({id: post.id, title: post.title.raw || '#' + post.id}));
        results.value.forEach(product => {
          known.value[product.id] = product.title;
        });
      })
      .catch(() => {
        if (token === searchToken) {
          results.value = [];
          loadError.value = true;
        }
      })
      .finally(() => {
        if (token === searchToken) {
          loading.value = false;
        }
      });
};

// The Add form posts by AJAX and WP clears only its own fields; clear ours after a successful add.
const onAjaxSuccess = (event, xhr, settings) => {
  const data = typeof settings.data === 'string' ? settings.data : '';
  if (data.indexOf('action=add-tag') === -1 || xhr.responseText.indexOf('<wp_error') !== -1) {
    return;
  }
  selectedIds.value = [];
};

onMounted(() => window.jQuery && window.jQuery(document).on('ajaxSuccess', onAjaxSuccess));
onBeforeUnmount(() => window.jQuery && window.jQuery(document).off('ajaxSuccess', onAjaxSuccess));
</script>
