<script setup>
import {computed, getCurrentInstance, onMounted, onBeforeUnmount, ref} from "vue";
import {ElMessageBox} from "element-plus";
import Importer from "@/Modules/Products/BulkInsert/Importer.vue";
import bulkInsetModel from "@/Models/BulkInsetModel";
import ResizeableColumns from "@/Modules/Products/BulkInsert/ResizeableColumns.vue";
import BulkMediaPicker from "@/Bits/Components/Attachment/BulkMediaPicker.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Animation from "@/Bits/Components/Animation.vue";
import BulkPriceInput from "@/Modules/Products/BulkShared/BulkPriceInput.vue";
import BulkPaymentTypeCell from "@/Modules/Products/BulkShared/BulkPaymentTypeCell.vue";
import BulkIntervalSelect from "@/Modules/Products/BulkShared/BulkIntervalSelect.vue";
import BulkTrialDaysInput from "@/Modules/Products/BulkShared/BulkTrialDaysInput.vue";
import BulkComparePriceInput from "@/Modules/Products/BulkShared/BulkComparePriceInput.vue";
import BulkCategorySelect from "@/Modules/Products/BulkShared/BulkCategorySelect.vue";
import BulkEditorCell from "@/Modules/Products/BulkShared/BulkEditorCell.vue";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import {Loading, WarningFilled} from "@element-plus/icons-vue";
import {useBulkSelection} from "@/Modules/Products/BulkShared/useBulkSelection";
import {vIndeterminate} from "@/Modules/Products/BulkShared/vIndeterminate";
import {syncLinkedField, LINK_COLUMNS_INSERT} from "@/Modules/Products/BulkShared/bulkLinkColumns";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import * as Card from "@/Bits/Components/Card/Card.js";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate from "@/utils/translator/Translator";

const {options, products} = bulkInsetModel.data;
const isSimple = (product) => product?.detail?.variation_type === 'simple';

const saving = computed(() => bulkInsetModel.data.saving);
const saveProgress = computed(() => bulkInsetModel.data.saveProgress);

const progressPercentage = computed(() => {
  const progress = bulkInsetModel.data.saveProgress;
  if (!progress.total) return 0;
  return Math.round((progress.completed / progress.total) * 100);
});

const saveProducts = () => {
  bulkInsetModel.saveProducts();
};

// Status checks — thin delegates to model
const isSaving = (product) => bulkInsetModel.isSaving(product._cid);
const isSaved = (product) => bulkInsetModel.isSaved(product._cid);
const isRowDisabled = (product) => bulkInsetModel.isRowDisabled(product._cid);

// Error checks — thin delegates to model
const hasError = (product) => bulkInsetModel.hasError(product._cid);
const getError = (product) => bulkInsetModel.getError(product._cid);
const hasProductLevelError = (product) => bulkInsetModel.hasProductLevelError(product._cid);
const hasVariantError = (product, vi) => bulkInsetModel.hasVariantError(product._cid, vi);
const getVariantError = (product, vi) => bulkInsetModel.getVariantError(product._cid, vi);
const hasFieldError = (product, field) => bulkInsetModel.hasFieldError(product._cid, field);
const hasVariantFieldError = (product, vi, field) => bulkInsetModel.hasVariantFieldError(product._cid, vi, field);

const removeProduct = async (product) => {
  const title = product.post_title || self.$t('Untitled product');
  const variantCount = Array.isArray(product.variants) ? product.variants.length : 0;
  const isSavedProduct = bulkInsetModel.isSaved(product._cid);

  let message = variantCount > 0
    ? self.$t('This will remove "%s" and its %s variant(s).').replace('%s', title).replace('%s', variantCount)
    : self.$t('This will remove "%s".').replace('%s', title);

  if (isSavedProduct) {
    message += ' ' + self.$t('This product was already saved and will be permanently deleted.');
  }

  try {
    await ElMessageBox.confirm(message, self.$t('Remove Product'), {
      confirmButtonText: self.$t('Remove'),
      cancelButtonText: self.$t('Cancel'),
      type: 'warning',
    });
  } catch {
    return;
  }

  await bulkInsetModel.removeProduct(product);
};

const removeVariant = (product, variant) => {
  const title = variant.variation_title || self.$t('Untitled variant');
  ElMessageBox.confirm(
    self.$t('This will remove the variant "%s".').replace('%s', title),
    self.$t('Remove Variant'),
    {
      confirmButtonText: self.$t('Remove'),
      cancelButtonText: self.$t('Cancel'),
      confirmButtonClass: 'el-button--small',
      cancelButtonClass: 'el-button--small',
      type: 'warning',
    }
  ).then(() => {
    bulkInsetModel.removeVariant(product, variant);
  }).catch(() => {});
};

const duplicateProduct = (product) => bulkInsetModel.duplicateProduct(product);
const duplicateVariant = (product, variant) => bulkInsetModel.duplicateVariant(product, variant);

// ── Bulk duplicate ────────────────────────────────────────────
const categorizeSelection = () => {
  const result = [];
  for (const product of products) {
    const productKey = `p:${product._cid}`;
    const productSelected = selectedKeys.value.has(productKey);

    const selectedVariantIndexes = [];
    if (product.variants) {
      product.variants.forEach((_, vi) => {
        if (selectedKeys.value.has(`v:${product._cid}:${vi}`)) {
          selectedVariantIndexes.push(vi);
        }
      });
    }

    if (!productSelected && selectedVariantIndexes.length === 0) continue;

    if (productSelected && selectedVariantIndexes.length === 0) {
      result.push({ product, type: 'full' });
    } else if (!productSelected && selectedVariantIndexes.length > 0) {
      result.push({ product, type: 'variants-only', variantIndexes: selectedVariantIndexes });
    } else {
      result.push({ product, type: 'partial', variantIndexes: selectedVariantIndexes });
    }
  }
  return result;
};

const bulkDuplicate = () => {
  const items = categorizeSelection();
  if (!items.length) return;

  const fullCount = items.filter(i => i.type === 'full').length;
  const partialCount = items.filter(i => i.type === 'partial').length;
  const variantOnlyItems = items.filter(i => i.type === 'variants-only');
  const variantOnlyCount = variantOnlyItems.reduce((sum, i) => sum + i.variantIndexes.length, 0);

  const parts = [];
  if (fullCount > 0) parts.push(`${fullCount} product(s) with all variants`);
  if (partialCount > 0) parts.push(`${partialCount} product(s) with selected variants`);
  if (variantOnlyCount > 0) parts.push(`${variantOnlyCount} variant(s)`);

  ElMessageBox.confirm(
    self.$t('Duplicate %s?').replace('%s', parts.join(', ')),
    self.$t('Bulk Duplicate'),
    {
      confirmButtonText: self.$t('Duplicate'),
      cancelButtonText: self.$t('Cancel'),
      type: 'info',
    }
  ).then(() => {
    for (const item of items) {
      if (item.type === 'full') {
        bulkInsetModel.duplicateProduct(item.product);
      } else if (item.type === 'variants-only') {
        for (const vi of item.variantIndexes) {
          const variant = item.product.variants[vi];
          if (variant) bulkInsetModel.duplicateVariant(item.product, variant);
        }
      } else if (item.type === 'partial') {
        bulkInsetModel.duplicateProductWithVariants(item.product, item.variantIndexes);
      }
    }
    deselectAll();
  }).catch(() => {});
};

const bulkDelete = async () => {
  const productsToRemove = [];
  const variantsToRemove = [];

  for (const product of products) {
    const productKey = `p:${product._cid}`;
    const productSelected = selectedKeys.value.has(productKey);

    if (productSelected) {
      productsToRemove.push(product);
      continue;
    }

    const selectedVariants = [];
    if (product.variants) {
      product.variants.forEach((v, vi) => {
        if (selectedKeys.value.has(`v:${product._cid}:${vi}`)) {
          selectedVariants.push(v);
        }
      });
    }

    if (selectedVariants.length > 0) {
      variantsToRemove.push({ product, variants: selectedVariants });
    }
  }

  if (!productsToRemove.length && !variantsToRemove.length) return;

  const savedCount = productsToRemove.filter(p => bulkInsetModel.isSaved(p._cid)).length;
  const variantCount = variantsToRemove.reduce((sum, i) => sum + i.variants.length, 0);
  const parts = [];
  if (productsToRemove.length > 0) parts.push(`${productsToRemove.length} product(s)`);
  if (variantCount > 0) parts.push(`${variantCount} variant(s)`);

  let message = self.$t('Remove %s?').replace('%s', parts.join(' and '));
  if (savedCount > 0) {
    message += ' ' + self.$t('%s already saved will be permanently deleted.').replace('%s', `${savedCount}`);
  }

  try {
    await ElMessageBox.confirm(
      message,
      self.$t('Bulk Remove'),
      {
        confirmButtonText: self.$t('Remove'),
        cancelButtonText: self.$t('Cancel'),
        type: 'warning',
      }
    );
  } catch {
    return;
  }

  for (const product of productsToRemove) {
    await bulkInsetModel.removeProduct(product);
  }

  for (const { product, variants } of variantsToRemove) {
    for (const variant of variants) {
      bulkInsetModel.removeVariant(product, variant);
    }
  }

  deselectAll();
};

const onPaymentTypeChange = (variant) => {
  bulkInsetModel.resetPaymentTypeDefaults(variant);
};

const onCategoryChange = (product, values) => {
  const options = bulkInsetModel.data.categoryOptions;
  for (const val of values) {
    if (!options.find(o => o.value === val)) {
      options.push({ label: val, value: val });
    }
  }
};

const insertDummyProduct = () => {
  bulkInsetModel.populateDummyProduct()
}

const self = getCurrentInstance().ctx;

const onDataPopulated = (populatedProducts) => {
  const targetProducts = bulkInsetModel.data.products;
  if (!populatedProducts.concat) {
    targetProducts.splice(0, targetProducts.length);
    bulkInsetModel.data.savedIds = new Set();
    bulkInsetModel.data.savingIds = new Set();
    bulkInsetModel.data.productErrors = new Map();
  }
  targetProducts.push(...populatedProducts.products);
}

// Editor modal state
const editorModal = ref({
  visible: false,
  field: '',
  product: null,
  value: '',
});

const openEditor = (product, field) => {
  editorModal.value = {
    visible: true,
    field,
    product,
    value: product[field] || '',
  };
};

const onEditorUpdate = (content) => {
  editorModal.value.value = content;
};

const saveEditor = () => {
  const { product, field, value } = editorModal.value;
  if (product) {
    product[field] = value;
  }
  editorModal.value.visible = false;
};

const editorTitle = computed(() => {
  return editorModal.value.field === 'post_content'
    ? self.$t('Edit Description')
    : self.$t('Edit Short Description');
});

const stickyActions = ref(true);

// ── Row selection ─────────────────────────────────────────────
const {
  selectedKeys, handleRowClick, toggleSelectAll,
  selectAll, deselectAll, isSelected, selectedCount, pruneStaleKeys,
  isLinkMode, toggleLinkMode,
} = useBulkSelection();

const flatVisibleKeys = computed(() => {
  const keys = [];
  for (const group of productGroups.value) {
    keys.push(`p:${group.productId}`);
    if (group.hasVariants && !group.collapsed) {
      for (let vi = 0; vi < group.product.variants.length; vi++) {
        keys.push(`v:${group.productId}:${vi}`);
      }
    }
  }
  return keys;
});

const getRowByKey = (key) => {
  if (key.startsWith('p:')) {
    const cid = key.slice(2);
    const product = products.find(p => p._cid === cid);
    return product ? { type: 'product', data: product, product } : null;
  } else if (key.startsWith('v:')) {
    const parts = key.split(':');
    const cid = parts[1];
    const vi = parseInt(parts[2]);
    const product = products.find(p => p._cid === cid);
    if (product && product.variants?.[vi]) {
      return { type: 'variant', data: product.variants[vi], product };
    }
  }
  return null;
};

const onSync = (columnKey, value, rowKey) => {
  if (!isLinkMode.value || !isSelected(rowKey)) return;
  syncLinkedField(columnKey, value, rowKey, selectedKeys.value, getRowByKey, null, LINK_COLUMNS_INSERT);
};

const isShiftHeld = ref(false);
const onShiftDown = (e) => { if (e.key === 'Shift') isShiftHeld.value = true; };
const onShiftUp = (e) => { if (e.key === 'Shift') isShiftHeld.value = false; };

const onRowShiftClick = (key, event) => {
  if (!event.shiftKey) return;
  if (event.target.closest('.bulk-checkbox-cell')) return;
  event.preventDefault();
  const next = new Set(selectedKeys.value);
  if (next.has(key)) {
    next.delete(key);
  } else {
    next.add(key);
  }
  selectedKeys.value = next;
};

const columns = ref([]);

const filteredColumns = computed(() => {
  return columns.value.filter(col => {
    if (col.key === 'title' || col.key === 'actions') return true;
    return bulkInsetModel.isColumnVisible(col.key);
  });
});

const isColVisible = (key) => bulkInsetModel.isColumnVisible(key);

const onColumnResizeEnd = () => {
  bulkInsetModel.saveColumnWidths(columns.value);
};

// Collapse state
const collapsedProducts = ref(new Set());

const toggleCollapse = (product) => {
  const id = product._cid;
  if (collapsedProducts.value.has(id)) {
    collapsedProducts.value.delete(id);
  } else {
    collapsedProducts.value.add(id);
  }
  collapsedProducts.value = new Set(collapsedProducts.value);
};

const isCollapsed = (product) => {
  return collapsedProducts.value.has(product._cid);
};

// Virtual scrolling — group-based (product + variants block per group)
const ROW_HEIGHT = 43;
const BUFFER = 10;
const scrollTop = ref(0);
const containerHeight = 800;

const productGroups = computed(() => {
  let offset = 0;
  return products.map(product => {
    const productId = product._cid;
    const hasVariants = product.detail?.variation_type !== 'simple' && Array.isArray(product.variants);
    const variantCount = hasVariants ? product.variants.length : 0;
    const collapsed = collapsedProducts.value.has(productId);
    const variantsHeight = hasVariants && !collapsed ? (variantCount + 1) * ROW_HEIGHT : 0;
    const groupHeight = ROW_HEIGHT + variantsHeight;
    const group = { product, productId, hasVariants, variantCount, collapsed, groupHeight, offset };
    offset += groupHeight;
    return group;
  });
});

const totalHeight = computed(() => {
  const groups = productGroups.value;
  if (groups.length === 0) return 0;
  const last = groups[groups.length - 1];
  return last.offset + last.groupHeight;
});

const visibleGroups = computed(() => {
  const groups = productGroups.value;
  const vTop = scrollTop.value;
  const vBottom = vTop + containerHeight;
  const bufferPx = BUFFER * ROW_HEIGHT;

  return groups.filter(g => {
    const gBottom = g.offset + g.groupHeight;
    return gBottom >= vTop - bufferPx && g.offset <= vBottom + bufferPx;
  });
});

const topSpacerHeight = computed(() => {
  if (visibleGroups.value.length === 0) return 0;
  return visibleGroups.value[0].offset;
});

const bottomSpacerHeight = computed(() => {
  if (visibleGroups.value.length === 0) return 0;
  const last = visibleGroups.value[visibleGroups.value.length - 1];
  return totalHeight.value - last.offset - last.groupHeight;
});

let scrollTimer = null;
let lastScrollUpdate = 0;
const SCROLL_THROTTLE = 100;

const onScroll = (e) => {
  const target = e.target;
  const now = Date.now();

  if (scrollTimer) clearTimeout(scrollTimer);

  if (now - lastScrollUpdate >= SCROLL_THROTTLE) {
    scrollTop.value = target.scrollTop;
    lastScrollUpdate = now;
  } else {
    scrollTimer = setTimeout(() => {
      scrollTop.value = target.scrollTop;
      lastScrollUpdate = Date.now();
      scrollTimer = null;
    }, SCROLL_THROTTLE);
  }
};

let keyboard = null;

onMounted(async () => {
  keyboard = useKeyboardShortcuts();
  keyboard.bind('mod+a', (e) => {
    const tag = document.activeElement?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || document.activeElement?.isContentEditable) return;
    e.preventDefault();
    selectAll(flatVisibleKeys.value);
  });
  keyboard.bind('escape', () => {
    const tag = document.activeElement?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || document.activeElement?.isContentEditable) return;
    if (isLinkMode.value) {
      toggleLinkMode();
    } else {
      deselectAll();
    }
  });

  columns.value = [
    {key: 'title', title: self.$t('Title'), width: 150, minWidth: 100},
    {key: 'sku', title: self.$t('SKU'), width: 120, minWidth: 80},
    {key: 'media', title: self.$t('Media'), width: 100, minWidth: 50},
    {key: 'categories', title: self.$t('Categories'), width: 200, minWidth: 150},
    {key: 'description', title: self.$t('Description'), width: 200, minWidth: 150},
    {key: 'short_description', title: self.$t('Short Description'), width: 150, minWidth: 100},
    {key: 'status', title: self.$t('Status'), width: 100, minWidth: 50},
    {key: 'product_type', title: self.$t('Product Type'), width: 200, minWidth: 150},
    {key: 'pricing_type', title: self.$t('Pricing Type'), width: 200, minWidth: 150},
    {key: 'payment_type', title: self.$t('Payment Type'), width: 120, minWidth: 100},
    {key: 'interval', title: self.$t('Interval'), width: 120, minWidth: 80},
    {key: 'trial_days', title: self.$t('Trial Days'), width: 100, minWidth: 70},
    {key: 'best_price', title: self.$t('Best Price'), width: 100, minWidth: 100},
    {key: 'compare_price', title: self.$t('Compare-at Price'), width: 200, minWidth: 150},
    {key: 'track_quantity', title: self.$t('Track Quantity'), width: 100, minWidth: 100},
    {key: 'stock', title: self.$t('Stock'), width: 150, minWidth: 150},
    {key: 'actions', title: '', width: 110, minWidth: 110},
  ]

  bulkInsetModel.restoreColumnWidths(columns.value);
  bulkInsetModel.setupColumnVisibility();

  // Fetch categories for picker
  bulkInsetModel.fetchCategories();

  document.body.classList.add('fct-bulk-page');

  window.addEventListener('keydown', onShiftDown);
  window.addEventListener('keyup', onShiftUp);
})

onBeforeUnmount(() => {
  document.body.classList.remove('fct-bulk-page');
  window.removeEventListener('keydown', onShiftDown);
  window.removeEventListener('keyup', onShiftUp);
  if (keyboard) {
    keyboard.reset();
  }
})

</script>

<template>
  <div class="fct-bulk-insert-page fct-layout-width">
    <PageHeading :title="$t('Bulk Insert')">
      <template #action>
        <Importer @on-data-populated="onDataPopulated"/>
        <el-button @click="insertDummyProduct">{{ $t('Add Product') }}</el-button>
        <el-button
          type="primary"
          @click="saveProducts"
          :loading="saving"
          :disabled="saving || products.length === 0"
        >
          {{ saving ? $t('Saving %s/%s...').replace('%s', saveProgress.completed).replace('%s', saveProgress.total) : $t('Save All Products') }}
        </el-button>
      </template>
    </PageHeading>

    <el-progress v-if="saving" :percentage="progressPercentage" :stroke-width="6" class="mb-4" />

    <Card.Container>
      <Card.Header
          v-if="products.length > 0"
          :title="products?.length === 1 ? $t('Product') : $t('Products')"
          border_bottom
      >
        <template #action>
          <div class="fct-btn-group sm justify-end">
            <el-button
                :disabled="selectedCount === 0"
                @click="bulkDelete"
                size="small"
                type="danger"
                soft
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              {{ $t('Delete') }}
            </el-button>

            <el-button
                :disabled="selectedCount === 0"
                @click="bulkDuplicate"
                size="small"
                type="info"
                soft
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              {{ $t('Duplicate') }}
            </el-button>

            <el-button
                :class="{ 'is-active': isLinkMode }"
                :disabled="selectedCount < 2 && !isLinkMode"
                @click="toggleLinkMode"
                size="small"
                type="info"
                soft
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
              {{ isLinkMode ? $t('Unlink') : $t('Link') }}
            </el-button>

            <el-checkbox v-model="stickyActions" :label="$t('Pin Actions')" />

            <el-popover trigger="click" placement="bottom-end" width="240" popper-class="filter-popover">
              <div class="filter-popover-item">
                <h3 class="filter-popover-title">{{ $t('Columns') }}</h3>
                <el-checkbox-group
                    v-model="bulkInsetModel.data.visibleColumns"
                    class="fct-checkbox-blocks"
                    @change="bulkInsetModel.handleColumnVisibilityChange()"
                >
                  <el-checkbox
                      v-for="col in bulkInsetModel.getToggleableColumns()"
                      :key="col.value"
                      :value="col.value"
                      :label="$t(col.label)"
                  />
                </el-checkbox-group>
              </div>
              <template #reference>
              <span>
                <el-tooltip effect="light" :content="$t('Toggle columns')" placement="top" popper-class="fct-tooltip">
                  <IconButton tag="button" size="small">
                    <DynamicIcon name="ColumnIcon"/>
                  </IconButton>
                </el-tooltip>
              </span>
              </template>
            </el-popover>
          </div>
        </template>
      </Card.Header>

      <Card.Body class="p-0">
        <!-- Table with data -->
        <div class="relative" :class="{ 'is-link-mode': isLinkMode, 'is-shift-held': isShiftHeld }" v-if="products.length > 0">
          <ResizeableColumns :columns="filteredColumns" :sticky-last="stickyActions" @scroll="onScroll" @resize-end="onColumnResizeEnd">
            <template #header-first>
              <el-checkbox
                  :model-value="selectedCount > 0 && selectedCount === flatVisibleKeys.length"
                  :indeterminate="selectedCount > 0 && selectedCount < flatVisibleKeys.length"
                  @change="toggleSelectAll(flatVisibleKeys)"
              >
              </el-checkbox>
            </template>
            <template #header-last>
              {{ $t('Actions') }}
            </template>

            <!-- Top spacer -->
            <tr v-if="topSpacerHeight > 0" aria-hidden="true">
              <td :colspan="filteredColumns.length + 1" :style="{ height: topSpacerHeight + 'px', padding: 0, border: 'none' }"></td>
            </tr>

            <template v-for="group in visibleGroups" :key="group.productId">

              <!-- Product row -->
              <tr class="bulk-product-row" :class="{ 'is-saving': isSaving(group.product), 'is-saved': isSaved(group.product), 'has-product-error': hasError(group.product), 'is-selected': isSelected(`p:${group.productId}`) }" @click="onRowShiftClick(`p:${group.productId}`, $event)">
                <td class="bulk-checkbox-cell" @click="handleRowClick(`p:${group.productId}`, $event, flatVisibleKeys)">
                  <el-checkbox
                      :model-value="isSelected(`p:${group.productId}`)"
                      tabindex="-1"
                  />
                </td>

                <!-- title -->
                <td class="fct-bulk-product-title-cell sticky-col" :style="{ left: '40px' }">
                  <div class="flex items-center">
                    <!-- Collapse btn -->
                    <button
                        v-if="group.hasVariants"
                        class="bulk-collapse-btn"
                        @click.prevent="toggleCollapse(group.product)"
                        :title="isCollapsed(group.product) ? $t('Expand variants') : $t('Collapse variants')"
                    >
                      <svg :class="['bulk-collapse-icon', { 'is-collapsed': isCollapsed(group.product) }]" width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M6 4L10 8L6 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                      </svg>

                      <span v-if="isCollapsed(group.product)" class="bulk-collapse-count">{{ group.variantCount }}</span>
                    </button>

                    <!-- input -->
                    <el-input
                        class="fct-bulk-input"
                        :class="{ 'is-error': hasFieldError(group.product, 'post_title') }"
                        v-model="group.product.post_title"
                        :placeholder="$t('Product title')"
                        size="small"
                        :disabled="isRowDisabled(group.product)"
                        @input="(val) => onSync('title', val, `p:${group.productId}`)"
                    />

                    <!-- Syncing icon while saving -->
                    <span v-if="isSaving(group.product)" class="bulk-status-icon is-syncing" :title="$t('Saving...')">
                      <el-icon class="is-loading" :size="14"><Loading /></el-icon>
                    </span>

                    <!-- Saved icon -->
                    <span v-else-if="isSaved(group.product)" class="bulk-status-icon is-saved" :title="$t('Saved')">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                        <path d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm-.997-6l7.07-7.071-1.414-1.414-5.656 5.657-2.829-2.829-1.414 1.414L11.003 16z"/>
                      </svg>
                    </span>

                    <!-- Error icon — product-level errors, or any error for simple products (no variant rows) -->
                    <el-tooltip v-else-if="hasProductLevelError(group.product) || (isSimple(group.product) && hasError(group.product))" :content="getError(group.product)" placement="top">
                      <span class="bulk-status-icon is-error">
                        <el-icon :size="16"><WarningFilled /></el-icon>
                      </span>
                    </el-tooltip>
                  </div>
                </td>

                <!-- sku -->
                <td class="fct-bulk-sku-cell" v-if="isColVisible('sku')">
                  <el-input
                      v-if="group.product?.detail?.variation_type === 'simple' && group.product.variants?.[0]"
                      class="fct-bulk-input"
                      :class="{ 'is-error': hasFieldError(group.product, 'variants.0.sku') }"
                      v-model="group.product.variants[0].sku"
                      placeholder="SKU"
                      size="small"
                      :disabled="isRowDisabled(group.product)"
                      @update:modelValue="(val) => onSync('sku', val, `p:${group.productId}`)"
                  />

                  <span v-else class="text-gray-300 text-sm flex justify-center">—</span>
                </td>

                <!-- media -->
                <td class="fct-bulk-media-cell" v-if="isColVisible('media')">
                  <BulkMediaPicker v-model="group.product.gallery" show-url-tab :disabled="isRowDisabled(group.product)" />
                </td>

                <!-- categories -->
                <td class="fct-bulk-categories-cell" v-if="isColVisible('categories')">
                  <BulkCategorySelect
                      v-model="group.product.categories"
                      :options="bulkInsetModel.data.categoryOptions"
                      :disabled="isRowDisabled(group.product)"
                      @change="(val) => { onCategoryChange(group.product, val); onSync('categories', group.product.categories, `p:${group.productId}`); }"
                  />
                </td>

                <!-- desc -->
                <td class="fct-bulk-desc-cell" v-if="isColVisible('description')">
                  <BulkEditorCell :value="group.product.post_content" :placeholder="$t('Description')" @click="openEditor(group.product, 'post_content')" />
                </td>

                <!-- short desc -->
                <td class="fct-bulk-short-desc-cell" v-if="isColVisible('short_description')">
                  <BulkEditorCell :value="group.product.post_excerpt" :placeholder="$t('Short description')" @click="openEditor(group.product, 'post_excerpt')" />
                </td>

                <!-- status -->
                <td class="fct-bulk-status-cell" v-if="isColVisible('status')">
                  <el-select v-model="group.product.post_status" size="small" :class="{ 'is-field-error': hasFieldError(group.product, 'post_status') }" :disabled="isRowDisabled(group.product)" @change="onSync('status', group.product.post_status, `p:${group.productId}`)">
                    <el-option
                        v-for="option in options.status"
                        :key="option.value"
                        :label="$t(option.title)"
                        :value="option.value"
                    />
                  </el-select>
                </td>

                <!-- product type -->
                <td class="fct-bulk-product-type-cell" v-if="isColVisible('product_type') && group.product?.detail">
                  <el-select v-model="group.product.detail.fulfillment_type" size="small" :class="{ 'is-field-error': hasFieldError(group.product, 'detail.fulfillment_type') }" :disabled="isRowDisabled(group.product)" @change="onSync('product_type', group.product.detail.fulfillment_type, `p:${group.productId}`)">
                    <el-option
                        v-for="option in options.fulfilment"
                        :key="option.value"
                        :label="$t(option.title)"
                        :value="option.value"
                    />
                  </el-select>
                </td>

                <!-- product type -->
                <td class="fct-bulk-product-type-cell" v-else-if="isColVisible('product_type')" />

                <!-- pricing type -->
                <td class="fct-bulk-pricing-type-cell" v-if="isColVisible('pricing_type') && group.product?.detail">
                  <el-select v-model="group.product.detail.variation_type" size="small" :class="{ 'is-field-error': hasFieldError(group.product, 'detail.variation_type') }" :disabled="isRowDisabled(group.product)" @change="(variationType)=>{
                  bulkInsetModel.handleVariationChanged(group.product, variationType);
                  onSync('pricing_type', variationType, `p:${group.productId}`);
                }">
                    <el-option
                        v-for="option in options.variation"
                        :key="option.value"
                        :label="$t(option.title)"
                        :value="option.value"
                    />
                  </el-select>
                </td>

                <!-- pricing type -->
                <td class="fct-bulk-pricing-type-cell" v-else-if="isColVisible('pricing_type')" />

                <!-- payment type -->
                <td class="fct-bulk-payment-type-cell" v-if="isColVisible('payment_type')">
                  <BulkPaymentTypeCell
                      :variant="isSimple(group.product) ? group.product.variants?.[0] : null"
                      :disabled="isRowDisabled(group.product)"
                      :has-error="hasFieldError(group.product, 'variants.0.other_info.payment_type')"
                      @payment-type-change="(v) => { onPaymentTypeChange(v); onSync('payment_type', v.other_info?.payment_type, `p:${group.productId}`); }"
                  />
                </td>

                <!-- interval -->
                <td class="fct-bulk-interval-cell" v-if="isColVisible('interval')">
                  <BulkIntervalSelect
                      :variant="isSimple(group.product) ? group.product.variants?.[0] : null"
                      :disabled="isRowDisabled(group.product)"
                      :has-error="hasFieldError(group.product, 'variants.0.other_info.repeat_interval')"
                      @change="onSync('interval', group.product.variants?.[0]?.other_info?.repeat_interval, `p:${group.productId}`)"
                  />
                </td>

                <!-- trial days -->
                <td class="fct-bulk-trial-days-cell" v-if="isColVisible('trial_days')">
                  <BulkTrialDaysInput
                      :variant="isSimple(group.product) ? group.product.variants?.[0] : null"
                      :disabled="isRowDisabled(group.product)"
                      :has-error="hasFieldError(group.product, 'variants.0.other_info.trial_days')"
                      @change="onSync('trial_days', group.product.variants?.[0]?.other_info?.trial_days, `p:${group.productId}`)"
                  />
                </td>

                <!-- best price -->
                <td class="fct-bulk-best-price-cell" v-if="isColVisible('best_price')">
                  <BulkPriceInput
                      :variant="isSimple(group.product) ? group.product.variants?.[0] : null"
                      :disabled="isRowDisabled(group.product)"
                      :has-error="hasFieldError(group.product, 'variants.0.item_price')"
                      @change="onSync('price', group.product.variants?.[0]?.item_price, `p:${group.productId}`)"
                  />
                </td>

                <!-- compare price -->
                <td class="fct-bulk-compare-price-cell" v-if="isColVisible('compare_price')">
                  <BulkComparePriceInput
                      :variant="isSimple(group.product) ? group.product.variants?.[0] : null"
                      :disabled="isRowDisabled(group.product)"
                      :has-error="hasFieldError(group.product, 'variants.0.compare_price')"
                      @change="onSync('compare_price', group.product.variants?.[0]?.compare_price, `p:${group.productId}`)"
                  />
                </td>

                <!-- quantity -->
                <td class="fct-bulk-qty-cell !pl-5" v-if="isColVisible('track_quantity')">
                  <el-switch v-if="group.product?.detail" v-model="group.product.detail.manage_stock" active-value="1" inactive-value="0" :disabled="isRowDisabled(group.product)" @change="bulkInsetModel.syncStockStatus(group.product); onSync('manage_stock', group.product.detail.manage_stock, `p:${group.productId}`)" />
                </td>

                <!-- stock -->
                <td class="fct-bulk-stock-cell" v-if="isColVisible('stock')">
                  <el-input
                      v-if="group.product?.detail?.manage_stock === '1' && group.product?.detail?.variation_type === 'simple'"
                      v-model="group.product.detail.total_stock"
                      :disabled="isRowDisabled(group.product)"
                      size="small"
                      :placeholder="$t('e.g 10')"
                      @update:modelValue="(val) => {
                        if (group.product.variants?.[0]) {
                          group.product.variants[0].total_stock = val;
                          group.product.variants[0].available = val;
                        }
                        bulkInsetModel.syncStockStatus(group.product);
                        onSync('stock', val, `p:${group.productId}`);
                      }"
                  />

                  <span v-else class="text-gray-300 text-sm pl-5">—</span>
                </td>

                <!-- actions -->
                <td class="fct-bulk-actions-cell" :class="{ 'sticky-col sticky-col-right': stickyActions }">
                  <div class="fct-btn-group xs justify-end">
                    <!-- view product -->
                    <a v-if="isSaved(group.product) && group.product.view_url" :href="group.product.view_url" target="_blank" class="bulk-action-btn bulk-view-link" :title="$t('View Product')">
                      <DynamicIcon name="External" />
                    </a>

                    <!-- duplicate -->
                    <icon-button
                        tag="button"
                        size="x-small"
                        @click.prevent="duplicateProduct(group.product)"
                        :disabled="saving || isRowDisabled(group.product)"
                        :title="$t('Duplicate')"
                    >
                      <DynamicIcon name="Copy" />
                    </icon-button>

                    <!-- delete -->
                    <icon-button
                        tag="button"
                        size="x-small"
                        hover="danger"
                        @click.prevent="removeProduct(group.product)"
                        :disabled="saving || isRowDisabled(group.product)"
                        :title="$t('Delete')"
                    >
                      <DynamicIcon name="Delete" />
                    </icon-button>
                  </div>
                </td>
              </tr>

              <!-- Variants block — smooth accordion animation -->
              <tr v-if="group.hasVariants" class="bulk-variants-block-row">
                <td :colspan="filteredColumns.length + 1" class="!p-0 !border-b-0">
                  <Animation accordion :visible="!group.collapsed" :duration="200">
                    <table class="bulk-inner-table" :style="{ tableLayout: 'fixed', width: '100%', borderCollapse: 'collapse' }">
                      <colgroup>
                        <col :style="{ width: '40px' }" />
                        <col v-for="(col, i) in filteredColumns" :key="i" :style="{ width: col.width + 'px' }" />
                      </colgroup>
                      <tbody>

                      <tr v-for="(variant, vi) in group.product.variants" :key="vi" class="bulk-variation-row" :class="{ 'is-selected': isSelected(`v:${group.productId}:${vi}`) }" @click="onRowShiftClick(`v:${group.productId}:${vi}`, $event)">

                        <td class="bulk-checkbox-cell">
                          <el-checkbox
                              :model-value="isSelected(`v:${group.productId}:${vi}`)"
                              tabindex="-1"
                              @change="(checked) => handleRowClick(
                                `v:${group.productId}:${vi}`,
                                checked,
                                flatVisibleKeys
                              )"
                          />
                        </td>

                        <td class="fct-bulk-product-title-cell sticky-col" :style="{ left: '40px' }">
                          <div class="flex items-center gap-1 pl-4">
                            <span class="text-gray-300 text-xs">└</span>

                            <el-input
                                size="small"
                                v-model="variant.variation_title"
                                :placeholder="$t('Variation title')"
                                :disabled="isRowDisabled(group.product)"
                                :class="{ 'is-error': hasVariantFieldError(group.product, vi, 'variation_title') }"
                                @update:modelValue="(val) => onSync('title', val, `v:${group.productId}:${vi}`)"
                            />

                            <el-tooltip v-if="hasVariantError(group.product, vi)" :content="getVariantError(group.product, vi)" placement="top">
                                <span class="bulk-status-icon is-error">
                                  <el-icon :size="14"><WarningFilled /></el-icon>
                                </span>
                            </el-tooltip>
                          </div>
                        </td>

                        <td class="fct-bulk-sku-cell" v-if="isColVisible('sku')">
                          <el-input
                              size="small"
                              v-model="variant.sku"
                              placeholder="SKU"
                              :disabled="isRowDisabled(group.product)"
                              :class="{ 'is-error': hasVariantFieldError(group.product, vi, 'sku') }"
                              @update:modelValue="(val) => onSync('sku', val, `v:${group.productId}:${vi}`)"
                          />
                        </td>

                        <td class="fct-bulk-media-cell" v-if="isColVisible('media')">
                          <BulkMediaPicker v-model="variant.media" show-url-tab :disabled="isRowDisabled(group.product)" />
                        </td>

                        <td v-if="isColVisible('categories')"><span class="text-gray-300 text-sm">—</span></td>
                        <td v-if="isColVisible('description')"><span class="text-gray-300 text-sm">—</span></td>
                        <td v-if="isColVisible('short_description')"><span class="text-gray-300 text-sm">—</span></td>
                        <td v-if="isColVisible('status')"><span class="text-gray-300 text-sm">—</span></td>
                        <td v-if="isColVisible('product_type')"><span class="text-gray-300 text-sm">—</span></td>
                        <td v-if="isColVisible('pricing_type')"><span class="text-gray-300 text-sm">—</span></td>

                        <td v-if="isColVisible('payment_type')">
                          <BulkPaymentTypeCell
                              :variant="variant"
                              :disabled="isRowDisabled(group.product)"
                              :has-error="hasVariantFieldError(group.product, vi, 'other_info.payment_type')"
                              @payment-type-change="(v) => { onPaymentTypeChange(v); onSync('payment_type', v.other_info?.payment_type, `v:${group.productId}:${vi}`); }"
                          />
                        </td>

                        <td v-if="isColVisible('interval')">
                          <BulkIntervalSelect
                              :variant="variant"
                              :disabled="isRowDisabled(group.product)"
                              :has-error="hasVariantFieldError(group.product, vi, 'other_info.repeat_interval')"
                              @change="onSync('interval', variant.other_info?.repeat_interval, `v:${group.productId}:${vi}`)"
                          />
                        </td>

                        <td v-if="isColVisible('trial_days')">
                          <BulkTrialDaysInput
                              :variant="variant"
                              :disabled="isRowDisabled(group.product)"
                              :has-error="hasVariantFieldError(group.product, vi, 'other_info.trial_days')"
                              @change="onSync('trial_days', variant.other_info?.trial_days, `v:${group.productId}:${vi}`)"
                          />
                        </td>

                        <td v-if="isColVisible('best_price')">
                          <BulkPriceInput
                              :variant="variant"
                              :disabled="isRowDisabled(group.product)"
                              :has-error="hasVariantFieldError(group.product, vi, 'item_price')"
                              @change="onSync('price', variant.item_price, `v:${group.productId}:${vi}`)"
                          />
                        </td>

                        <td v-if="isColVisible('compare_price')">
                          <BulkComparePriceInput
                              :variant="variant"
                              :disabled="isRowDisabled(group.product)"
                              :has-error="hasVariantFieldError(group.product, vi, 'compare_price')"
                              @change="onSync('compare_price', variant.compare_price, `v:${group.productId}:${vi}`)"
                          />
                        </td>

                        <td v-if="isColVisible('track_quantity')" class="text-center">
                          <span class="text-gray-300 text-sm">—</span>
                        </td>

                        <td v-if="isColVisible('stock')">
                          <el-input
                              v-if="group.product?.detail?.manage_stock === '1'"
                              class="fc-bulk-input"
                              size="small"
                              v-model="variant.total_stock"
                              :disabled="isRowDisabled(group.product)"
                              :placeholder="$t('e.g 10')"
                              @update:modelValue="(val) => {
                                variant.available = val;
                                bulkInsetModel.syncStockStatus(group.product);
                                onSync('stock', val, `v:${group.productId}:${vi}`);
                              }"
                          />
                          <span v-else class="text-gray-300 text-sm flex justify-center">—</span>
                        </td>

                        <td class="fct-bulk-actions-cell" :class="{ 'sticky-col sticky-col-right': stickyActions }">
                          <div class="fct-btn-group sm justify-end">
                            <icon-button
                                tag="button"
                                size="x-small"
                                @click.prevent="duplicateVariant(group.product, variant)"
                                :disabled="saving || isRowDisabled(group.product)"
                                :title="$t('Duplicate')"
                            >
                              <DynamicIcon name="Copy" />
                            </icon-button>

                            <icon-button
                                tag="button"
                                size="x-small"
                                hover="danger"
                                @click.prevent="removeVariant(group.product, variant)"
                                :disabled="saving || isRowDisabled(group.product)"
                                :title="$t('Delete')"
                            >
                              <DynamicIcon name="Delete" />
                            </icon-button>
                          </div>
                        </td>
                      </tr>

                      <tr class="bulk-add-variant-row">
                        <td :colspan="filteredColumns.length + 1">
                          <div class="sticky left-0 inline-block pl-6">
                            <el-button size="small" text type="primary" @click.prevent="bulkInsetModel.addVariationToProduct(group.product)">
                              + {{ $t('Add Variant') }}
                            </el-button>
                          </div>
                        </td>
                      </tr>
                      </tbody>
                    </table>
                  </Animation>
                </td>
              </tr>

            </template>

            <!-- Bottom spacer -->
            <tr v-if="bottomSpacerHeight > 0" aria-hidden="true">
              <td :colspan="filteredColumns.length + 1" :style="{ height: bottomSpacerHeight + 'px', padding: 0, border: 'none' }"></td>
            </tr>

          </ResizeableColumns>
        </div>

        <!-- Empty state -->
        <Empty
            v-else
            icon="Empty/ListView"
            :has-dark="true"
            :text="translate('No products yet. Import a CSV or add products manually.')"
        />
      </Card.Body>
    </Card.Container>

    <!-- WpEditor Modal -->
    <el-dialog
      v-model="editorModal.visible"
      :title="editorTitle"
      width="700px"
      :close-on-click-modal="false"
      @closed="editorModal.product = null"
    >
      <WpEditor
        v-if="editorModal.visible && editorModal.field === 'post_content'"
        :model-value="editorModal.value"
        :height="300"
        @update="onEditorUpdate"
      />
      <el-input
        v-if="editorModal.visible && editorModal.field === 'post_excerpt'"
        v-model="editorModal.value"
        type="textarea"
        :rows="6"
        :placeholder="$t('Short description')"
      />
      <template #footer>
        <el-button @click="editorModal.visible = false">{{ $t('Cancel') }}</el-button>
        <el-button type="primary" @click="saveEditor">{{ $t('Save') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>
