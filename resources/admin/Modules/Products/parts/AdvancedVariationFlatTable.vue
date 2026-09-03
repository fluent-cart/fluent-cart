<script setup>
import {computed, ref, watch, onUnmounted} from "vue";
import BulkMediaPicker from "@/Bits/Components/Attachment/BulkMediaPicker.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import PriceInput from "@/Bits/Components/Inputs/PriceInput.vue";
import translate from "@/utils/translator/Translator";

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
  variants: {
    type: Array,
    default: null,
  },
})

const displayVariants = computed(() => props.variants ?? props.product.variants);

// Render-cap the flat (ungrouped) list, growing on scroll — same strategy as the
// grouped table's child rows. Mounting every variant at once (each with a
// checkbox, media picker, two inputs and a dropdown) is slow at 100s of
// combinations; render FLAT_ROW_CAP first and add FLAT_ROW_CHUNK more whenever
// the bottom sentinel scrolls into view. Selection/save still use the full
// displayVariants, not the rendered slice.
const FLAT_ROW_CAP = 30;
const FLAT_ROW_CHUNK = 30;
const renderLimit = ref(FLAT_ROW_CAP);

const visibleVariants = computed(() => {
  const all = displayVariants.value;
  return all.length <= renderLimit.value ? all : all.slice(0, renderLimit.value);
});
const hasMoreRows = computed(() => displayVariants.value.length > renderLimit.value);

// Reset the cap whenever the underlying list changes (search / filter / data
// update) so a new result set starts fast at the top.
watch(displayVariants, () => { renderLimit.value = FLAT_ROW_CAP; });

// Infinite scroll: grow the cap when the bottom sentinel enters the viewport
// (300px early). Root is the table's scroll container or the viewport, so it
// works whether the page or an inner panel scrolls.
let rowObserver = null;
let sentinelEl = null;

const getScrollParent = (el) => {
  let node = el && el.parentElement;
  while (node) {
    const oy = getComputedStyle(node).overflowY;
    if (oy === 'auto' || oy === 'scroll' || oy === 'overlay') return node;
    node = node.parentElement;
  }
  return null; // viewport
};

const sentinelRef = (el) => {
  if (el) {
    if (!rowObserver) {
      rowObserver = new IntersectionObserver((entries) => {
        if (entries.some(entry => entry.isIntersecting)) {
          renderLimit.value = Math.min(renderLimit.value + FLAT_ROW_CHUNK, displayVariants.value.length);
        }
      }, { root: getScrollParent(el), rootMargin: '300px 0px' });
    }
    sentinelEl = el;
    rowObserver.observe(el);
  } else if (sentinelEl) {
    rowObserver && rowObserver.unobserve(sentinelEl);
    sentinelEl = null;
  }
};

onUnmounted(() => {
  if (rowObserver) { rowObserver.disconnect(); rowObserver = null; }
});

// Visual error state for the inline compare-price cell. Reads the model's
// validation map keyed by `variants.<i>.compare_price`, where <i> is the
// index in the full product.variants array (not displayVariants, which can
// be a filtered subset).
const hasComparePriceError = (variant) => {
  if (!props.productEditModel) return false;
  const idx = props.product.variants.findIndex(v => v.id === variant.id);
  if (idx === -1) return false;
  return props.productEditModel.hasValidationError(`variants.${idx}.compare_price`);
};

const getAttrBreadcrumb = (variant) => {
  const attrMap = variant.attr_map || [];
  if (!attrMap.length) return '';

  // Order by the merchant's current group order (other_info.attribute_config),
  // not the attr_map DB insertion order — relations are written once at
  // creation and never re-ordered on a drag-drop reorder, so iterating
  // attr_map directly would keep the stale creation-time order.
  const groupOrder = (props.product?.detail?.other_info?.attribute_config || [])
      .map(groupConfig => parseInt(groupConfig.group_id))
      .filter(Boolean);
  const groupRank = (groupId) => {
    const position = groupOrder.indexOf(groupId);
    return position === -1 ? Number.MAX_SAFE_INTEGER : position;
  };

  const labels = [...attrMap]
      .sort((leftRel, rightRel) => groupRank(parseInt(leftRel.group_id)) - groupRank(parseInt(rightRel.group_id)))
      .map(rel => {
        const group = props.attributeGroups.find(g => g.id === parseInt(rel.group_id));
        if (!group) return null;
        const term = (group.terms || []).find(t => t.id === parseInt(rel.term_id));
        return term ? term.title : null;
      }).filter(Boolean);
  return labels.join(' / ');
};

const emit = defineEmits([
  'update:selectedVariants',
  'update:isAllSelected',
  'editVariant',
  'toggleStatus',
  'inlinePriceChange',
  'inlinePriceCommit',
  'mediaUploaded',
])

const toggleVariantSelection = (variantId, val) => {
  let updated;
  if (val) {
    updated = [...props.selectedVariants, variantId];
  } else {
    updated = props.selectedVariants.filter(id => id !== variantId);
  }
  emit('update:selectedVariants', updated);
};
</script>

<template>
  <div class="fct-flat-variant-table fct-table-draggable hide-on-mobile">
    <table>
      <colgroup>
        <col width="40">
        <col width="50">
        <col>
        <col>
        <col>
        <col>
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
          <th>{{ $t('Title') }}</th>
          <th>{{ $t('Price') }}</th>
          <th>{{ $t('Compare at price') }}</th>
          <th class="is-right">{{ $t('Action') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="variant in visibleVariants" :key="variant.id">
          <td>
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
                  @change="value => $emit('mediaUploaded', variant, value)"
              />
            </div>
          </td>
          <td>
            <div class="fct-product-pricing-table-item">
              <div class="fct-adv-title-cell">
                <div class="fct-adv-title-main">
                  <span class="fct-adv-variant-title">{{ variant.variation_title }}</span>
                  <span v-if="variant.item_status === 'inactive'" class="fct-variant-badge fct-variant-badge-inactive">
                    {{ translate('Inactive') }}
                  </span>
                </div>
                <!-- Hide the breadcrumb when it duplicates the variation
                     title (single-group products) — for 1-group products
                     attr_map has one entry whose term title IS the
                     variation_title, so showing both stacked the same
                     word twice in the Title cell ('Red / Red'). -->
                <span v-if="getAttrBreadcrumb(variant) && getAttrBreadcrumb(variant) !== variant.variation_title" class="fct-adv-attr-breadcrumb">{{ getAttrBreadcrumb(variant) }}</span>
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
              <!-- Subscription interval badge — same affordance the simple
                   variation pricing table shows next to the price input
                   (ProductPricingTable.vue), so a merchant scanning either
                   table can tell at a glance which rows are recurring. -->
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
              <div class="fct-btn-group sm">
                <IconButton class="hide-on-mobile" size="small" tag="button" @click="$emit('editVariant', variant)">
                  <DynamicIcon name="Edit"/>
                </IconButton>

                <el-dropdown class="fct-more-option-wrap" popper-class="fct-dropdown" trigger="click"
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

                      <el-dropdown-item v-if="variant.id"
                                        command="toggle_status"
                                        :class="{ 'item-destructive': variant.item_status !== 'inactive' }">
                        <DynamicIcon :name="variant.item_status === 'inactive' ? 'CheckCircle' : 'InActive'"/>
                        {{ variant.item_status === 'inactive' ? $t('Set Active') : $t('Set Inactive') }}
                      </el-dropdown-item>
                    </el-dropdown-menu>
                  </template>
                </el-dropdown>
              </div>
            </div>
          </td>
        </tr>
        <tr v-if="hasMoreRows" class="fct-adv-row-sentinel-row" aria-hidden="true">
          <td colspan="6">
            <div class="fct-adv-row-sentinel" :ref="sentinelRef"></div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
