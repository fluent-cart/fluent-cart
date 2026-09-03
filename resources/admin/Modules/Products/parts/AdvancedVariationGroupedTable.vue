<script setup>
import {ref, computed, watch, onUnmounted} from "vue";
import {formatNumber} from "@/Bits/productService";
import BulkMediaPicker from "@/Bits/Components/Attachment/BulkMediaPicker.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import TransitionAccordion from "@/Bits/Components/TransitionAccordion.vue";
import PriceInput from "@/Bits/Components/Inputs/PriceInput.vue";
import translate from "@/utils/translator/Translator";
import {createGroupPriceCommitTracker} from "../utils/groupPriceCommit";

const props = defineProps({
  product: Object,
  productEditModel: {
    type: Object,
    default: null,
  },
  attributeGroups: {
    type: Array,
    default: () => [],
  },
  groupedVariants: {
    type: Array,
    default: () => [],
  },
  selectedVariants: {
    type: Array,
    default: () => [],
  },
  isAllSelected: {
    type: Boolean,
    default: false,
  },
  isSelectionIndeterminate: {
    type: Boolean,
    default: false,
  },
  groupById: {
    type: Number,
    default: null,
  },
  // Expand state lifted to the parent so it survives the skeleton
  // unmount during any mutation (delete, save-order, bulk action).
  // Before this lived locally — every mutation reset every group to
  // collapsed, forcing the merchant to re-expand the group they were
  // verifying after a delete on a large combination.
  expandedGroups: {
    type: Set,
    default: () => new Set(),
  },
})

// Breadcrumb title per variant ("Vanilla / Regular / Floral / 1 kg"), cached.
// Previously getAttrBreadcrumb ran per row on EVERY render — rebuilding the
// group order and doing find()s over attributeGroups/terms for each attr — which
// is costly when expanding a large group (144 combinations). Compute the whole
// map once and recompute only when the underlying data changes; rows just read
// breadcrumbByVariantId[variant.id].
//
// Order by the merchant's current group order (other_info.attribute_config),
// not attr_map's DB insertion order, which never re-orders on drag-drop. Skip
// the column the table is grouped by (groupById) so it isn't repeated in the row.
const breadcrumbByVariantId = computed(() => {
  const skip = props.groupById ? parseInt(props.groupById) : null;

  const groupOrder = (props.product?.detail?.other_info?.attribute_config || [])
      .map(groupConfig => parseInt(groupConfig.group_id))
      .filter(Boolean);
  const rankOf = (gid) => {
    const position = groupOrder.indexOf(gid);
    return position === -1 ? Number.MAX_SAFE_INTEGER : position;
  };

  // groupId -> termId -> title, built once instead of find()-per-attr-per-render.
  const termTitle = {};
  (props.attributeGroups || []).forEach(group => {
    const byTerm = {};
    (group.terms || []).forEach(term => { byTerm[parseInt(term.id)] = term.title; });
    termTitle[parseInt(group.id)] = byTerm;
  });

  const map = {};
  (props.groupedVariants || []).forEach(group => {
    (group.variants || []).forEach(variant => {
      const attrMap = variant.attr_map || [];
      if (!attrMap.length) { map[variant.id] = ''; return; }
      const labels = [...attrMap]
          .sort((a, b) => rankOf(parseInt(a.group_id)) - rankOf(parseInt(b.group_id)))
          .map(rel => {
            const gid = parseInt(rel.group_id);
            if (skip && gid === skip) return null;
            const byTerm = termTitle[gid];
            return byTerm ? (byTerm[parseInt(rel.term_id)] || null) : null;
          }).filter(Boolean);
      map[variant.id] = labels.join(' / ');
    });
  });
  return map;
});

// Lazy-mount the per-row ⋮ menu content. The dropdown's items (two
// CopyToClipboard + their tooltips + the action items) are otherwise mounted
// for every child row the moment a group expands; here they mount only the
// first time that row's menu is actually opened. Reassign the Set so Vue picks
// up the change.
const openedDropdowns = ref(new Set());
const markDropdownOpened = (variantId) => {
  if (!openedDropdowns.value.has(variantId)) {
    openedDropdowns.value = new Set(openedDropdowns.value).add(variantId);
  }
};

// Render-cap large groups, growing on scroll. Mounting every child row of a
// 48-/144-variant group the instant it expands is what makes expansion feel
// slow — each row mounts a checkbox, media picker, two inputs and a dropdown.
// Render the first CHILD_ROW_CAP rows, then add CHILD_ROW_CHUNK more each time
// the group's bottom sentinel scrolls into view, so expansion cost stays bounded
// regardless of group size. Rows aren't recycled (fine up to a few hundred per
// group; for far larger sets, virtualization is the next step). Selection/save
// still operate on the full group.variants, not just the rendered slice.
const CHILD_ROW_CAP = 30;
const CHILD_ROW_CHUNK = 30;
const groupLimits = ref({}); // termId -> currently-rendered row count

const limitFor = (termId) => groupLimits.value[termId] || CHILD_ROW_CAP;
const visibleVariants = (group) => {
  const all = group.variants || [];
  const limit = limitFor(group.termId);
  return limit >= all.length ? all : all.slice(0, limit);
};
const hasMoreRows = (group) => (group.variants || []).length > limitFor(group.termId);
const revealMoreRows = (termId, total) => {
  const current = groupLimits.value[termId] || CHILD_ROW_CAP;
  if (current >= total) return;
  groupLimits.value = { ...groupLimits.value, [termId]: Math.min(current + CHILD_ROW_CHUNK, total) };
};

// Re-cap a group when it collapses so the next expand is fast again.
watch(() => props.expandedGroups, (expanded) => {
  const keys = Object.keys(groupLimits.value);
  if (!keys.length) return;
  const kept = {};
  keys.forEach(termId => {
    if (expanded && (expanded.has(termId) || expanded.has(Number(termId)))) {
      kept[termId] = groupLimits.value[termId];
    }
  });
  if (Object.keys(kept).length !== keys.length) {
    groupLimits.value = kept;
  }
});

// Infinite scroll. Observe each open group's bottom sentinel; when it enters the
// viewport (300px early), render the next chunk. The observer root is the table's
// scroll container, or the viewport — so it works whether the page or an inner
// panel scrolls. On a tall monitor the sentinel stays visible after the first
// chunk, so it keeps firing until the content fills past the viewport, then
// pauses: large screens auto-load just enough to fill, still in small batches.
let rowObserver = null;
const sentinelEls = {}; // termId -> element, so we can unobserve on collapse

const getScrollParent = (el) => {
  let node = el && el.parentElement;
  while (node) {
    const oy = getComputedStyle(node).overflowY;
    if (oy === 'auto' || oy === 'scroll' || oy === 'overlay') return node;
    node = node.parentElement;
  }
  return null; // viewport
};

const ensureObserver = (sentinelEl) => {
  if (rowObserver) return;
  rowObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      revealMoreRows(entry.target.dataset.termId, parseInt(entry.target.dataset.total, 10) || 0);
    });
  }, { root: getScrollParent(sentinelEl), rootMargin: '300px 0px' });
};

const sentinelRef = (termId, total) => (el) => {
  if (el) {
    el.dataset.termId = termId;
    el.dataset.total = total;
    ensureObserver(el);
    sentinelEls[termId] = el;
    rowObserver.observe(el);
  } else if (sentinelEls[termId]) {
    rowObserver && rowObserver.unobserve(sentinelEls[termId]);
    delete sentinelEls[termId];
  }
};

onUnmounted(() => {
  if (rowObserver) { rowObserver.disconnect(); rowObserver = null; }
});

// Visual error state for the inline compare-price cell. Reads the model's
// validation map keyed by `variants.<i>.compare_price`, where <i> is the
// index in the full product.variants array (groupedVariants may surface a
// filtered subset).
const hasComparePriceError = (variant) => {
  if (!props.productEditModel) return false;
  const idx = props.product.variants.findIndex(v => v.id === variant.id);
  if (idx === -1) return false;
  return props.productEditModel.hasValidationError(`variants.${idx}.compare_price`);
};

const emit = defineEmits([
  'update:selectedVariants',
  'update:isAllSelected',
  'update:expandedGroups',
  'editVariant',
  'editGroup',
  'toggleStatus',
  'inlinePriceChange',
  'inlinePriceCommit',
  'mediaUploaded',
  'groupPriceApply',
  'groupMediaUploaded',
])

// Group price overrides — populated only when the user types. The placeholder shows the
// current price (uniform) or range derived from children.
const groupPrices = ref({});

// Tracks the Enter-arms / blur-consumes lifecycle for the group price quick-set
// (see groupPriceCommit.js). PriceInput keeps groupPrices[termId] current via
// update:modelValue on every keystroke, but suppresses its own `change` event
// on blur once the typed value already equals what it last emitted — the
// common case once the merchant stops typing and tabs away. Blur and Enter
// therefore both read the buffer directly and commit through this tracker,
// which also stops Enter followed immediately by blur from applying the same
// price twice — without blocking a later, unrelated re-application of the
// same price (e.g. after the merchant selects different children or edits
// one directly). Not a ref: nothing in the template reads its internal
// state, so it doesn't need to be reactive.
const groupPriceTracker = createGroupPriceCommitTracker();

// Discard reverts product.variants in-place, but groupPrices is a write-only
// buffer decoupled from them, so a typed-then-discarded group price would linger
// in the input. Clear it on discard (mirrors ProductInfo watching discardKey) so
// the input falls back to its placeholder, which reflects the reverted prices.
watch(() => props.productEditModel?.data?.discardKey, () => {
  groupPrices.value = {};
  groupPriceTracker.reset();
});

const allGroupsExpanded = computed(() => {
  return props.groupedVariants.every(g => props.expandedGroups.has(g.termId));
});

const toggleExpandAll = () => {
  const next = allGroupsExpanded.value
      ? new Set()
      : new Set(props.groupedVariants.map(g => g.termId));
  emit('update:expandedGroups', next);
};

const toggleGroup = (termId) => {
  const next = new Set(props.expandedGroups);
  if (next.has(termId)) {
    next.delete(termId);
  } else {
    next.add(termId);
  }
  emit('update:expandedGroups', next);
};

const isGroupExpanded = (termId) => props.expandedGroups.has(termId);

const isGroupAllSelected = (group) => {
  return group.variants.length > 0 && group.variants.every(v => props.selectedVariants.includes(v.id));
};

const isGroupIndeterminate = (group) => {
  if (group.variants.length === 0) return false;
  const selected = group.variants.filter(v => props.selectedVariants.includes(v.id)).length;
  return selected > 0 && selected < group.variants.length;
};

const toggleGroupSelection = (group, val) => {
  let updated;
  if (val) {
    const ids = new Set(props.selectedVariants);
    group.variants.forEach(v => ids.add(v.id));
    updated = Array.from(ids);
  } else {
    const removeIds = new Set(group.variants.map(v => v.id));
    updated = props.selectedVariants.filter(id => !removeIds.has(id));
  }
  emit('update:selectedVariants', updated);
};

const toggleVariantSelection = (variantId, val) => {
  let updated;
  if (val) {
    updated = [...props.selectedVariants, variantId];
  } else {
    updated = props.selectedVariants.filter(id => id !== variantId);
  }
  emit('update:selectedVariants', updated);
};

const applyGroupPrice = (groupTermId, price) => {
  emit('groupPriceApply', groupTermId, price);
};

// Enter always commits the current buffer unconditionally (matches the
// pre-existing Enter behaviour) and arms the tracker so the trailing blur of
// the same keypress — Enter does not itself move focus, but a Tab or click
// immediately after does — does not reapply it.
const commitGroupPriceOnEnter = (group) => {
  const numericPrice = groupPriceTracker.onEnter(group.termId, groupPrices.value[group.termId]);
  if (numericPrice === null) return;

  applyGroupPrice(group.termId, numericPrice);
};

// Blur consumes any pending Enter value for this group — one-shot, whether
// or not it ends up suppressing anything — then applies the buffer unless
// this is exactly that Enter's trailing blur.
const commitGroupPriceOnBlur = (group) => {
  const toApply = groupPriceTracker.onBlur(group.termId, groupPrices.value[group.termId]);
  if (toApply === null) return;

  applyGroupPrice(group.termId, toApply);
};

const formatGroupPriceRange = (group) => {
  const prices = group.variants
      .map(v => parseFloat(v.item_price))
      .filter(p => !isNaN(p));
  if (!prices.length) return '';
  const min = Math.min(...prices);
  const max = Math.max(...prices);
  // Match the inner variant price input, which renders item_price as dollars
  // with no forced decimals — so a whole-number group price reads "0" not
  // "0.00". item_price is stored in cents, so format rather than interpolate.
  if (min === max) {
    return formatNumber(min, false);
  }
  return `${formatNumber(min, false)} – ${formatNumber(max, false)}`;
};

// Track which group's price input currently has focus so we can blank its
// placeholder while the merchant is typing. CSS-only doesn't work here because
// the existing ::placeholder rule uses !important to make the range text read
// as a real value (Shopify behaviour), and the design-system hook blocks
// adding another !important rule to override it on focus. Swapping the
// placeholder attribute to '' is the cleanest way to remove the text.
const focusedGroupTermId = ref(null);

const getGroupPricePlaceholder = (group) => {
  if (focusedGroupTermId.value === group.termId) return '';
  return formatGroupPriceRange(group) || '';
};

const onGroupPriceFocus = (group) => {
  focusedGroupTermId.value = group.termId;
};

const onGroupPriceBlur = (group) => {
  focusedGroupTermId.value = null;
  commitGroupPriceOnBlur(group);
};

// Distinct images across the group's variants, deduped by media id (or url
// for URL-imported images) so variants sharing an image are not counted
// twice. Fed to the group row's BulkMediaPicker, which renders the
// representative thumbnail plus a +N count from this array's length.
const getGroupDistinctMedia = (group) => {
  const seen = new Set();
  const media = [];
  (group.variants || []).forEach(v => {
    if (!Array.isArray(v?.media)) return;
    v.media.forEach(m => {
      const key = m?.id || m?.url;
      if (key && !seen.has(key)) {
        seen.add(key);
        media.push(m);
      }
    });
  });
  return media;
};

// The group row's BulkMediaPicker binds a derived aggregate as model-value.
// A no-op Save would re-emit that aggregate and broadcast the union of every
// variant's images back onto all of them. Emit only when the picked media
// genuinely differs — order included, so a reorder still saves — from the
// current aggregate.
const onGroupMediaChange = (group, updatedMedia, saveOptions) => {
  if (!saveOptions || !saveOptions.applyToAll) return;
  emit('groupMediaUploaded', group, updatedMedia);
};

const onVariantMediaChange = (group, variant, updatedMedia, saveOptions) => {
  if (saveOptions && saveOptions.applyToAll) {
    emit('groupMediaUploaded', group, updatedMedia);
  } else {
    emit('mediaUploaded', variant, updatedMedia);
  }
};
</script>

<template>
  <div class="fct-grouped-variant-table fct-adv-table-desktop">
    <table>
      <colgroup>
        <col width="80">
        <col width="130">
        <col width="250">
        <col width="150">
        <col width="180">
        <col width="100">
      </colgroup>
      <thead>
        <tr>
          <th>
            <el-checkbox
                :model-value="isAllSelected"
                :indeterminate="isSelectionIndeterminate"
                :aria-label="$t('Select all variants')"
                @update:model-value="val => $emit('update:isAllSelected', val)"
            />
          </th>
          <th>{{ $t('Image') }}</th>
          <th>{{ $t('Variant') }}</th>
          <th>{{ $t('Price') }}</th>
          <th>{{ $t('Compare at price') }}</th>
          <th class="is-right">
            <button
                type="button"
                class="fct-adv-group-chevron-btn"
                :aria-expanded="allGroupsExpanded"
                :aria-label="allGroupsExpanded ? $t('Collapse all groups') : $t('Expand all groups')"
                @click="toggleExpandAll"
            >
              <span class="fct-adv-group-chevron" :class="{ 'is-expanded': allGroupsExpanded }">
                <DynamicIcon name="ChevronDown"/>
              </span>
            </button>
          </th>
        </tr>
      </thead>

      <tbody>
        <template v-for="group in groupedVariants" :key="group.termId">
          <!-- GROUP HEADER ROW -->
          <tr class="fct-adv-group-row" :class="{ 'is-expanded': isGroupExpanded(group.termId) }" @click="toggleGroup(group.termId)">
            <td>
                <el-checkbox
                    @click.stop
                    :model-value="isGroupAllSelected(group)"
                    :indeterminate="isGroupIndeterminate(group)"
                    :aria-label="translate('Select all variants in %1$s', group.title)"
                    @change="(val) => toggleGroupSelection(group, val)"
                />
            </td>
            <td>
              <div class="fct-product-pricing-table-item" @click.stop>
                <!-- Group row: fed the distinct images across the group's
                     variants — compact mode renders the representative
                     thumbnail plus a +N count. Editing in the modal
                     broadcasts the picked images to every variant. -->
                <BulkMediaPicker
                    :model-value="getGroupDistinctMedia(group)"
                    :compact="true"
                    :max-thumbs="1"
                    :square="true"
                    :show-apply-to-all="true"
                    :default-apply-to-all="true"
                    :selected-count="selectedVariants.length"
                    @change="(updatedMedia, saveOptions) => onGroupMediaChange(group, updatedMedia, saveOptions)"
                />
              </div>
            </td>
            <td>
              <div class="fct-adv-group-header">
                <strong>{{ group.title }}</strong>
                <span class="fct-adv-group-count">{{ group.variants.length }} {{ group.variants.length === 1 ? $t('variant') : $t('variants') }}</span>
              </div>
            </td>
            <td>
              <div class="fct-product-pricing-table-item">
                <PriceInput
                    @click.stop
                    class="fct-adv-group-price-input"
                    :placeholder="getGroupPricePlaceholder(group)"
                    size="small"
                    v-model="groupPrices[group.termId]"
                    @focus="onGroupPriceFocus(group)"
                    @blur="onGroupPriceBlur(group)"
                    @keyup.enter="commitGroupPriceOnEnter(group)"
                />
              </div>
            </td>
            <td></td>
            <td class="is-right">
                <div class="fct-adv-group-actions">
                    <IconButton
                        v-if="isGroupExpanded(group.termId)"
                        class="fct-adv-group-edit-btn"
                        size="small"
                        tag="button"
                        :aria-label="$t('Edit group %1$s', group.title)"
                        @click.stop="$emit('editGroup', group)"
                    >
                        <DynamicIcon name="Edit"/>
                    </IconButton>
                    <button
                        type="button"
                        class="fct-adv-group-chevron-btn"
                        :aria-expanded="isGroupExpanded(group.termId)"
                        :aria-label="$t('Toggle %1$s variants', group.title)"
                        @click.stop="toggleGroup(group.termId)"
                    >
                        <span class="fct-adv-group-chevron" :class="{ 'is-expanded': isGroupExpanded(group.termId) }">
                            <DynamicIcon name="ChevronDown"/>
                        </span>
                    </button>
                </div>
            </td>
          </tr>

          <!-- CHILD VARIANT ROWS — one slot row per group with TransitionAccordion
               so the table height grows/shrinks smoothly. <tr> elements don't
               support overflow:hidden / height animation, so we wrap an inner
               table in a <div> (the accordion) whose height *can* animate. The
               inner colgroup mirrors the outer so columns stay aligned. -->
          <tr class="fct-adv-slot-row">
            <td colspan="6">
              <TransitionAccordion :visible="isGroupExpanded(group.termId)" :duration="220">
                <table>
                  <colgroup>
                    <col width="80">
                    <col width="130">
                    <col width="250">
                    <col width="150">
                    <col width="180">
                    <col width="100">
                  </colgroup>
                  <tbody>
                    <tr v-for="variant in visibleVariants(group)" :key="variant.id" class="fct-adv-group-child-row">
                      <td @click.stop>
                        <el-checkbox
                            :model-value="selectedVariants.includes(variant.id)"
                            :aria-label="translate('Select variant %1$s', variant.variation_title)"
                            @change="(val) => toggleVariantSelection(variant.id, val)"
                        />
                      </td>
                      <td>
                        <div class="fct-product-pricing-table-item">
                          <BulkMediaPicker
                              v-model="variant.media"
                              :compact="true"
                              :max-thumbs="1"
                              :square="true"
                              :show-apply-to-all="true"
                              :selected-count="selectedVariants.length"
                              @change="(updatedMedia, saveOptions) => onVariantMediaChange(group, variant, updatedMedia, saveOptions)"
                          />
                        </div>
                      </td>
                      <td>
                        <div class="fct-product-pricing-table-item fct-adv-child-title">
                          <div class="fct-adv-title-cell">
                            <div class="fct-adv-title-main">
                              <span class="fct-adv-variant-title">
                                {{ breadcrumbByVariantId[variant.id] || variant.variation_title }}
                              </span>
                              <span v-if="variant.item_status === 'inactive'" class="fct-variant-badge fct-variant-badge-inactive">
                                {{ translate('Inactive') }}
                              </span>
                            </div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <div class="fct-product-pricing-table-item">
                          <PriceInput
                              :placeholder="$t('Price')"
                              :model-value="variant.item_price"
                              @update:model-value="value => $emit('inlinePriceChange', variant, 'item_price', value)"
                              @change="$emit('inlinePriceCommit', variant)"
                          />
                          <!-- Subscription interval badge — same affordance the
                               simple variation pricing table shows next to the
                               price input (ProductPricingTable.vue), so a merchant
                               scanning either table can tell at a glance which
                               rows are recurring. -->
                          <span v-if="variant.other_info?.payment_type === 'subscription' && variant.other_info?.repeat_interval"
                                class="fct-variant-badge fct-repeat-interval-badge">
                            {{ variant.other_info.repeat_interval }}
                          </span>
                        </div>
                      </td>
                      <td>
                        <div class="fct-product-pricing-table-item">
                          <PriceInput
                              :placeholder="$t('Compare price')"
                              :error-class="hasComparePriceError(variant) ? 'is-error' : ''"
                              :title="hasComparePriceError(variant) ? $t('Compare price must be greater than or equal to item price.') : null"
                              :model-value="variant.compare_price"
                              @update:model-value="value => $emit('inlinePriceChange', variant, 'compare_price', value)"
                              @change="$emit('inlinePriceCommit', variant)"
                          />
                        </div>
                      </td>
                      <td class="is-right">
                        <div class="fct-product-pricing-table-item">
                          <div class="fct-btn-group sm justify-end">
                            <IconButton class="hide-on-mobile" size="small" tag="button" @click="$emit('editVariant', variant)">
                              <DynamicIcon name="Edit"/>
                            </IconButton>

                            <el-dropdown class="fct-more-option-wrap" popper-class="fct-dropdown" trigger="click" @visible-change="(v) => v && markDropdownOpened(variant.id)"
                                         @command="(command) => {
                                           if (command === 'toggle_status') {
                                             $emit('toggleStatus', variant);
                                           }
                                         }">
                              <button type="button" class="more-btn" :aria-label="translate('Variation actions')">
                                <DynamicIcon name="More"/>
                              </button>
                              <template #dropdown>
                                <el-dropdown-menu>
                                  <template v-if="openedDropdowns.has(variant.id)">
                                  <el-dropdown-item class="show-on-mobile" @click="$emit('editVariant', variant)">
                                    <DynamicIcon name="Edit"/>
                                    {{ $t('Edit') }}
                                  </el-dropdown-item>

                                  <el-dropdown-item>
                                    <CopyToClipboard
                                        v-if="variant.id"
                                        class="fct-copy-wrap-inline"
                                        :text="String(variant.id)"
                                        showMode="icon_with_text"
                                        :buttonText="$t('Copy Variation ID')"
                                    />
                                  </el-dropdown-item>

                                  <el-dropdown-item
                                      :disabled="(product.post_status !== 'publish' && product.post_status !== 'private') || !variant.id">
                                    <CopyToClipboard
                                        v-if="(product.post_status === 'publish' || product.post_status === 'private') && variant.id"
                                        class="fct-copy-wrap-inline"
                                        :text="appVars?.frontend_url +'=instant_checkout&item_id=' + variant.id + '&quantity=1'"
                                        showMode="icon_with_text"
                                        :buttonText="$t('Direct Checkout')"
                                        :tooltipContent="$t('Share direct checkout link to let customers buy this variation directly.')"
                                    />
                                    <template v-else>
                                      <DynamicIcon name="Copy"/>
                                      {{ $t('Direct Checkout') }}
                                      <LabelHint
                                          :content="$t('This product is currently in draft. You can\'t share direct checkout link')"></LabelHint>
                                    </template>
                                  </el-dropdown-item>

                                  <el-dropdown-item v-if="variant.id" command="toggle_status"
                                                    :class="{ 'item-destructive': variant.item_status !== 'inactive' }">
                                    <DynamicIcon :name="variant.item_status === 'inactive' ? 'CheckCircle' : 'InActive'"/>
                                    {{ variant.item_status === 'inactive' ? $t('Set Active') : $t('Set Inactive') }}
                                  </el-dropdown-item>
                                  </template>
                                </el-dropdown-menu>
                              </template>
                            </el-dropdown>
                          </div>
                        </div>
                      </td>
                    </tr>
                    <tr v-if="hasMoreRows(group)" class="fct-adv-row-sentinel-row" aria-hidden="true">
                      <td colspan="6">
                        <div class="fct-adv-row-sentinel" :ref="sentinelRef(group.termId, group.variants.length)"></div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </TransitionAccordion>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>

  <!-- Mobile table: same markup/UI as the desktop table; trim columns here (e.g. remove Price) independently of desktop. -->
  <div class="fct-grouped-variant-table fct-adv-table-mobile">
    <table>
      <colgroup>
        <col width="56">
        <col width="68">
        <col width="auto">
        <col width="56">
      </colgroup>
      <thead>
        <tr>
          <th>
            <el-checkbox
                :model-value="isAllSelected"
                :indeterminate="isSelectionIndeterminate"
                :aria-label="$t('Select all variants')"
                @update:model-value="val => $emit('update:isAllSelected', val)"
            />
          </th>
          <th>{{ $t('Image') }}</th>
          <th>{{ $t('Variant') }}</th>
          <th class="is-right">
            <button
                type="button"
                class="fct-adv-group-chevron-btn"
                :aria-expanded="allGroupsExpanded"
                :aria-label="allGroupsExpanded ? $t('Collapse all groups') : $t('Expand all groups')"
                @click="toggleExpandAll"
            >
              <span class="fct-adv-group-chevron" :class="{ 'is-expanded': allGroupsExpanded }">
                <DynamicIcon name="ChevronDown"/>
              </span>
            </button>
          </th>
        </tr>
      </thead>

      <tbody>
        <template v-for="group in groupedVariants" :key="group.termId">
          <!-- GROUP HEADER ROW -->
          <tr class="fct-adv-group-row" :class="{ 'is-expanded': isGroupExpanded(group.termId) }" @click="toggleGroup(group.termId)">
            <td>
                <el-checkbox
                    @click.stop
                    :model-value="isGroupAllSelected(group)"
                    :indeterminate="isGroupIndeterminate(group)"
                    :aria-label="translate('Select all variants in %1$s', group.title)"
                    @change="(val) => toggleGroupSelection(group, val)"
                />
            </td>
            <td>
              <div class="fct-product-pricing-table-item" @click.stop>
                <!-- Group row: fed the distinct images across the group's
                     variants — compact mode renders the representative
                     thumbnail plus a +N count. Editing in the modal
                     broadcasts the picked images to every variant. -->
                <BulkMediaPicker
                    :model-value="getGroupDistinctMedia(group)"
                    :compact="true"
                    :max-thumbs="1"
                    :square="true"
                    :show-apply-to-all="true"
                    :default-apply-to-all="true"
                    :selected-count="selectedVariants.length"
                    @change="(updatedMedia, saveOptions) => onGroupMediaChange(group, updatedMedia, saveOptions)"
                />
              </div>
            </td>
            <td>
              <div class="fct-adv-group-header">
                <strong>{{ group.title }}</strong>
                <span class="fct-adv-group-count">{{ group.variants.length }} {{ group.variants.length === 1 ? $t('variant') : $t('variants') }}</span>
              </div>
            </td>
            <td class="is-right">
                <div class="fct-adv-group-actions">
                    <IconButton
                        v-if="isGroupExpanded(group.termId)"
                        class="fct-adv-group-edit-btn"
                        size="small"
                        tag="button"
                        :aria-label="$t('Edit group %1$s', group.title)"
                        @click.stop="$emit('editGroup', group)"
                    >
                        <DynamicIcon name="Edit"/>
                    </IconButton>
                    <button
                        type="button"
                        class="fct-adv-group-chevron-btn"
                        :aria-expanded="isGroupExpanded(group.termId)"
                        :aria-label="$t('Toggle %1$s variants', group.title)"
                        @click.stop="toggleGroup(group.termId)"
                    >
                        <span class="fct-adv-group-chevron" :class="{ 'is-expanded': isGroupExpanded(group.termId) }">
                            <DynamicIcon name="ChevronDown"/>
                        </span>
                    </button>
                </div>
            </td>
          </tr>

          <!-- CHILD VARIANT ROWS — slot row + TransitionAccordion (same pattern as desktop) -->
          <tr class="fct-adv-slot-row">
            <td colspan="4">
              <TransitionAccordion :visible="isGroupExpanded(group.termId)" :duration="220">
                <table>
                  <colgroup>
                    <col width="56">
                    <col width="68">
                    <col width="auto">
                    <col width="56">
                  </colgroup>
                  <tbody>
                    <tr v-for="variant in visibleVariants(group)" :key="variant.id" class="fct-adv-group-child-row">
                      <td @click.stop>
                        <el-checkbox
                            :model-value="selectedVariants.includes(variant.id)"
                            :aria-label="translate('Select variant %1$s', variant.variation_title)"
                            @change="(val) => toggleVariantSelection(variant.id, val)"
                        />
                      </td>
                      <td>
                        <div class="fct-product-pricing-table-item">
                          <BulkMediaPicker
                              v-model="variant.media"
                              :compact="true"
                              :max-thumbs="1"
                              :square="true"
                              :show-apply-to-all="true"
                              :selected-count="selectedVariants.length"
                              @change="(updatedMedia, saveOptions) => onVariantMediaChange(group, variant, updatedMedia, saveOptions)"
                          />
                        </div>
                      </td>
                      <td>
                        <div class="fct-product-pricing-table-item fct-adv-child-title">
                          <div class="fct-adv-title-cell">
                            <div class="fct-adv-title-main">
                              <span class="fct-adv-variant-title">
                                {{ breadcrumbByVariantId[variant.id] || variant.variation_title }}
                              </span>
                              <span v-if="variant.item_status === 'inactive'" class="fct-variant-badge fct-variant-badge-inactive">
                                {{ translate('Inactive') }}
                              </span>
                            </div>
                            <!-- Mobile has no inline Price column; surface the price under
                                 the title instead. Editing stays in the Edit drawer. -->
                            <span class="fct-adv-variant-price">
                              <span v-if="variant.compare_price" class="fct-adv-variant-compare">{{ formatNumber(variant.compare_price, true) }}</span>
                              <span>{{ formatNumber(variant.item_price || 0, true) }}</span>
                            </span>
                          </div>
                        </div>
                      </td>
                      <td class="is-right">
                        <div class="fct-product-pricing-table-item">
                          <div class="fct-btn-group sm justify-end">
                            <el-dropdown class="fct-more-option-wrap" popper-class="fct-dropdown" trigger="click" @visible-change="(v) => v && markDropdownOpened(variant.id)"
                                         @command="(command) => {
                                           if (command === 'toggle_status') {
                                             $emit('toggleStatus', variant);
                                           }
                                         }">
                              <button type="button" class="more-btn" :aria-label="translate('Variation actions')">
                                <DynamicIcon name="More"/>
                              </button>
                              <template #dropdown>
                                <el-dropdown-menu>
                                  <template v-if="openedDropdowns.has(variant.id)">
                                  <el-dropdown-item @click="$emit('editVariant', variant)">
                                    <DynamicIcon name="Edit"/>
                                    {{ $t('Edit') }}
                                  </el-dropdown-item>

                                  <el-dropdown-item>
                                    <CopyToClipboard
                                        v-if="variant.id"
                                        class="fct-copy-wrap-inline"
                                        :text="String(variant.id)"
                                        showMode="icon_with_text"
                                        :buttonText="$t('Copy Variation ID')"
                                    />
                                  </el-dropdown-item>

                                  <el-dropdown-item
                                      :disabled="(product.post_status !== 'publish' && product.post_status !== 'private') || !variant.id">
                                    <CopyToClipboard
                                        v-if="(product.post_status === 'publish' || product.post_status === 'private') && variant.id"
                                        class="fct-copy-wrap-inline"
                                        :text="appVars?.frontend_url +'=instant_checkout&item_id=' + variant.id + '&quantity=1'"
                                        showMode="icon_with_text"
                                        :buttonText="$t('Direct Checkout')"
                                        :tooltipContent="$t('Share direct checkout link to let customers buy this variation directly.')"
                                    />
                                    <template v-else>
                                      <DynamicIcon name="Copy"/>
                                      {{ $t('Direct Checkout') }}
                                      <LabelHint
                                          :content="$t('This product is currently in draft. You can\'t share direct checkout link')"></LabelHint>
                                    </template>
                                  </el-dropdown-item>

                                  <el-dropdown-item v-if="variant.id" command="toggle_status"
                                                    :class="{ 'item-destructive': variant.item_status !== 'inactive' }">
                                    <DynamicIcon :name="variant.item_status === 'inactive' ? 'CheckCircle' : 'InActive'"/>
                                    {{ variant.item_status === 'inactive' ? $t('Set Active') : $t('Set Inactive') }}
                                  </el-dropdown-item>
                                  </template>
                                </el-dropdown-menu>
                              </template>
                            </el-dropdown>
                          </div>
                        </div>
                      </td>
                    </tr>
                    <tr v-if="hasMoreRows(group)" class="fct-adv-row-sentinel-row" aria-hidden="true">
                      <td colspan="6">
                        <div class="fct-adv-row-sentinel" :ref="sentinelRef(group.termId, group.variants.length)"></div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </TransitionAccordion>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>

</template>
