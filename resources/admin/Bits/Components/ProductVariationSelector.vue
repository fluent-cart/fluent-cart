<template>
    <el-tree-select
        v-model="selectedVariationIds"
        :data="products"
        :filter-method="debouncedSearch"
        :filter-node-method="filterNodeMethod"
        filterable
        show-checkbox
        :multiple="is_multiple"
        clearable
        :placeholder="$t('Select product')"
        @visible-change="(visible)=>{
          onVisibleChange(visible)
        }"
        :popper-class="popoverClass"
        :class="loading ? 'is-custom-loading' : ''"
    >
        <template v-if="!is_multiple" #label>
            <div class="flex items-center flex-wrap gap-1.5" v-if="selectedFormattedItems">
                <el-tag class="mr-2" v-for="(items, itemName) in selectedFormattedItems">
                    {{ itemName }}
                    <span v-if="items.length"> | {{ items.join(', ') }}</span>
                </el-tag>
            </div>
        </template>
        <template v-else #tag>
            <ProductChips class="fct-product-chips--wrap" :groups="productGroups" :tooltips-disabled="dropdownOpen"/>
        </template>
    </el-tree-select>
</template>

<script type="text/babel">

import Utils from "@/utils/Utils";
import ProductChips from "@/Bits/Components/ProductChips/ProductChips.vue";
import {groupIdsByTree} from "@/Bits/Components/ProductChips/productChips";
import {mergeProductTree} from "@/Bits/Components/ProductChips/labelIndex";
import translate from "@/utils/translator/Translator";

export default {
  name: 'ProductVariationSelector',
  components: {
    ProductChips
  },
  props: {
    modelValue: {
      type: [Array, String, Number],
      default: () => []
    },
    is_multiple: {
      type: Boolean,
      default: true
    },
    popoverClass: {
      type: String,
      default: ''
    },
    scopes: {
      type: Array,
      default: () => []
    }
  },
  emits: ['update:modelValue', 'visible-change'],
  data() {
    return {
      products: [],
      // Every product loaded so far, so picks hidden by a search keep their names.
      knownProducts: [],
      selectedVariationIds: this.modelValue,
      loading: false,
      dropdownOpen: false
    }
  },
  computed: {
    productGroups() {
      return groupIdsByTree(this.selectedVariationIds, this.knownProducts);
    },
    selectedFormattedItems() {
      const selectedVariationIds = [];
      if (this.selectedVariationIds) {
        selectedVariationIds.push(this.selectedVariationIds);
      }

      if (!selectedVariationIds || !selectedVariationIds?.length) {
        return null;
      }

      let keyedProducts = {};

      let keyedVariations = {};
      this.knownProducts.forEach(product => {
        keyedProducts[product.label] = product.children;
        product.children.forEach(variation => {
          keyedVariations[variation.value] = {
            productName: product.label,
            variationName: variation.label
          };
        });
      });

      let formattedItems = {};

      // loop through keyedVariations and format them
      selectedVariationIds.forEach(id => {
        if (keyedVariations[id]) {
          let variation = keyedVariations[id];
          if (!formattedItems[variation.productName]) {
            formattedItems[variation.productName] = [];
          }
          formattedItems[variation.productName].push(variation.variationName);
        } else {
          const unknown = translate('Unknown');
          if (!formattedItems[unknown]) {
            formattedItems[unknown] = [];
          }
          formattedItems[unknown].push(id);
        }
      });

      Object.keys(formattedItems).forEach(key => {
        if (formattedItems[key].length === keyedProducts[key]?.length) {
          formattedItems[key] = [];
        }
      });

      return formattedItems;
    }
  },
  watch: {
    selectedVariationIds(newVal) {
      this.$emit('update:modelValue', newVal);
    },
    modelValue(newVal) {
      this.selectedVariationIds = newVal;
    }
  },
  created() {
    // Built once per instance; a template-level debounce is rebuilt on every render.
    this.debouncedSearch = Utils.debounce(this.searchProducts);
  },
  mounted() {
    if (!this.is_multiple) {
      this.selectedVariationIds = this.selectedVariationIds ? parseInt(this.selectedVariationIds) : null;
    } else if (!Array.isArray(this.selectedVariationIds)) {
      this.selectedVariationIds = [];
    }
    this.searchProducts();
  },
  methods: {
    onVisibleChange(visible) {
      this.dropdownOpen = visible;
      this.$emit('visible-change', visible);
    },
    searchProducts(search = '') {
      this.loading = true;
      const queryParams = {
        search: search,
        include_ids: this.is_multiple ? this.selectedVariationIds : [this.selectedVariationIds]
      };
      if (this.scopes.length) {
        queryParams.scopes = this.scopes;
      }
      this.$get("products/search-product-variant-options", queryParams)
          .then((response) => {
            this.products = response.products;
            this.knownProducts = mergeProductTree(this.knownProducts, response.products);
          })
          .catch((errors) => {
          })
          .finally(() => {
            this.loading = false;
          });
    },
    filterNodeMethod(value, data) {
      return data;
    }
  }
}
</script>
