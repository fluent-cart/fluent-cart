<script setup>
import '@/Modules/Products/_bulk-table.scss';
import {computed, getCurrentInstance, nextTick, onMounted, onBeforeUnmount, ref, useTemplateRef} from "vue";
import bulkEditModel from "@/Models/BulkEditModel";
import ResizeableColumns from "@/Modules/Products/BulkInsert/ResizeableColumns.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import Animation from "@/Bits/Components/Animation.vue";
import AdvancedFilter from "@/Bits/Components/TableNew/Components/AdvancedFilter/AdvancedFilter.vue";
import FilterTabs from "@/Bits/Components/TableNew/FilterTabs.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import BulkPriceInput from "@/Modules/Products/BulkShared/BulkPriceInput.vue";
import BulkPaymentTypeCell from "@/Modules/Products/BulkShared/BulkPaymentTypeCell.vue";
import BulkIntervalSelect from "@/Modules/Products/BulkShared/BulkIntervalSelect.vue";
import BulkTrialDaysInput from "@/Modules/Products/BulkShared/BulkTrialDaysInput.vue";
import BulkComparePriceInput from "@/Modules/Products/BulkShared/BulkComparePriceInput.vue";
import BulkCategorySelect from "@/Modules/Products/BulkShared/BulkCategorySelect.vue";
import BulkEditorCell from "@/Modules/Products/BulkShared/BulkEditorCell.vue";
import BulkMediaPicker from "@/Bits/Components/Attachment/BulkMediaPicker.vue";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import {Loading, WarningFilled} from "@element-plus/icons-vue";
import {ElMessageBox} from "element-plus";
import {useBulkSelection} from "@/Modules/Products/BulkShared/useBulkSelection";
import {vIndeterminate} from "@/Modules/Products/BulkShared/vIndeterminate";
import {syncLinkedField, LINK_COLUMNS_EDIT} from "@/Modules/Products/BulkShared/bulkLinkColumns";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import * as Card from "@/Bits/Components/Card/Card.js";

const self = getCurrentInstance().ctx;

const {options} = bulkEditModel.data;
const isSimple = (product) => product?.detail?.variation_type === 'simple';

const products = computed(() => bulkEditModel.data.products);
const loading = computed(() => bulkEditModel.data.loading);
const saving = computed(() => bulkEditModel.data.saving);
const saveProgress = computed(() => bulkEditModel.data.saveProgress);
const pagination = computed(() => bulkEditModel.data.pagination);
const dirtySet = computed(() => bulkEditModel.data.dirty);

const dirtyCount = computed(() => dirtySet.value.size);

const remainingCount = computed(() => {
  return Math.max(0, pagination.value.total - products.value.length);
});

const nextPageCount = computed(() => {
  return Math.min(pagination.value.per_page, remainingCount.value);
});

const progressPercentage = computed(() => {
  const progress = bulkEditModel.data.saveProgress;
  if (!progress.total) return 0;
  return Math.round((progress.completed / progress.total) * 100);
});

const inputRef = useTemplateRef('search-input');

const openSearch = () => {
  bulkEditModel.openSearch();
  nextTick(() => {
    inputRef.value?.focus();
  });
};

const loadProducts = (append = false) => {
  bulkEditModel.fetchProducts({}, append);
};

const saveProducts = () => {
  bulkEditModel.saveProducts();
};

const markDirty = (product) => {
  bulkEditModel.markDirty(product.ID);
};

const onPaymentTypeChange = (product, variant) => {
  bulkEditModel.resetPaymentTypeDefaults(variant);
  markDirty(product);
};

const onCategoryChange = (product, values) => {
  // Add newly created categories to the options list so they appear for other products
  const options = bulkEditModel.data.categoryOptions;
  for (const val of values) {
    if (!options.find(o => o.value === val)) {
      options.push({ label: val, value: val });
    }
  }
  markDirty(product);
};

// Status checks — thin delegates to model
const isDirty = (product) => bulkEditModel.isDirty(product.ID);
const isSaving = (product) => bulkEditModel.isSavingProduct(product.ID);
const isSaved = (product) => bulkEditModel.isSaved(product.ID);
const isRowDisabled = (product) => bulkEditModel.isRowDisabled(product.ID);
const isDuplicating = (product) => bulkEditModel.isDuplicating(product.ID);
const isDeleting = (product) => bulkEditModel.isDeleting(product.ID);

// Error checks — thin delegates to model
const hasError = (product) => bulkEditModel.hasError(product.ID);
const getError = (product) => bulkEditModel.getError(product.ID);
const hasProductLevelError = (product) => bulkEditModel.hasProductLevelError(product.ID);
const hasVariantError = (product, vi) => bulkEditModel.hasVariantError(product.ID, vi);
const getVariantError = (product, vi) => bulkEditModel.getVariantError(product.ID, vi);
const hasFieldError = (product, field) => bulkEditModel.hasFieldError(product.ID, field);
const hasVariantFieldError = (product, vi, field) => bulkEditModel.hasVariantFieldError(product.ID, vi, field);

const saveProduct = (product) => bulkEditModel.saveProduct(product);
const duplicateProduct = (product) => bulkEditModel.duplicateProduct(product);
const duplicateVariant = (product, variant) => bulkEditModel.duplicateVariant(product, variant);

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
      for (const variant of group.product.variants) {
        keys.push(`v:${variant.id}`);
      }
    }
  }
  return keys;
});

const getRowByKey = (key) => {
  if (key.startsWith('p:')) {
    const id = parseInt(key.slice(2));
    const group = productGroups.value.find(g => g.productId === id);
    return group ? { type: 'product', data: group.product, product: group.product } : null;
  } else if (key.startsWith('v:')) {
    const id = parseInt(key.slice(2));
    for (const group of productGroups.value) {
      const variant = group.product.variants?.find(v => v.id === id);
      if (variant) return { type: 'variant', data: variant, product: group.product };
    }
  }
  return null;
};

const onSync = (columnKey, value, rowKey) => {
  if (!isLinkMode.value || !isSelected(rowKey)) return;
  syncLinkedField(columnKey, value, rowKey, selectedKeys.value, getRowByKey, markDirty, LINK_COLUMNS_EDIT);
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

// ── Bulk duplicate ────────────────────────────────────────────
const categorizeSelection = () => {
  const result = [];
  for (const group of productGroups.value) {
    const productKey = `p:${group.productId}`;
    const productSelected = selectedKeys.value.has(productKey);

    const selectedVariantIds = [];
    if (group.product.variants) {
      for (const v of group.product.variants) {
        if (selectedKeys.value.has(`v:${v.id}`)) {
          selectedVariantIds.push(v.id);
        }
      }
    }

    if (!productSelected && selectedVariantIds.length === 0) continue;

    if (productSelected && selectedVariantIds.length === 0) {
      result.push({ product: group.product, type: 'full' });
    } else if (!productSelected && selectedVariantIds.length > 0) {
      result.push({ product: group.product, type: 'variants-only', variantIds: selectedVariantIds });
    } else {
      result.push({ product: group.product, type: 'partial', variantIds: selectedVariantIds });
    }
  }
  return result;
};

const bulkDuplicate = async () => {
  const items = categorizeSelection();
  if (!items.length) return;

  const fullCount = items.filter(i => i.type === 'full').length;
  const partialCount = items.filter(i => i.type === 'partial').length;
  const variantOnlyItems = items.filter(i => i.type === 'variants-only');
  const variantOnlyCount = variantOnlyItems.reduce((sum, i) => sum + i.variantIds.length, 0);

  const parts = [];
  if (fullCount > 0) parts.push(`${fullCount} product(s) with all variants`);
  if (partialCount > 0) parts.push(`${partialCount} product(s) with selected variants`);
  if (variantOnlyCount > 0) parts.push(`${variantOnlyCount} variant(s)`);

  try {
    await ElMessageBox.confirm(
      self.$t('Duplicate %s?').replace('%s', parts.join(', ')),
      self.$t('Bulk Duplicate'),
      {
        confirmButtonText: self.$t('Duplicate'),
        cancelButtonText: self.$t('Cancel'),
        type: 'info',
      }
    );
  } catch {
    return;
  }

  for (const item of items) {
    if (item.type === 'full') {
      await bulkEditModel.duplicateProduct(item.product);
    } else if (item.type === 'variants-only') {
      for (const vid of item.variantIds) {
        const variant = item.product.variants.find(v => v.id === vid);
        if (variant) bulkEditModel.duplicateVariant(item.product, variant);
      }
    } else if (item.type === 'partial') {
      await bulkEditModel.duplicateProductWithVariants(item.product, item.variantIds);
    }
  }

  deselectAll();
};

const bulkDelete = async () => {
  const productsToDelete = [];
  const variantsToRemove = [];

  for (const group of productGroups.value) {
    const productKey = `p:${group.productId}`;
    const productSelected = selectedKeys.value.has(productKey);

    if (productSelected) {
      productsToDelete.push(group.product);
      continue;
    }

    const selectedVariants = [];
    if (group.product.variants) {
      for (const v of group.product.variants) {
        if (selectedKeys.value.has(`v:${v.id}`)) {
          selectedVariants.push(v);
        }
      }
    }

    if (selectedVariants.length > 0) {
      variantsToRemove.push({ product: group.product, variants: selectedVariants });
    }
  }

  if (!productsToDelete.length && !variantsToRemove.length) return;

  const variantCount = variantsToRemove.reduce((sum, i) => sum + i.variants.length, 0);
  const parts = [];
  if (productsToDelete.length > 0) parts.push(`${productsToDelete.length} product(s)`);
  if (variantCount > 0) parts.push(`${variantCount} variant(s)`);

  try {
    await ElMessageBox.confirm(
      self.$t('Permanently delete %s? This cannot be undone.').replace('%s', parts.join(' and ')),
      self.$t('Bulk Delete'),
      {
        confirmButtonText: self.$t('Delete'),
        cancelButtonText: self.$t('Cancel'),
        type: 'warning',
      }
    );
  } catch {
    return;
  }

  for (const product of productsToDelete) {
    await bulkEditModel.deleteProduct(product);
  }

  for (const { product, variants } of variantsToRemove) {
    for (const variant of variants) {
      bulkEditModel.removeVariant(product, variant);
    }
  }

  deselectAll();
};

const deleteProduct = async (product) => {
  const productId = product.ID;
  if (!productId || bulkEditModel.isDeleting(productId)) return;

  const title = product.post_title || self.$t('Untitled product');
  const variantCount = Array.isArray(product.variants) ? product.variants.length : 0;
  const message = variantCount > 0
    ? self.$t('This will permanently delete "%s" and its %s variant(s).').replace('%s', title).replace('%s', variantCount)
    : self.$t('This will permanently delete "%s".').replace('%s', title);

  try {
    await ElMessageBox.confirm(message, self.$t('Delete Product'), {
      confirmButtonText: self.$t('Delete'),
      cancelButtonText: self.$t('Cancel'),
      type: 'warning',
    });
  } catch {
    return;
  }

  bulkEditModel.deleteProduct(product);
};

const removeVariant = (product, variant) => {
  if (product.variants.length <= 1) {
    bulkEditModel.removeVariant(product, variant);
    return;
  }

  const title = variant.variation_title || self.$t('Untitled variant');
  ElMessageBox.confirm(
    self.$t('This will remove the variant "%s".').replace('%s', title),
    self.$t('Remove Variant'),
    {
      confirmButtonText: self.$t('Remove'),
      cancelButtonText: self.$t('Cancel'),
      type: 'warning',
    }
  ).then(() => {
    bulkEditModel.removeVariant(product, variant);
  }).catch(() => {});
};

// Editor modal state
const editorModal = ref({
  visible: false,
  field: '',    // 'post_content' or 'post_excerpt'
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
    markDirty(product);
  }
  editorModal.value.visible = false;
};

const editorTitle = computed(() => {
  return editorModal.value.field === 'post_content'
    ? self.$t('Edit Description')
    : self.$t('Edit Short Description');
});

const columns = ref([]);

const filteredColumns = computed(() => {
  return columns.value.filter(col => {
    if (col.key === 'title' || col.key === 'actions') return true;
    return bulkEditModel.isColumnVisible(col.key);
  });
});

const isColVisible = (key) => bulkEditModel.isColumnVisible(key);

const onColumnResizeEnd = () => {
  bulkEditModel.saveColumnWidths(columns.value);
};

// Collapse state
const collapsedProducts = ref(new Set());

const toggleCollapse = (product) => {
  const id = product.ID;
  if (collapsedProducts.value.has(id)) {
    collapsedProducts.value.delete(id);
  } else {
    collapsedProducts.value.add(id);
  }
  collapsedProducts.value = new Set(collapsedProducts.value);
};

const isCollapsed = (product) => {
  return collapsedProducts.value.has(product.ID);
};

// Virtual scrolling
const ROW_HEIGHT = 43;
const BUFFER = 10;
const scrollTop = ref(0);
const containerHeight = 800;

const productGroups = computed(() => {
  let offset = 0;
  return products.value.map(product => {
    const productId = product.ID;
    const hasVariants = product.detail?.variation_type !== 'simple' && Array.isArray(product.variants) && product.variants.length > 0;
    const variantCount = hasVariants ? product.variants.length : 0;
    const collapsed = collapsedProducts.value.has(productId);
    const variantsHeight = hasVariants && !collapsed ? variantCount * ROW_HEIGHT : 0;
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

const INFINITE_SCROLL_THRESHOLD = 200;

const checkInfiniteScroll = () => {
  if (loading.value || !pagination.value.hasMore || products.value.length === 0) return;
  const distanceFromBottom = totalHeight.value - (scrollTop.value + containerHeight);
  if (distanceFromBottom < INFINITE_SCROLL_THRESHOLD) {
    loadProducts(true);
  }
};

const onScroll = (e) => {
  const target = e.target;
  const now = Date.now();

  if (scrollTimer) clearTimeout(scrollTimer);

  if (now - lastScrollUpdate >= SCROLL_THROTTLE) {
    scrollTop.value = target.scrollTop;
    lastScrollUpdate = now;
    checkInfiniteScroll();
  } else {
    scrollTimer = setTimeout(() => {
      scrollTop.value = target.scrollTop;
      lastScrollUpdate = Date.now();
      scrollTimer = null;
      checkInfiniteScroll();
    }, SCROLL_THROTTLE);
  }
};

// Label helpers
const fulfillmentLabel = (type) => {
  const map = { physical: 'Physical', digital: 'Digital' };
  return map[type] || type || '—';
};

const variationTypeLabel = (type) => {
  const map = { simple: 'Simple', simple_variations: 'Variable' };
  return map[type] || type || '—';
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
    {key: 'image', title: self.$t('Image'), width: 60, minWidth: 50},
    {key: 'sku', title: self.$t('SKU'), width: 120, minWidth: 80},
    {key: 'categories', title: self.$t('Categories'), width: 200, minWidth: 150},
    {key: 'description', title: self.$t('Description'), width: 200, minWidth: 150},
    {key: 'short_description', title: self.$t('Short Description'), width: 150, minWidth: 100},
    {key: 'status', title: self.$t('Status'), width: 100, minWidth: 50},
    {key: 'product_type', title: self.$t('Product Type'), width: 120, minWidth: 80},
    {key: 'pricing_type', title: self.$t('Pricing Type'), width: 120, minWidth: 80},
    {key: 'payment_type', title: self.$t('Payment Type'), width: 120, minWidth: 100},
    {key: 'interval', title: self.$t('Interval'), width: 120, minWidth: 80},
    {key: 'trial_days', title: self.$t('Trial Days'), width: 100, minWidth: 70},
    {key: 'best_price', title: self.$t('Best Price'), width: 100, minWidth: 100},
    {key: 'compare_price', title: self.$t('Compare-at Price'), width: 150, minWidth: 100},
    {key: 'track_quantity', title: self.$t('Track Quantity'), width: 100, minWidth: 100},
    {key: 'stock', title: self.$t('Stock'), width: 120, minWidth: 80},
    {key: 'actions', title: '', width: 110, minWidth: 110},
  ];

  bulkEditModel.restoreColumnWidths(columns.value);
  bulkEditModel.setupColumnVisibility();

  // Fetch categories for picker
  bulkEditModel.fetchCategories();

  // Auto-load products on mount
  loadProducts(false);

  document.body.classList.add('fct-bulk-page');

  window.addEventListener('keydown', onShiftDown);
  window.addEventListener('keyup', onShiftUp);
});

onBeforeUnmount(() => {
  document.body.classList.remove('fct-bulk-page');
  window.removeEventListener('keydown', onShiftDown);
  window.removeEventListener('keyup', onShiftUp);
  if (keyboard) {
    keyboard.reset();
  }
});
</script>

<template>
  <div class="fct-bulk-edit-page fct-layout-width">
    <PageHeading :title="$t('Bulk Edit')">
      <template #action>
        <el-button
          type="primary"
          @click="saveProducts"
          :loading="saving"
          :disabled="saving || dirtyCount === 0"
        >
          {{ saving
            ? $t('Saving %s/%s...').replace('%s', saveProgress.completed).replace('%s', saveProgress.total)
            : dirtyCount > 0
              ? $t('Save Changes') + ` (${dirtyCount})`
              : $t('Save Changes')
          }}
        </el-button>
      </template>
    </PageHeading>

    <el-progress v-if="saving" :percentage="progressPercentage" :stroke-width="6" class="mb-4" />

    <div class="fct-table-wrapper">
        <Card.Container>
            <Card.Header border_bottom>
                <div class="fct-card-header-top">
                    <div class="fct-card-header-left flex-1">
                        <FilterTabs
                            v-if="bulkEditModel.getTabsCount() && !bulkEditModel.isUsingAdvanceFilter()"
                            :class="'hide-animation-on-mobile hidden md:inline-flex classic-tab-style'"
                            :table="bulkEditModel"
                        />
                    </div>
                    <div class="fct-card-header-actions">
                        <div
                            v-if="bulkEditModel.isAdvanceFilterEnabled()"
                            class="fct-advanced-filter-toggle-wrapper"
                        >
                        <el-switch
                            class="fct-advanced-filter-toggle"
                            @change="(filterType) => { bulkEditModel.onFilterTypeChanged(filterType) }"
                            active-value="advanced"
                            inactive-value="simple"
                            v-model="bulkEditModel.data.filterType"
                            :active-text="$t('Advanced Filter')"
                            size="small"
                        />
                        </div>

                        <div class="fct-btn-group sm hidden md:flex" v-if="!bulkEditModel.isUsingAdvanceFilter()">
                            <el-tooltip
                                effect="light"
                                :content="$t('Search')"
                                placement="top"
                                v-if="!bulkEditModel.isSearching()"
                                popper-class="fct-tooltip"
                            >
                                <IconButton
                                tag="button"
                                @click.prevent="openSearch"
                                >
                                <DynamicIcon name="Search" />
                                </IconButton>
                            </el-tooltip>
                        </div>
                    </div>
                </div>

                <AdvancedFilter :table="bulkEditModel" />

                <div class="filter-search-wrap" v-if="bulkEditModel.isSearching() && !bulkEditModel.isUsingAdvanceFilter()">
                    <div class="search-bar">
                        <el-input
                        ref="search-input"
                        @clear="() => { bulkEditModel.search() }"
                        @keyup.enter="() => { bulkEditModel.search() }"
                        v-model="bulkEditModel.data.search"
                        type="text"
                        :placeholder="$t('Search')"
                        clearable
                        >
                        <template #prefix>
                            <DynamicIcon name="Search" />
                        </template>
                        </el-input>
                        <div class="text-xs text-system-light pt-2 dark:text-gray-300">
                        {{ bulkEditModel.getSearchHint() }}
                        </div>
                    </div>

                    <el-button text @click="() => { bulkEditModel.closeSearch() }">
                        {{ $t('Cancel') }}
                    </el-button>
                </div>
            </Card.Header>

            <Card.Body class="p-0">
                <div class="fct-bulk-edit-table-wrap">
                  <div class="px-5 py-3 flex items-center justify-between border border-solid border-x-0 border-gray-divider border-t-0 dark:border-dark-400" v-if="products.length > 0">
                      <h5 class="m-0 text-base">
                        {{ products.length }} {{ products.length === 1 ? $t('Product') : $t('Products') }}
                        <span v-if="pagination.total > products.length" class="text-system-mid text-sm font-normal dark:text-gray-300">
                            {{ $t('of') }} {{ pagination.total }} {{ $t('total') }} &middot; {{ nextPageCount }} {{ $t('next') }}
                        </span>
                      </h5>

                      <div class="fct-btn-group sm justify-end">
                        <el-button
                            :disabled="selectedCount === 0"
                            @click="bulkDuplicate"
                            size="small"
                        >
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            {{ $t('Duplicate') }}
                        </el-button>

                        <el-button
                            :disabled="selectedCount === 0"
                            @click="bulkDelete"
                            size="small"
                            type="danger"
                            plain
                        >
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            {{ $t('Delete') }}
                        </el-button>

                        <el-button
                            :class="{ 'is-active': isLinkMode }"
                            :disabled="selectedCount < 2 && !isLinkMode"
                            @click="toggleLinkMode"
                            size="small"
                        >
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                            {{ isLinkMode ? $t('Unlink') : $t('Link') }}
                        </el-button>

                        <el-checkbox v-model="stickyActions" :label="$t('Pin Actions')" />

                        <el-popover trigger="click" placement="bottom-end" width="240" popper-class="filter-popover">
                            <div class="filter-popover-item">
                            <h3 class="filter-popover-title">{{ $t('Columns') }}</h3>
                            <el-checkbox-group
                                v-model="bulkEditModel.data.visibleColumns"
                                class="fct-checkbox-blocks"
                                @change="bulkEditModel.handleColumnVisibilityChange()"
                            >
                                <el-checkbox
                                v-for="col in bulkEditModel.getToggleableColumns()"
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
                  </div>

                  <div class="fct-bulk-edit-table">
                      <!-- Table with data -->
                      <div class="relative" :class="{ 'is-link-mode': isLinkMode, 'is-shift-held': isShiftHeld }" v-if="products.length > 0">
                      <ResizeableColumns :columns="filteredColumns" :sticky-last="stickyActions" @scroll="onScroll" @resize-end="onColumnResizeEnd">
                          <template #header-first>
                            <el-checkbox
                                :checked="selectedCount > 0 && selectedCount === flatVisibleKeys.length"
                                v-indeterminate="selectedCount > 0 && selectedCount < flatVisibleKeys.length"
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
                          <tr class="bulk-product-row" :class="{ 'is-dirty': isDirty(group.product), 'is-saving': isSaving(group.product), 'is-saved': isSaved(group.product), 'has-product-error': hasError(group.product), 'is-selected': isSelected(`p:${group.productId}`) }" @click="onRowShiftClick(`p:${group.productId}`, $event)">

                              <td class="bulk-checkbox-cell" @click="handleRowClick(`p:${group.productId}`, $event, flatVisibleKeys)">
                                <el-checkbox
                                    :model-value="isSelected(`p:${group.productId}`)"
                                    tabindex="-1"
                                />
                              </td>

                              <td class="fct-bulk-product-title-cell sticky-col" :style="{ left: '40px' }">
                              <div class="flex items-center">
                                  <button
                                    v-if="group.hasVariants"
                                    class="bulk-collapse-btn"
                                    @click.prevent="toggleCollapse(group.product)"
                                    :title="isCollapsed(group.product) ? $t('Expand variants') : $t('Collapse variants')"
                                  >
                                    <svg :class="['bulk-collapse-icon', { 'is-collapsed': isCollapsed(group.product) }]" width="16" height="16" viewBox="0 0 16 16" fill="none">
                                        <path d="M6 4L10 8L6 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>

                                    <span v-if="isCollapsed(group.product)" class="bulk-collapse-count">
                                      {{ group.variantCount }}
                                    </span>
                                  </button>

                                <!-- input -->
                                <el-input
                                    class="fct-bulk-input"
                                    v-model="group.product.post_title"
                                    :placeholder="$t('Product title')"
                                    :disabled="isRowDisabled(group.product)"
                                    size="small"
                                    :class="{ 'is-error': hasFieldError(group.product, 'post_title') }"
                                    @update:modelValue="(val) => {
                                      markDirty(group.product)
                                      onSync('title', val, `p:${group.productId}`)
                                    }"
                                />


                                  <!-- Syncing icon while saving -->
                                  <span v-if="isSaving(group.product)" class="bulk-status-icon is-syncing" :title="$t('Saving...')">
                                  <el-icon class="is-loading" :size="14"><Loading /></el-icon>
                                  </span>

                                  <!-- Error icon — product-level errors, or any error for simple products (no variant rows) -->
                                  <el-tooltip v-else-if="hasProductLevelError(group.product) || (isSimple(group.product) && hasError(group.product))" :content="getError(group.product)" placement="top">
                                  <span class="bulk-status-icon is-error">
                                      <el-icon :size="16"><WarningFilled /></el-icon>
                                  </span>
                                  </el-tooltip>

                                  <!-- Inline save button when dirty -->
                                  <button
                                  v-else-if="isDirty(group.product)"
                                  class="bulk-inline-save"
                                  :disabled="isRowDisabled(group.product)"
                                  @click.prevent="saveProduct(group.product)"
                                  :title="$t('Save')"
                                  >
                                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" width="16" height="16">
                                      <path d="M9.9997 15.1709L19.1921 5.97852L20.6063 7.39273L9.9997 17.9993L3.63574 11.6354L5.04996 10.2212L9.9997 15.1709Z" fill="currentColor"/>
                                  </svg>
                                  </button>
                              </div>
                              </td>

                            <!-- media -->
                              <td class="fct-bulk-media-cell" v-if="isColVisible('image')">
                                <BulkMediaPicker v-model="group.product.gallery" @change="markDirty(group.product)" />
                              </td>

                              <td class="fct-bulk-sku-cell" v-if="isColVisible('sku')">
                                <el-input
                                    class="fct-bulk-input"
                                    v-if="group.product?.detail?.variation_type === 'simple' && group.product.variants?.[0]"
                                    v-model="group.product.variants[0].sku"
                                    placeholder="SKU"
                                    size="small"
                                    :disabled="isRowDisabled(group.product)"
                                    :class="{ 'is-error': hasFieldError(group.product, 'variants.0.sku') }"
                                    @update:modelValue="(val) => {
                                      markDirty(group.product)
                                      onSync('sku', val, `p:${group.productId}`)
                                    }"
                                />

                                <span v-else class="text-gray-300 text-sm flex justify-center">—</span>
                              </td>

                            <!-- categories -->
                              <td class="fct-bulk-categories-cell" v-if="isColVisible('categories')">
                                <BulkCategorySelect
                                    v-model="group.product.categories"
                                    :options="bulkEditModel.data.categoryOptions"
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
                                <el-select v-model="group.product.post_status" size="small" :class="{ 'is-field-error': hasFieldError(group.product, 'post_status') }" :disabled="isRowDisabled(group.product)" @change="markDirty(group.product); onSync('status', group.product.post_status, `p:${group.productId}`)">
                                    <el-option :label="$t('Published')" value="publish" />
                                    <el-option :label="$t('Draft')" value="draft" />
                                </el-select>
                              </td>

                            <!-- product type -->
                              <td class="fct-bulk-product-type-cell" v-if="isColVisible('product_type')">
                               <span class="bulk-readonly-label">{{ fulfillmentLabel(group.product?.detail?.fulfillment_type) }}</span>
                              </td>

                              <!-- pricing type -->
                              <td class="fct-bulk-pricing-type-cell" v-if="isColVisible('pricing_type')">
                                <span class="bulk-readonly-label">{{ variationTypeLabel(group.product?.detail?.variation_type) }}</span>
                              </td>

                            <!-- payment type -->
                              <td class="fct-bulk-payment-type-cell" v-if="isColVisible('payment_type')">
                                <BulkPaymentTypeCell
                                    :variant="isSimple(group.product) ? group.product.variants?.[0] : null"
                                    :disabled="isRowDisabled(group.product)"
                                    :has-error="hasFieldError(group.product, 'variants.0.other_info.payment_type')"
                                    @payment-type-change="(v) => { onPaymentTypeChange(group.product, v); onSync('payment_type', v.other_info?.payment_type, `p:${group.productId}`); }"
                                    @change="markDirty(group.product)"
                                />
                              </td>

                            <!-- interval -->
                              <td class="fct-bulk-interval-cell" v-if="isColVisible('interval')">
                                <BulkIntervalSelect
                                    :variant="isSimple(group.product) ? group.product.variants?.[0] : null"
                                    :disabled="isRowDisabled(group.product)"
                                    :has-error="hasFieldError(group.product, 'variants.0.other_info.repeat_interval')"
                                    @change="markDirty(group.product); onSync('interval', group.product.variants?.[0]?.other_info?.repeat_interval, `p:${group.productId}`)"
                                />
                              </td>

                            <!-- trial days -->
                              <td class="fct-bulk-trial-days-cell" v-if="isColVisible('trial_days')">
                              <BulkTrialDaysInput
                                  :variant="isSimple(group.product) ? group.product.variants?.[0] : null"
                                  :disabled="isRowDisabled(group.product)"
                                  :has-error="hasFieldError(group.product, 'variants.0.other_info.trial_days')"
                                  @change="markDirty(group.product); onSync('trial_days', group.product.variants?.[0]?.other_info?.trial_days, `p:${group.productId}`)"
                              />
                              </td>

                            <!-- best price -->
                              <td class="fct-bulk-best-price-cell" v-if="isColVisible('best_price')">
                              <BulkPriceInput
                                  :variant="isSimple(group.product) ? group.product.variants?.[0] : null"
                                  :disabled="isRowDisabled(group.product)"
                                  :has-error="hasFieldError(group.product, 'variants.0.item_price')"
                                  @change="markDirty(group.product); onSync('price', group.product.variants?.[0]?.item_price, `p:${group.productId}`)"
                              />
                              </td>

                            <!-- compare price -->
                              <td class="fct-bulk-compare-price-cell" v-if="isColVisible('compare_price')">
                              <BulkComparePriceInput
                                  :variant="isSimple(group.product) ? group.product.variants?.[0] : null"
                                  :disabled="isRowDisabled(group.product)"
                                  :has-error="hasFieldError(group.product, 'variants.0.compare_price')"
                                  @change="markDirty(group.product); onSync('compare_price', group.product.variants?.[0]?.compare_price, `p:${group.productId}`)"
                              />
                              </td>

                            <!-- quantity -->
                              <td v-if="isColVisible('track_quantity')" class="fct-bulk-qty-cell !pl-5">
                                <el-switch
                                    v-if="group.product?.detail"
                                    v-model="group.product.detail.manage_stock"
                                    :active-value="1"
                                    :inactive-value="0"
                                    :disabled="isRowDisabled(group.product)"
                                    @change="markDirty(group.product); bulkEditModel.syncStockStatus(group.product); onSync('manage_stock', group.product.detail.manage_stock, `p:${group.productId}`)"
                                />
                              </td>

                            <!-- stock -->
                              <td class="fct-bulk-stock-cell" v-if="isColVisible('stock')">
                                <el-input
                                    class="fct-bulk-input"
                                    :placeholder="$t('e.g 10')"
                                    v-if="group.product?.detail?.manage_stock && group.product?.detail?.variation_type === 'simple' && group.product.variants?.[0]"
                                    size="small"
                                    v-model="group.product.variants[0].available"
                                    :disabled="isRowDisabled(group.product)"
                                    @update:modelValue="(val) => {
                                      group.product.variants[0].total_stock = val;
                                      markDirty(group.product);
                                      bulkEditModel.syncStockStatus(group.product);
                                      onSync('stock', val, `p:${group.productId}`);
                                    }"
                                />

                              <span v-else class="text-gray-300 text-sm flex justify-center">—</span>
                              </td>

                             <!-- actions -->
                              <td class="fct-bulk-actions-cell" :class="{ 'sticky-col sticky-col-right': stickyActions }">
                              <div class="fct-btn-group sm justify-end">
                                  <icon-button
                                      tag="a"
                                      size="x-small"
                                      v-if="group.product.view_url"
                                      :href="group.product.view_url"
                                      target="_blank"
                                      :title="$t('View Product')"
                                  >
                                    <DynamicIcon name="External" />
                                  </icon-button>

                                <!-- duplicate -->
                                <icon-button
                                    tag="button"
                                    size="x-small"
                                    @click.prevent="duplicateProduct(group.product)"
                                    :disabled="saving || isRowDisabled(group.product) || isDuplicating(group.product)"
                                    :title="$t('Duplicate')"
                                >
                                  <el-icon v-if="isDuplicating(group.product)" class="is-loading" :size="14"><Loading /></el-icon>
                                  <DynamicIcon v-else name="Copy" />
                                </icon-button>


                                  <icon-button
                                      tag="button"
                                      size="x-small"
                                      hover="danger"
                                      @click.prevent="deleteProduct(group.product)"
                                      :disabled="saving || isRowDisabled(group.product) || isDeleting(group.product)"
                                      :title="$t('Delete')"
                                  >
                                    <el-icon v-if="isDeleting(group.product)" class="is-loading" :size="14"><Loading /></el-icon>
                                    <DynamicIcon v-else name="Delete" />
                                  </icon-button>
                              </div>
                              </td>

                          </tr>

                          <!-- Variants block -->
                          <tr v-if="group.hasVariants" class="bulk-variants-block-row">
                              <td :colspan="filteredColumns.length + 1" class="!p-0 !border-b-0">
                              <Animation accordion :visible="!group.collapsed" :duration="200">
                                  <table class="bulk-inner-table" :style="{ tableLayout: 'fixed', width: '100%', borderCollapse: 'collapse' }">
                                  <colgroup>
                                      <col :style="{ width: '40px' }" />
                                      <col v-for="(col, i) in filteredColumns" :key="i" :style="{ width: col.width + 'px' }" />
                                  </colgroup>
                                  <tbody>
                                      <tr v-for="(variant, vi) in group.product.variants" :key="variant.id || vi" class="bulk-variation-row" :class="{ 'is-selected': isSelected(`v:${variant.id}`) }" @click="onRowShiftClick(`v:${variant.id}`, $event)">

                                      <td class="bulk-checkbox-cell">
                                        <el-checkbox
                                            :model-value="isSelected(`v:${variant.id}`)"
                                            tabindex="-1"
                                            @change="(checked) => handleRowClick(
                                              `v:${variant.id}`,
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
                                                @update:modelValue="(val) => {
                                                  markDirty(group.product)
                                                  onSync('title', val, `v:${variant.id}`)
                                                }"
                                            />

                                            <el-tooltip v-if="hasVariantError(group.product, vi)" :content="getVariantError(group.product, vi)" placement="top">
                                                <span class="bulk-status-icon is-error">
                                                <el-icon :size="14"><WarningFilled /></el-icon>
                                                </span>
                                            </el-tooltip>
                                          </div>
                                      </td>

                                      <td v-if="isColVisible('image')">
                                          <BulkMediaPicker v-model="variant.media" @change="markDirty(group.product)" />
                                      </td>

                                      <td v-if="isColVisible('sku')">
                                        <el-input
                                            class="fct-bulk-input"
                                            size="small"
                                            v-model="variant.sku"
                                            :placeholder="$t('SKU')"
                                            :disabled="isRowDisabled(group.product)"
                                            :class="{ 'is-error': hasVariantFieldError(group.product, vi, 'sku') }"
                                            @update:modelValue="(val) => {
                                              markDirty(group.product)
                                              onSync('sku', val, `v:${variant.id}`)
                                            }"
                                        />
                                      </td>

                                      <td v-if="isColVisible('categories')"><span class="text-gray-300 text-sm flex justify-center">—</span></td>

                                      <td v-if="isColVisible('description')"><span class="text-gray-300 text-sm flex justify-center">—</span></td>

                                      <td v-if="isColVisible('short_description')"><span class="text-gray-300 text-sm flex justify-center">—</span></td>

                                      <td v-if="isColVisible('status')"><span class="text-gray-300 text-sm flex justify-center">—</span></td>

                                      <td v-if="isColVisible('product_type')"><span class="text-gray-300 text-sm flex justify-center">—</span></td>

                                      <td v-if="isColVisible('pricing_type')"><span class="text-gray-300 text-sm flex justify-center">—</span></td>

                                      <td v-if="isColVisible('payment_type')">
                                          <BulkPaymentTypeCell
                                          :variant="variant"
                                          :disabled="isRowDisabled(group.product)"
                                          :has-error="hasVariantFieldError(group.product, vi, 'other_info.payment_type')"
                                          @payment-type-change="(v) => { onPaymentTypeChange(group.product, v); onSync('payment_type', v.other_info?.payment_type, `v:${variant.id}`); }"
                                          @change="markDirty(group.product)"
                                          />
                                      </td>

                                      <td v-if="isColVisible('interval')">
                                          <BulkIntervalSelect
                                          :variant="variant"
                                          :disabled="isRowDisabled(group.product)"
                                          :has-error="hasVariantFieldError(group.product, vi, 'other_info.repeat_interval')"
                                          @change="markDirty(group.product); onSync('interval', variant.other_info?.repeat_interval, `v:${variant.id}`)"
                                          />
                                      </td>

                                      <td v-if="isColVisible('trial_days')">
                                          <BulkTrialDaysInput
                                          :variant="variant"
                                          :disabled="isRowDisabled(group.product)"
                                          :has-error="hasVariantFieldError(group.product, vi, 'other_info.trial_days')"
                                          @change="markDirty(group.product); onSync('trial_days', variant.other_info?.trial_days, `v:${variant.id}`)"
                                          />
                                      </td>

                                      <td v-if="isColVisible('best_price')">
                                          <BulkPriceInput
                                          :variant="variant"
                                          :disabled="isRowDisabled(group.product)"
                                          :has-error="hasVariantFieldError(group.product, vi, 'item_price')"
                                          @change="markDirty(group.product); onSync('price', variant.item_price, `v:${variant.id}`)"
                                          />
                                      </td>

                                      <td v-if="isColVisible('compare_price')">
                                          <BulkComparePriceInput
                                          :variant="variant"
                                          :disabled="isRowDisabled(group.product)"
                                          :has-error="hasVariantFieldError(group.product, vi, 'compare_price')"
                                          @change="markDirty(group.product); onSync('compare_price', variant.compare_price, `v:${variant.id}`)"
                                          />
                                      </td>

                                      <td v-if="isColVisible('track_quantity')" class="text-center">
                                          <span class="text-gray-300 text-sm">—</span>
                                      </td>

                                      <td v-if="isColVisible('stock')">
                                        <el-input
                                            class="fct-bulk-input"
                                            :placeholder="$t('e.g 10')"
                                            v-if="group.product?.detail?.manage_stock"
                                            size="small"
                                            v-model="variant.available"
                                            :disabled="isRowDisabled(group.product)"
                                            @update:modelValue="(val) => {
                                              variant.total_stock = val;
                                              markDirty(group.product);
                                              bulkEditModel.syncStockStatus(group.product);
                                              onSync('stock', val, `v:${variant.id}`);
                                            }"
                                        />
                                          <span v-else class="text-gray-300 text-sm flex justify-center">—</span>
                                      </td>

                                      <td class="text-center" :class="{ 'sticky-col sticky-col-right': stickyActions }">
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
                      <div v-else-if="!loading" class="flex flex-col items-center justify-center py-16 text-center">
                      <p class="text-system-mid text-sm">{{ $t('No products found.') }}</p>
                      </div>

                      <!-- Loading state (initial load only) -->
                      <div v-else-if="loading" class="flex items-center justify-center py-8">
                      <el-icon class="is-loading mr-2"><i class="el-icon-loading" /></el-icon>
                      <span class="text-system-mid text-sm">{{ $t('Loading products...') }}</span>
                      </div>
                  </div>

                  <!-- Infinite scroll loading indicator -->
                  <div v-if="pagination.hasMore && products.length > 0 && loading" class="flex items-center justify-center py-3 border-top">
                      <el-icon class="is-loading mr-2"><i class="el-icon-loading" /></el-icon>
                      <span class="text-system-mid text-sm">{{ $t('Loading') }} {{ nextPageCount }} {{ $t('more...') }}</span>
                  </div>
                </div>
            </Card.Body>

        </Card.Container>

   
    </div>
    

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
