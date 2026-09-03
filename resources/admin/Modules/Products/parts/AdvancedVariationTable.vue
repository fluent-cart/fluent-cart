<script setup>
import {ref, computed, onMounted, onUnmounted, watch} from "vue";
import {formatNumber} from "@/Bits/productService";
import Attachments from "@/Bits/Components/Attachment/Attachments.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import AdvancedVariationGroupBar from "./AdvancedVariationGroupBar.vue";
import AdvancedVariationBulkBar from "./AdvancedVariationBulkBar.vue";
import AdvancedVariationGroupedTable from "./AdvancedVariationGroupedTable.vue";
import AdvancedVariationFlatTable from "./AdvancedVariationFlatTable.vue";
import ProductPricingForm from "./VariantForm/ProductPricingForm.vue";
import GroupBulkEditForm from "./VariantForm/GroupBulkEditForm.vue";
import VariantNavList from "@/Modules/Products/parts/VariantNavList.vue";
import VariantNavSummary from "@/Modules/Products/parts/VariantNavSummary.vue";
import VariantNavMobile from "@/Modules/Products/parts/VariantNavMobile.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Confirmation from "@/utils/Confirmation";
import Clipboard from "@/utils/Clipboard";
import AppConfig from "@/utils/Config/AppConfig";

const props = defineProps({
  product: Object,
  productEditModel: Object,
  attributeGroups: {
    type: Array,
    default: () => [],
  },
  // True while the parent (AdvancedVariationConfig) has its own
  // mutation in flight — generateVariations / saveOrderChange. Folded
  // into isMutating so the skeleton shows for those paths too, not
  // just the local delete / media-upload / bulk handlers.
  externalBusy: {
    type: Boolean,
    default: false,
  },
})

const selectedVariants = ref([]);
const savingBulk = ref(false);

// Group-edit mode — set when the user clicks the edit icon on a group header
// in the variant nav list. GroupBulkEditForm handles init and save; this parent
// just tracks which group is open and passes variant IDs down.
const isGroupEditMode = ref(false);
const editingGroupData = ref(null); // {termId, title, count, items: [{key, index, variant, ...}]}
const pendingGroupExit = ref(false);
const groupIncludedKeys = ref(new Set());

// Expand state for the grouped table. Lives at the parent level so
// it survives the skeleton swap during a mutation (delete, save-order,
// bulk action). Before this lived inside AdvancedVariationGroupedTable,
// which unmounts whenever isMutating flips true — so after every delete
// the merchant had to re-expand the group they were verifying.
const expandedGroups = ref(new Set());

// Counter so concurrent mutations (e.g. a delete during a still-pending
// media upload) don't end the busy state early. Surfaces as `isMutating`,
// which the grouped table renders a skeleton over.
const mutationCount = ref(0);
const beginBusy = () => { mutationCount.value++; };
const endBusy = () => { mutationCount.value = Math.max(0, mutationCount.value - 1); };
const isMutating = computed(() => mutationCount.value > 0 || savingBulk.value || props.externalBusy);

// Track the last time the grouped table rendered a real, populated group
// list. The skeleton mirrors this count instead of paint-stamping a
// hardcoded 6 — that way a Save Order that ends with 4 group rows
// doesn't leave the page 2-rows-taller during the skeleton phase and
// then shrink under the user (which makes the WP admin sidebar appear
// to "scroll" as the page height collapses). Seeded from the saved
// leader attribute's selected term count so the *first* skeleton (on
// initial page load) also matches what the real grouped table will
// render — before `groupedVariants` has had a chance to compute and
// the watcher below has updated this.
const lastKnownGroupCount = ref(
  props.product?.detail?.other_info?.attribute_config?.[0]?.variants?.length || 6
);

const showSharedDrawer = ref(false);
const selectedVariantIndex = ref(null);

// Edit-drawer dirty-draft guard — mirrors ProductPricingTable. The drawer is
// destroy-on-close and closes on backdrop click, so without this an unsaved
// variant edit would be silently discarded. ProductPricingForm exposes
// hasDirtyDrafts/saveCurrentDraft/discardDrafts; we gate close/cancel through
// a save-or-discard confirmation dialog.
const pricingFormRef = ref(null);
const showDirtyDraftDialog = ref(false);
const dirtyDialogSubmitting = ref(false);
const isCurrentVariationDirty = ref(false);
const pendingDirtyAction = ref(null);

// Bulk action state
const bulkAction = ref('');
const bulkValue = ref('');

// Group-by state
const groupByGroupId = ref(null);

// Search + filter panel state
const variantSearch = ref('');
const filtersExpanded = ref(false);

// Attribute filter state — { [groupId]: Set<termId> }
const activeFilters = ref({});

const toggleFiltersPanel = () => {
  filtersExpanded.value = !filtersExpanded.value;
};

const cancelFiltersPanel = () => {
  activeFilters.value = {};
  variantSearch.value = '';
  filtersExpanded.value = false;
};

const onFilterPanelBeforeEnter = (el) => {
  el.style.opacity = '0';
  el.style.overflow = 'hidden';
};

const onFilterPanelEnter = (el) => {
  const targetHeight = el.scrollHeight + 'px';
  el.style.height = '0';
  el.offsetHeight;
  el.style.transition = 'height 0.25s ease, opacity 0.2s ease';
  el.style.height = targetHeight;
  el.style.opacity = '1';
};

const onFilterPanelAfterEnter = (el) => {
  el.style.height = '';
  el.style.overflow = '';
  el.style.transition = '';
  el.style.opacity = '';
};

const onFilterPanelLeave = (el) => {
  el.style.height = el.scrollHeight + 'px';
  el.style.overflow = 'hidden';
  el.offsetHeight;
  el.style.transition = 'height 0.25s ease, opacity 0.2s ease';
  el.style.height = '0';
  el.style.opacity = '0';
};

const onFilterPanelAfterLeave = (el) => {
  el.style.height = '';
  el.style.overflow = '';
  el.style.transition = '';
  el.style.opacity = '';
};

// Configured attribute groups from product config — computed directly off props
// so the reactive chain stays inside this component. Previously we read this
// via a template ref to AdvancedVariationGroupBar's exposed computed, which
// only re-evaluated when the ref itself flipped (null → instance on mount)
// and missed subsequent changes to attribute_config inside the child. The
// auto-grouping watcher below depends on firing on every config change, so
// we derive locally from the same props the child uses.
const configuredGroups = computed(() => {
  const config = props.product?.detail?.other_info?.attribute_config;
  if (!config || !Array.isArray(config)) return [];
  return config.map(c => {
    // BIGINT ids serialize from PHP as strings while attributeGroups can come
    // back with numeric ids — strict equality silently drops every match and
    // makes configuredGroups empty, which in turn breaks the auto-grouping
    // watcher below. Normalise both sides to int before comparing.
    const targetId = parseInt(c.group_id, 10);
    const group = props.attributeGroups.find(g => parseInt(g.id, 10) === targetId);
    return group ? { id: parseInt(group.id, 10), title: group.title } : null;
  }).filter(Boolean);
});

// Chip groups enriched with terms from the global attribute group list
const filterChipGroups = computed(() => {
  return configuredGroups.value.map(cg => {
    const full = props.attributeGroups.find(g => g.id === cg.id);
    return full ? { id: full.id, title: full.title, terms: full.terms || [] } : null;
  }).filter(Boolean);
});

const isTermActive = (groupId, termId) => {
  const set = activeFilters.value[groupId];
  return set ? set.has(termId) : false;
};

const toggleTermFilter = (groupId, termId) => {
  const next = { ...activeFilters.value };
  const current = next[groupId] ? new Set(next[groupId]) : new Set();
  if (current.has(termId)) {
    current.delete(termId);
  } else {
    current.add(termId);
  }
  if (current.size === 0) {
    delete next[groupId];
  } else {
    next[groupId] = current;
  }
  activeFilters.value = next;
};

const getFilterCount = (groupId) => activeFilters.value[groupId]?.size || 0;

const clearGroupFilter = (groupId) => {
  if (!activeFilters.value[groupId]) return;
  const next = { ...activeFilters.value };
  delete next[groupId];
  activeFilters.value = next;
};

const clearAllFilters = () => {
  activeFilters.value = {};
  variantSearch.value = '';
};

const hasAnyActiveFilter = computed(() => {
  return Object.keys(activeFilters.value).length > 0 || variantSearch.value.trim() !== '';
});

// Show empty state when filters are active but match nothing
const isFilteredEmpty = computed(() => {
  return hasAnyActiveFilter.value
      && props.product.variants.length > 0
      && filteredVariants.value.length === 0;
});

// Filter variants by search query AND attribute filters (AND across groups, OR within a group)
const filteredVariants = computed(() => {
  let result = props.product.variants;

  // Attribute filters — AND across different groups, OR within a single group
  const filterGroupIds = Object.keys(activeFilters.value);
  if (filterGroupIds.length > 0) {
    result = result.filter(variant => {
      return filterGroupIds.every(gid => {
        const allowed = activeFilters.value[gid];
        if (!allowed || allowed.size === 0) return true;
        const rel = (variant.attr_map || []).find(r => parseInt(r.group_id) === parseInt(gid));
        return rel && allowed.has(parseInt(rel.term_id));
      });
    });
  }

  // Text search
  const q = variantSearch.value.trim().toLowerCase();
  if (q) {
    result = result.filter(variant => {
      const title = (variant.variation_title || '').toLowerCase();
      if (title.includes(q)) return true;
      const breadcrumb = (variant.attr_map || []).map(rel => {
        const grp = props.attributeGroups.find(g => g.id === parseInt(rel.group_id));
        if (!grp) return '';
        const term = (grp.terms || []).find(t => t.id === parseInt(rel.term_id));
        return term ? term.title.toLowerCase() : '';
      }).join(' ');
      return breadcrumb.includes(q);
    });
  }

  return result;
});

// Build grouped variant structure
const groupedVariants = computed(() => {
  if (!groupByGroupId.value) return null;

  // Normalise to int because BIGINT ids serialize as strings from PHP for
  // attributeGroups/attr_map but groupByGroupId may be set as a number by the
  // watcher above. Without parseInt on both sides, the lookup silently
  // returns undefined and groupedVariants stays null → table renders flat.
  const gid = parseInt(groupByGroupId.value, 10);
  const group = props.attributeGroups.find(g => parseInt(g.id, 10) === gid);
  if (!group) return null;

  const groups = [];
  const termMap = {};

  for (const variant of filteredVariants.value) {
    // attr_map stores group_id/term_id as strings, attributeGroups uses numbers
    const rel = (variant.attr_map || []).find(r => parseInt(r.group_id, 10) === gid);
    if (!rel) continue;
    const tid = parseInt(rel.term_id, 10);
    if (!termMap[tid]) {
      const term = (group.terms || []).find(t => parseInt(t.id, 10) === tid);
      termMap[tid] = { termId: tid, title: term ? term.title : 'Unknown', variants: [] };
      groups.push(termMap[tid]);
    }
    termMap[tid].variants.push(variant);
  }

  return groups;
});

const isGroupingActive = computed(() => !!groupByGroupId.value && groupedVariants.value && groupedVariants.value.length > 0);

// Snapshot the group count whenever a real grouped render lands. The
// skeleton's row loop uses this so the next mutation cycle's skeleton
// matches the prior layout's height, avoiding the page-shrink that made
// the WP admin sidebar appear to scroll.
watch(groupedVariants, (groups) => {
  if (Array.isArray(groups) && groups.length > 0) {
    lastKnownGroupCount.value = groups.length;
  }
}, { immediate: true });


// Strike-through pricing invariant. compare_price must be >= item_price
// (zero means "no compare price"). Delegates to the model's
// validateComparePrice so the same key (`variants.<i>.compare_price`)
// surfaces an inline message instead of the old behavior that silently
// zeroed the field. Shared by group / inline / bulk paths so the three
// enforcement points stay in sync.
const enforceComparePriceInvariant = (index) => {
  return props.productEditModel.validateComparePrice(index);
};

const onGroupPriceApply = (groupTermId, price) => {
  const group = groupedVariants.value ? groupedVariants.value.find(g => g.termId === groupTermId) : null;
  if (!group) return;

  // A cleared field (el-input type=number hands back '', null or undefined on
  // clear) means the merchant blanked the quick-set input, NOT a request to set
  // every variant in the group to $0. Treat it as a benign no-op — the previous
  // `parseFloat(price) || 0` fallback silently staged $0 across the whole group.
  if (price === '' || price === null || price === undefined) {
    return;
  }
  // Genuinely invalid input (non-numeric / negative) is a mistake — reject it
  // with the same message the bulk-bar set-price validation uses, rather than
  // clamping to 0 and silently corrupting the group's prices.
  // CENTS — the quick-set field is a PriceInput, so it has already crossed the
  // dollars boundary and item_price takes cents from here down.
  const centsPrice = parseFloat(price);
  if (isNaN(centsPrice) || centsPrice < 0) {
    Notify.error(translate('Please enter a valid price.'));
    return;
  }

  // When variant rows are selected, the group quick-set price applies only to
  // the selected variants in this group; with no selection it applies to all.
  const selectedInGroup = group.variants.filter(v => selectedVariants.value.includes(v.id));
  const targets = selectedInGroup.length ? selectedInGroup : group.variants;

  // Route each variant change through productEditModel.onChangePricing so it
  // lands in data.product_changes.variants — the top "Update" button picks
  // that up via the standard product save flow, so we no longer need a
  // separate sticky save bar below.
  targets.forEach(v => {
    const index = props.product.variants.findIndex(pv => pv.id === v.id);
    if (index === -1) return;
    props.productEditModel.onChangePricing('item_price', index, centsPrice);
    enforceComparePriceInvariant(index);
  });
};

// Re-check the strike-through invariant when an inline price field is
// committed (blur / Enter). Enforcement is deliberately NOT done on every
// @input keystroke: clearing compare_price mid-type would stop a merchant
// from ever entering a valid compare price whose leading digits are
// transiently at or below item_price (e.g. typing 150 while item_price is 100).
// Toast on failure — the inline cell only gets a red ring, the user
// needs to know why.
const onInlinePriceCommit = (variant) => {
  const index = props.product.variants.findIndex(v => v.id === variant.id);
  if (index === -1) return;
  const ok = enforceComparePriceInvariant(index);
  if (!ok) {
    Notify.error(translate('Compare price must be greater than or equal to item price.'));
  }
};

// Normalise media on every variants change — not just on mount. The DB shape of
// variant.media is the postmeta relation `{meta_value: [...]}`, but the template
// expects a flat array (`variant.media[0]?.url`). Without re-running this after
// the productEditModel reloader fires (e.g. after generateVariations), images
// appear to vanish even though the DB still holds them: media reverts to the
// raw object shape and `media[0]` is undefined → the empty-image placeholder
// renders.
watch(() => props.product.variants, (variants) => {
  if (!Array.isArray(variants)) return;
  variants.forEach(variant => {
    const mediaArray = variant.media ? (variant.media.meta_value || variant.media) : [];
    variant.media = Array.isArray(mediaArray) ? mediaArray : [];
  });
  if (pendingGroupExit.value) {
    pendingGroupExit.value = false;
    exitGroupEditMode();
  }
}, { immediate: true, deep: false });

// The wheel-to-scroll guard that used to live here is gone with the last
// `input[type=number]` in this table. It existed because a mouse wheel over a
// focused number input silently changes its value; PriceInput renders a text
// input, which has no such behaviour. Its querySelectorAll matched nothing
// after the conversion, so it was protecting the price cells in name only.

// Auto-pin grouping to the FIRST configured attribute (the "leader"). Fires
// on ANY leader change so dragging an option to the top — the merchant's
// signal that they want grouping by that attribute — flips Group by along
// with it. Also handles initial mount via immediate: true and the
// < 2-attribute edge case where grouping doesn't apply.
watch(
  () => configuredGroups.value[0]?.id,
  (leaderId) => {
    if (configuredGroups.value.length < 2 || !leaderId) {
      groupByGroupId.value = null;
      return;
    }
    // Always re-pin to the new leader, even if the previous selection was
    // still valid — when the merchant reorders options, the leadership change
    // IS the intent to regroup.
    groupByGroupId.value = parseInt(leaderId, 10);
  },
  { immediate: true }
);

// The leader-id watcher above only fires when [0].id changes. Deleting the
// second group while keeping the first group leaves the leader ID identical,
// so the watcher above never fires and groupByGroupId stays set. Watch the
// count separately so dropping to 1 group always clears the grouping.
watch(
  () => configuredGroups.value.length,
  (count) => {
    if (count < 2) {
      groupByGroupId.value = null;
    }
  }
);

// Defensive self-heal: if groupByGroupId ever lands on null OR on a group
// that's no longer configured (e.g. the chosen attribute was deleted),
// restore to the current leader so the table never falls back to a flat list
// against the merchant's intent.
watch(
  [groupByGroupId, configuredGroups],
  ([current, groups]) => {
    if (!Array.isArray(groups) || groups.length < 2) return;
    const leaderId = groups[0]?.id;
    if (!leaderId) return;
    const validIds = groups.map(g => parseInt(g.id, 10));
    if (current == null || !validIds.includes(parseInt(current, 10))) {
      groupByGroupId.value = parseInt(leaderId, 10);
    }
  },
  { deep: true }
);

// Inline price/compare-price edits now flow into productEditModel via
// onChangePricing so the top "Update" button persists them alongside any
// other product-level changes. No separate save bar / bulk update path is
// needed for this surface anymore.
// `value` is CENTS — the inline cells are PriceInputs, which own the
// dollars<->cents conversion. onChangePricing stores cents unchanged.
const onInlinePriceChange = (variant, field, value) => {
  const numeric = parseFloat(value) || 0;
  const index = props.product.variants.findIndex(v => v.id === variant.id);
  if (index === -1) return;
  props.productEditModel.onChangePricing(field, index, numeric);
}

const onMediaUploaded = (variant, value) => {
  const previous = variant.media;
  variant.media = value;

  beginBusy();
  Rest.post(`products/variants/${variant.id}/setMedia`, {
    media: value,
  }).then(response => {
    Notify.success(response.message || translate('Thumbnail set successfully'));
  }).catch(errors => {
    variant.media = previous;
    Notify.error(errors?.data?.message || errors?.message || translate('Failed to save thumbnail'));
  }).finally(endBusy);
}

// Apply an uploaded image to the group's variants. When variant rows are
// selected, only the selected variants in this group are updated; with no
// selection it applies to every variant. Chunk the writes the same way
// deleteSelectedVariants does so a 200-variant group doesn't fan out 200
// simultaneous admin-API writes and overwhelm the server / browser pool.
const onGroupMediaUploaded = async (group, value) => {
  const selectedInGroup = group.variants.filter(v => selectedVariants.value.includes(v.id));
  const targets = selectedInGroup.length ? selectedInGroup : group.variants;

  targets.forEach(variant => { variant.media = value; });
  const ids = targets.map(v => v.id);
  const chunkSize = 5;
  let failed = 0;
  beginBusy();
  try {
    for (let i = 0; i < ids.length; i += chunkSize) {
      const results = await Promise.allSettled(
        ids.slice(i, i + chunkSize).map(id => Rest.post(`products/variants/${id}/setMedia`, { media: value }))
      );
      failed += results.filter(r => r.status === 'rejected').length;
    }
  } finally {
    endBusy();
  }
  if (failed === 0) {
    /* translators: %1$s: number of variants the bulk image was applied to */
    Notify.success(translate('Thumbnail applied to %1$s variants', targets.length));
  } else {
    /* translators: %1$s: number of variants where the image failed to save */
    Notify.error(translate('Failed to apply thumbnail to %1$s variants', failed));
  }
}

const isAllSelected = computed({
  get() {
    const visibleIds = filteredVariants.value.map(v => v.id);
    if (visibleIds.length === 0) return false;
    return visibleIds.every(id => selectedVariants.value.includes(id));
  },
  set(val) {
    const visibleIds = filteredVariants.value.map(v => v.id);
    if (val) {
      // Union: keep existing selections, add visible ones
      const merged = new Set([...selectedVariants.value, ...visibleIds]);
      selectedVariants.value = Array.from(merged);
    } else {
      // Deselect only the visible ones; keep selections that are currently hidden
      const visibleSet = new Set(visibleIds);
      selectedVariants.value = selectedVariants.value.filter(id => !visibleSet.has(id));
    }
  }
})

// Indeterminate: some but not all visible variants are selected
const isSelectionIndeterminate = computed(() => {
  const visibleIds = filteredVariants.value.map(v => v.id);
  if (visibleIds.length === 0) return false;
  const selectedVisible = visibleIds.filter(id => selectedVariants.value.includes(id)).length;
  return selectedVisible > 0 && selectedVisible < visibleIds.length;
});

// Hard cap on a single bulk-update payload. Variant count is already bounded
// by the MAX_COMBINATIONS generation cap, so this is defense-in-depth: it keeps
// one bulk POST bounded even if that upstream cap is ever raised.
const MAX_BULK_VARIANTS = 200;

const applyBulkAction = () => {
  // Drop re-entrant clicks while a bulk request is already in flight.
  if (savingBulk.value || !bulkAction.value || selectedVariants.value.length === 0) {
    return;
  }

  if (bulkAction.value === 'delete') {
    Confirmation.confirmDeleteWithInput(
        'delete',
        /* translators: %1$s: number of selected variants to be deleted */
        translate('Are you sure you want to delete %1$s variants?', selectedVariants.value.length),
    ).then(() => {
      deleteSelectedVariants();
    }).catch(() => {});
    return;
  }

  const isPriceAction = bulkAction.value === 'set_price';
  // CENTS — the bulk bar's value field is a PriceInput, and
  // products/variants/bulk-update reads cents.
  let bulkPrice = null;
  if (isPriceAction) {
    bulkPrice = parseFloat(bulkValue.value);
    if (bulkValue.value === '' || isNaN(bulkPrice) || bulkPrice < 0) {
      Notify.error(translate('Please enter a valid price.'));
      return;
    }
  }

  // Strike-through pricing rule — raising item_price above an existing
  // compare_price would leave compare_price < item_price. Abort the whole
  // batch so the merchant adjusts compare_price first instead of silently
  // saving a now-invalid pair.
  if (isPriceAction) {
    const violators = selectedVariants.value
        .slice(0, MAX_BULK_VARIANTS)
        .map(id => props.product.variants.find(v => v.id === id))
        .filter(v => {
          if (!v) return false;
          const compare = parseFloat(v.compare_price);
          return !isNaN(compare) && compare > 0 && compare < bulkPrice;
        });
    if (violators.length) {
      Notify.error(translate('Compare price must be greater than or equal to item price.'));
      return;
    }
  }

  const updates = selectedVariants.value.slice(0, MAX_BULK_VARIANTS).map(id => {
    const update = {id};
    if (bulkAction.value === 'set_price') {
      update.item_price = bulkPrice;
    } else if (bulkAction.value === 'set_status_active') {
      update.item_status = 'active';
    } else if (bulkAction.value === 'set_status_inactive') {
      update.item_status = 'inactive';
    }
    return update;
  });

  savingBulk.value = true;
  props.productEditModel.bulkUpdateVariants(updates).then((response) => {
    Notify.success(response?.message || translate('Variants updated successfully'));
    props.productEditModel.data.reloader();
    selectedVariants.value = [];
    bulkAction.value = '';
    bulkValue.value = '';
  }).catch((errors) => {
    Notify.error(errors?.data?.message || translate('Bulk action failed'));
  }).finally(() => {
    savingBulk.value = false;
  });
}

const deleteSelectedVariants = async () => {
  const ids = [...selectedVariants.value];
  const chunkSize = 5;
  beginBusy();
  try {
    for (let i = 0; i < ids.length; i += chunkSize) {
      await Promise.all(ids.slice(i, i + chunkSize).map(id => Rest.delete(`products/variants/${id}`)));
    }
    Notify.success(translate('Variants deleted successfully'));
    props.productEditModel.data.reloader();
    selectedVariants.value = [];
  } catch (e) {
    Notify.error(translate('Failed to delete some variants'));
  } finally {
    endBusy();
  }
}

// Flip a single variant between active / inactive from its kebab menu. Reuses
// the same bulkUpdateVariants path as the bulk "Set Active / Set Inactive"
// action so the server-side status write stays in one place. Delete was removed
// from the per-row menu (the bulk "Delete Selected" action still covers it), so
// this is now the kebab's primary state action.
const toggleVariantStatus = (variant) => {
  if (!variant || !variant.id) {
    return;
  }
  const nextStatus = variant.item_status === 'inactive' ? 'active' : 'inactive';
  beginBusy();
  props.productEditModel.bulkUpdateVariants([{id: variant.id, item_status: nextStatus}])
      .then((response) => {
        // Reflect the new status locally so the badge/menu label flip is
        // immediate; the reloader then re-syncs from the server.
        variant.item_status = nextStatus;
        Notify.success(response?.message || translate('Variant updated successfully'));
        props.productEditModel.data.reloader();
      })
      .catch((errors) => {
        Notify.error(errors?.data?.message || translate('Failed to update variant'));
      })
      .finally(endBusy);
}

const getAttrBreadcrumb = (variant) => {
  const attrMap = variant.attr_map || [];
  if (!attrMap.length) return '';
  const labels = attrMap.map(rel => {
    const group = props.attributeGroups.find(g => g.id === parseInt(rel.group_id));
    if (!group) return null;
    const term = (group.terms || []).find(t => t.id === parseInt(rel.term_id));
    return term ? term.title : null;
  }).filter(Boolean);
  return labels.join(' / ');
};

// Breadcrumb minus one attribute — used for variants rendered INSIDE a grouped
// row/header. Without this, the drawer's nav under "Classic" would show
// "Grey / Classic / Monthly" — repeating the group's own label. Stripping the
// grouped attribute makes the child labels read as "Grey / Monthly", matching
// the AdvancedVariationGroupedTable's existing convention on the main page.
const getAttrBreadcrumbExcluding = (variant, excludeGroupId) => {
  const attrMap = variant.attr_map || [];
  if (!attrMap.length) return '';
  const excludeId = parseInt(excludeGroupId);
  const labels = attrMap
      .filter(rel => parseInt(rel.group_id) !== excludeId)
      .map(rel => {
        const group = props.attributeGroups.find(g => g.id === parseInt(rel.group_id));
        if (!group) return null;
        const term = (group.terms || []).find(t => t.id === parseInt(rel.term_id));
        return term ? term.title : null;
      })
      .filter(Boolean);
  return labels.join(' / ');
};

const openEditDrawer = (variant) => {
  const index = props.product.variants.findIndex(v => v.id === variant.id);
  if (index === -1) return;
  props.productEditModel.setValidationErrors({});
  selectedVariantIndex.value = index;
  showSharedDrawer.value = true;
}

const closeSharedDrawer = () => {
  props.productEditModel.setValidationErrors({});
  isCurrentVariationDirty.value = false;
  showSharedDrawer.value = false;
  selectedVariantIndex.value = null;
  isGroupEditMode.value = false;
  editingGroupData.value = null;
  pendingGroupExit.value = false;
}

const exitGroupEditMode = () => {
  props.productEditModel.setValidationErrors({});
  isCurrentVariationDirty.value = false;
  isGroupEditMode.value = false;
  editingGroupData.value = null;
  groupIncludedKeys.value = new Set();
}

const activeGroupVariantIds = computed(() => {
  if (!editingGroupData.value || !editingGroupData.value.items) return [];
  return editingGroupData.value.items
    .filter(item => groupIncludedKeys.value.has(item.key))
    .map(item => item.variant && item.variant.id ? item.variant.id : null)
    .filter(Boolean);
});

const toggleGroupItem = (key) => {
  const next = new Set(groupIncludedKeys.value);
  if (next.has(key)) {
    next.delete(key);
  } else {
    next.add(key);
  }
  groupIncludedKeys.value = next;
};

// --- Dirty-draft confirmation flow (mirrors ProductPricingTable) -----------

const resetDirtyDialog = () => {
  showDirtyDraftDialog.value = false;
  dirtyDialogSubmitting.value = false;
  pendingDirtyAction.value = null;
};

const hasDirtyDrafts = () => {
  return pricingFormRef.value?.hasDirtyDrafts?.() ?? false;
};

const handleDirtyStateChange = (isDirty) => {
  isCurrentVariationDirty.value = isDirty;
};

// Runs the action that was deferred while the dirty dialog was open — i.e. the
// el-drawer's own `done()` callback, which lets the close finally proceed.
const executePendingDirtyAction = () => {
  const action = pendingDirtyAction.value;
  resetDirtyDialog();
  if (!action) {
    return;
  }
  if (action.type === 'close') {
    if (typeof action.done === 'function') {
      action.done();
      return;
    }
    closeSharedDrawer();
  }
};

const handleContinueEditing = () => {
  resetDirtyDialog();
};

const handleDiscardDirtyDrafts = async () => {
  await pricingFormRef.value?.discardDrafts?.();
  isCurrentVariationDirty.value = false;
  executePendingDirtyAction();
};

const handleSaveDirtyDraft = async () => {
  if (!pricingFormRef.value?.saveCurrentDraft || dirtyDialogSubmitting.value) {
    return;
  }
  dirtyDialogSubmitting.value = true;
  const result = await pricingFormRef.value.saveCurrentDraft();
  if (result) {
    isCurrentVariationDirty.value = false;
    executePendingDirtyAction();
    return;
  }
  // Save failed (validation) — keep the drawer open on the dirty form.
  dirtyDialogSubmitting.value = false;
  handleContinueEditing();
};

// el-drawer before-close hook. Element Plus passes a `done` callback that must
// be invoked to let the drawer actually close. If the form is dirty we hold
// `done` until the merchant resolves the save/discard dialog.
const handleSharedDrawerBeforeClose = (done) => {
  if (!hasDirtyDrafts()) {
    done();
    return;
  }
  pendingDirtyAction.value = { type: 'close', done };
  showDirtyDraftDialog.value = true;
};

// The form's own Cancel/close emit — route it through the same guard.
// In group-edit mode, Cancel/Discard goes back to the single-variant view
// instead of closing the drawer entirely.
const requestCloseSharedDrawer = () => {
  if (isGroupEditMode.value) {
    handleSharedDrawerBeforeClose(exitGroupEditMode);
    return;
  }
  handleSharedDrawerBeforeClose(closeSharedDrawer);
};

// Fired after the form saves a variant (or a group bulk-update).
// Group mode: close the drawer and reload so the updated variant rows reflect
// the server changes. Single-variant mode: just track the saved index.
const handleVariantSaved = (payload) => {
  isCurrentVariationDirty.value = false;
  props.productEditModel.setValidationErrors({});
  if (isGroupEditMode.value) {
    pendingGroupExit.value = true;
    props.productEditModel.data.reloader();
    return;
  }
  if (payload && typeof payload.index === 'number' && payload.index >= 0) {
    selectedVariantIndex.value = payload.index;
  }
};

// Convert the groupedVariants shape {termId, title, variants[]} emitted by
// AdvancedVariationGroupedTable into the nav-item shape {termId, title, count,
// items[]} that handleGroupEdit expects, then open group-edit mode.
const handleGroupEditFromTable = (group) => {
  const navItem = groupedVariationNavItems.value
      ? groupedVariationNavItems.value.find(g => g.termId === group.termId)
      : null;
  if (navItem) handleGroupEdit(navItem);
};

// Open the edit drawer in group-edit mode. The form loads the first variant as
// a template; the merchant edits shared fields (price, shipping, tax) and
// clicks "Update N variants" to apply them to every variant in the group.
const handleGroupEdit = (group) => {
  if (!group || !group.items || !group.items.length) return;
  if (isCurrentVariationDirty.value) return;
  editingGroupData.value = group;
  groupIncludedKeys.value = new Set(group.items.map(item => item.key));
  isGroupEditMode.value = true;
  const firstItem = group.items[0];
  props.productEditModel.setValidationErrors({});
  selectedVariantIndex.value = typeof firstItem.index === 'number' ? firstItem.index : null;
  showSharedDrawer.value = true;
};

// --- Shared variation drawer wiring ---------------------------------------
// Reuse the same sidebar UX as the simple-variation drawer: list every variant
// in the left aside, click to switch the active form. Advanced variants are
// auto-generated from option combinations, so we expose only "update" mode —
// no draft/create/duplicate paths.

const viewportWidth = ref(typeof window !== 'undefined' ? window.innerWidth : 1280);
let onResize = null;

onMounted(() => {
  if (typeof window === 'undefined') return;
  onResize = () => { viewportWidth.value = window.innerWidth; };
  window.addEventListener('resize', onResize);
});

onUnmounted(() => {
  if (onResize) {
    window.removeEventListener('resize', onResize);
    onResize = null;
  }
});

const useCompactVariantNav = computed(() => viewportWidth.value <= 900);

const sharedDrawerSize = computed(() => {
  if (viewportWidth.value <= 640) return '100%';
  if (viewportWidth.value <= 900) return '95%';
  return '920px';
});

const variationNavItems = computed(() => {
  // Use filteredVariants (not raw product.variants) so the drawer's mobile
  // compact nav and the desktop flat-fallback both narrow with the same
  // search/filter state the main table uses. The original index against
  // props.product.variants is preserved because selectVariation routes
  // through `index` and the form binds by that index.
  const variantIndex = new Map();
  (props.product.variants || []).forEach((v, i) => {
    if (v && v.id != null) variantIndex.set(v.id, i);
  });

  return (filteredVariants.value || []).map((variant) => {
    const index = variantIndex.get(variant?.id);
    return {
      key: variant?.id ?? `variant-${index}`,
      index,
      variant,
      label: getAttrBreadcrumb(variant) || variant?.variation_title || `${translate('Variant')} ${index + 1}`,
      meta: variant?.item_price !== undefined && variant?.item_price !== null && variant?.item_price !== ''
        ? formatNumber(variant.item_price, true)
        : translate('No price set'),
      modeType: 'update',
      isDraft: false,
    };
  });
});

// Grouped variant nav for the shared-pricing drawer sidebar. Reuses the same
// groupedVariants bucketing the main table already does (by the first configured
// attribute), but maps each child variant to the nav-item shape VariantNavList
// expects. Child labels strip the grouping attribute so the header isn't
// repeated in every row (e.g. under "Classic" → "Grey / Monthly", not
// "Grey / Classic / Monthly"). Returns null when grouping isn't active so the
// flat list path stays.
const groupedVariationNavItems = computed(() => {
  if (!groupedVariants.value || groupedVariants.value.length === 0) return null;

  const variantIndex = new Map();
  (props.product.variants || []).forEach((v, i) => {
    if (v && v.id != null) variantIndex.set(v.id, i);
  });

  const skipGroupId = groupByGroupId.value;

  const toNavItem = (variant) => {
    const idx = variantIndex.get(variant?.id);
    const breadcrumb = skipGroupId
        ? getAttrBreadcrumbExcluding(variant, skipGroupId)
        : getAttrBreadcrumb(variant);
    return {
      key: variant?.id ?? `variant-${idx}`,
      index: idx,
      variant,
      label: breadcrumb || variant?.variation_title || `${translate('Variant')} ${idx + 1}`,
      meta: variant?.item_price !== undefined && variant?.item_price !== null && variant?.item_price !== ''
        ? formatNumber(variant.item_price, true)
        : translate('No price set'),
      modeType: 'update',
      isDraft: false,
    };
  };

  return groupedVariants.value.map(group => ({
    termId: group.termId,
    title: group.title,
    count: group.variants.length,
    items: group.variants.map(toNavItem),
  }));
});

const activeVariationKey = computed(() => {
  const v = props.product.variants?.[selectedVariantIndex.value];
  return v?.id ?? `variant-${selectedVariantIndex.value ?? 0}`;
});

// A variant's value for a single attribute group (e.g. the "Material" group
// → "Cotton"). Used as the heading of the drawer title.
const getAttrTermLabel = (variant, groupId) => {
  // Normalise every id with parseInt before comparing — BIGINT ids serialize
  // from PHP as strings on attributeGroups/terms while groupId/attr_map ids
  // can be numbers; a strict === then silently misses and blanks the heading.
  const gid = parseInt(groupId, 10);
  const rel = (variant.attr_map || []).find(r => parseInt(r.group_id, 10) === gid);
  if (!rel) return '';
  const group = props.attributeGroups.find(g => parseInt(g.id, 10) === gid);
  if (!group) return '';
  const termId = parseInt(rel.term_id, 10);
  const term = (group.terms || []).find(t => parseInt(t.id, 10) === termId);
  return term ? term.title : '';
};

// Title for the variant open in the drawer. When the list is grouped (e.g. by
// Material) the group value is the heading ("Cotton") and the remaining
// attributes sit below ("Red / Solid") — mirroring the nav. With no grouping,
// the detail line carries the full breadcrumb and there is no heading.
const selectedVariantTitleInfo = computed(() => {
  if (selectedVariantIndex.value === null) return {group: '', detail: ''};
  const v = props.product.variants?.[selectedVariantIndex.value];
  if (!v) return {group: '', detail: ''};
  if (groupByGroupId.value) {
    return {
      group: getAttrTermLabel(v, groupByGroupId.value),
      detail: getAttrBreadcrumbExcluding(v, groupByGroupId.value),
    };
  }
  return {group: '', detail: getAttrBreadcrumb(v)};
});

const selectVariation = (item) => {
  if (!item) return;
  // Block switching to another variant while the current form has unsaved
  // edits — the merchant must save or discard first (VariantNavList shows the
  // dirty indicator). Matches ProductPricingTable.
  if (isCurrentVariationDirty.value && activeVariationKey.value !== item.key) {
    return;
  }
  if (typeof item.index === 'number') {
    props.productEditModel.setValidationErrors({});
    selectedVariantIndex.value = item.index;
  }
}

// VariantNavList renders a per-row dropdown that calls this prop in its template
// (`:disabled="!canCopyDirectCheckout(item)"`). Without a real function the call
// throws inside Vue's reactivity loop and Element Plus's ElDropdown re-renders
// recursively. Mirror the simple-variation predicate so the menu item behaves
// the same way for advanced variants.
const canCopyDirectCheckout = (item) => {
  return !!item?.variant?.id && ['publish', 'private'].includes(props.product?.post_status);
}

const getDirectCheckoutUrl = (item) => {
  return `${AppConfig.get('frontend_url')}=instant_checkout&item_id=${item.variant.id}&quantity=1`;
}

// VariantNavList emits a `command` for each kebab-menu action. The advanced
// drawer wires it here — copy the variation id / direct-checkout link to the
// clipboard, and flip the variant active/inactive. Without this handler bound
// the whole kebab menu was inert.
const handleVariantNavCommand = (command, item) => {
  if (!item || !command) {
    return;
  }

  if (command === 'copy_variation_id' && item.variant?.id) {
    Clipboard.copy(String(item.variant.id), {
      successMessage: translate('Variation ID copied to clipboard')
    });
    return;
  }

  if (command === 'copy_direct_checkout' && canCopyDirectCheckout(item)) {
    Clipboard.copy(getDirectCheckoutUrl(item), {
      successMessage: translate('Direct checkout link copied to clipboard')
    });
    return;
  }

  if (command === 'toggle_status' && item.variant?.id) {
    toggleVariantStatus(item.variant);
  }
}

</script>

<template>
  <div class="fct-adv-variant-table-wrap">

    <div class="flex items-center flex-wrap justify-between gap-2 mb-3">
        <!-- Group by toolbar (only when ≥2 attribute groups) -->
        <AdvancedVariationGroupBar
            :product="product"
            :attribute-groups="attributeGroups"
            v-model="groupByGroupId"
        />

        <div class="flex items-center flex-wrap gap-2 ml-auto">
          <!-- Collapsed: icon toggle -->
          <button
              v-if="!filtersExpanded"
              type="button"
              class="fct-adv-filter-panel-toggle"
              :aria-label="translate('Search and filter variants')"
              @click="toggleFiltersPanel"
          >
            <DynamicIcon name="Search"/>
            <DynamicIcon name="Filter"/>
          </button>

          <!-- Expanded: cancel button -->
          <el-button
              v-else
              @click="cancelFiltersPanel"
          >
            {{ translate('Cancel') }}
          </el-button>
        </div>
    </div>

    <!-- Expanded panel: full-width search + chips -->
    <Transition
      @before-enter="onFilterPanelBeforeEnter"
      @enter="onFilterPanelEnter"
      @after-enter="onFilterPanelAfterEnter"
      @leave="onFilterPanelLeave"
      @after-leave="onFilterPanelAfterLeave"
    >
    <div v-if="filtersExpanded" class="fct-adv-filters-panel-wrap">
      <div class="fct-adv-variant-search-row">
        <el-input
            v-model="variantSearch"
            :placeholder="translate('Search variants')"
            size="small"
            clearable
        >
          <template #prefix>
            <DynamicIcon name="Search"/>
          </template>
        </el-input>
      </div>

      <!-- Per-attribute filter chips -->
      <div v-if="filterChipGroups.length > 0" class="fct-adv-variant-filters">
        <el-popover
          v-for="group in filterChipGroups"
          :key="group.id"
          placement="bottom-start"
          :width="220"
          trigger="click"
          popper-class="fct-adv-filter-popover"
      >
        <template #reference>
          <button
              class="fct-adv-filter-chip"
              :class="{ 'is-active': getFilterCount(group.id) > 0 }"
              :aria-pressed="getFilterCount(group.id) > 0"
              type="button"
          >
            <span>{{ group.title }}</span>
            <span v-if="getFilterCount(group.id) > 0" class="fct-adv-filter-chip__count">
              {{ getFilterCount(group.id) }}
            </span>
            <DynamicIcon name="ChevronDown"/>
          </button>
        </template>
        <div class="fct-adv-filter-options">
          <div class="fct-adv-filter-options__header">
            <span>{{
              /* translators: %1$s: attribute group title (e.g. "Color", "Size") */
              translate('Filter by %1$s', group.title)
            }}</span>
            <button
                v-if="getFilterCount(group.id) > 0"
                type="button"
                class="fct-adv-filter-clear"
                @click="clearGroupFilter(group.id)"
            >
              {{ translate('Clear') }}
            </button>
          </div>
          <ul class="fct-adv-filter-options__list">
            <li v-for="term in group.terms" :key="term.id">
              <el-checkbox
                  :model-value="isTermActive(group.id, term.id)"
                  @change="toggleTermFilter(group.id, term.id)"
              >
                {{ term.title }}
              </el-checkbox>
            </li>
          </ul>
        </div>
      </el-popover>

      <span
          v-if="hasAnyActiveFilter && !isFilteredEmpty"
          class="fct-adv-filter-count"
      >
        {{ translate('Showing %1$s of %2$s variants', filteredVariants.length, product.variants.length) }}
      </span>

      <button
          v-if="hasAnyActiveFilter"
          type="button"
          class="fct-adv-filter-clear-all"
          @click="clearAllFilters"
      >
        {{ translate('Clear filters') }}
      </button>
    </div>
    </div>
    </Transition>

    <!-- Bulk action bar — its own row, only when selection exists -->
    <AdvancedVariationBulkBar
        :selected-count="selectedVariants.length"
        v-model:bulk-action="bulkAction"
        v-model:bulk-value="bulkValue"
        :saving="savingBulk"
        @apply="applyBulkAction"
        @clear="selectedVariants = []"
    />

    <!-- Empty state when filters match nothing -->
    <div v-if="isFilteredEmpty" class="fct-adv-variant-empty">
      <DynamicIcon name="Search"/>
      <p class="fct-adv-variant-empty__title">{{ translate('No variants match your filters') }}</p>
      <p class="fct-adv-variant-empty__hint">{{ translate('Try a different search term or clear some filters.') }}</p>
      <el-button size="small" @click="clearAllFilters">
        {{ translate('Clear filters') }}
      </el-button>
    </div>

    <!-- Top-level skeleton during any mutation — owns the table slot so
         the actual grouped/flat tables don't mount/unmount rapidly as
         isGroupingActive flips false→true around the post-save attr_map
         arrival window. (Toggling v-if on the live tables mid-flush
         caused a Vue "Cannot read properties of null (reading
         'emitsOptions')" patch error and let the flat table flash with
         real rows beside the skeleton.) -->
    <div
      v-if="isMutating && !isFilteredEmpty"
      class="fct-grouped-variant-table hide-on-mobile"
      role="status"
      aria-live="polite"
      :aria-label="$t('Updating variants')"
    >
      <table>
        <colgroup>
          <col width="40"><col width="50"><col><col width="160"><col width="160"><col width="100">
        </colgroup>
        <thead>
          <tr>
            <th><span class="fct-skel-block fct-skel-row-check"/></th>
            <th>{{ $t('Image') }}</th>
            <th>{{ $t('Variant') }}</th>
            <th>{{ $t('Price') }}</th>
            <th>{{ $t('Compare at price') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="fct-grouped-variant-skeleton">
          <tr v-for="i in Math.min(lastKnownGroupCount, 8)" :key="'wrap-skel-' + i">
            <td><span class="fct-skel-block fct-skel-row-check"/></td>
            <td><span class="fct-skel-block fct-skel-row-img"/></td>
            <td><span class="fct-skel-block fct-skel-row-title"/></td>
            <td><span class="fct-skel-block fct-skel-row-price"/></td>
            <td><span class="fct-skel-block fct-skel-row-price"/></td>
            <td><span class="fct-skel-block fct-skel-row-action"/></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Desktop Table: Grouped View — only renders when NOT mutating, so
         the skeleton above fully owns the slot during transitions. -->
    <AdvancedVariationGroupedTable
        v-else-if="isGroupingActive && !isFilteredEmpty"
        :product="product"
        :product-edit-model="productEditModel"
        :attribute-groups="attributeGroups"
        :grouped-variants="groupedVariants"
        :selected-variants="selectedVariants"
        :is-all-selected="isAllSelected"
        :is-selection-indeterminate="isSelectionIndeterminate"
        :group-by-id="groupByGroupId"
        :expanded-groups="expandedGroups"
        @update:selected-variants="val => selectedVariants = val"
        @update:is-all-selected="val => isAllSelected = val"
        @update:expanded-groups="val => expandedGroups = val"
        @edit-variant="openEditDrawer"
        @edit-group="handleGroupEditFromTable"
        @toggle-status="toggleVariantStatus"
        @inline-price-change="onInlinePriceChange"
        @inline-price-commit="onInlinePriceCommit"
        @media-uploaded="onMediaUploaded"
        @group-price-apply="onGroupPriceApply"
        @group-media-uploaded="onGroupMediaUploaded"
    />

    <!-- Desktop Table: Flat View (no grouping or single attribute) —
         only renders when NOT mutating. The wrapper-level skeleton above
         covers the transition. -->
    <AdvancedVariationFlatTable
        v-else-if="!isGroupingActive && !isFilteredEmpty"
        :product="product"
        :product-edit-model="productEditModel"
        :variants="filteredVariants"
        :attribute-groups="attributeGroups"
        :selected-variants="selectedVariants"
        :is-all-selected="isAllSelected"
        :is-selection-indeterminate="isSelectionIndeterminate"
        @update:selected-variants="val => selectedVariants = val"
        @update:is-all-selected="val => isAllSelected = val"
        @edit-variant="openEditDrawer"
        @toggle-status="toggleVariantStatus"
        @inline-price-change="onInlinePriceChange"
        @inline-price-commit="onInlinePriceCommit"
        @media-uploaded="onMediaUploaded"
    />

    <!-- Mobile View — mirrors the desktop FlatTable above, which renders
         `filteredVariants` so search + attribute filters narrow what the
         merchant sees. Using `product.variants` here would silently bypass
         every filter on small screens. Drag-reorder was removed from advanced
         variation (desktop and mobile) — variants keep their generated order. -->
    <!-- Flat mobile list — only when grouping is OFF. When grouping is active the
         AdvancedVariationGroupedTable renders its own grouped mobile view, so
         gating this on !isGroupingActive avoids a duplicate set of variant rows. -->
    <div v-if="!isGroupingActive" class="fct-table-draggable fct-product-pricing-table-mobile-wrap">
        <div
            v-for="variant in filteredVariants"
            :key="variant.id"
            class="fct-product-pricing-table-mobile-row"
        >
          <div class="fct-product-pricing-table-mobile-item-inner">
            <div class="fct-product-pricing-table-item">
              <div class="media relative">
                <div class="absolute top-0 left-0 w-full h-full z-10 rounded opacity-0">
                  <!-- :attachments="[]" keeps the MediaButton click target
                       visible after an image is uploaded; with the variant's
                       saved media passed in, Attachments hides every upload
                       button and the merchant can't replace the image. The
                       saved thumbnail is rendered by the sibling <img> below. -->
                  <Attachments
                      :previewImage="false"
                      :showList="false"
                      :multiple="false"
                      :showDeleteButton="false"
                      :attachments="[]"
                      @media-uploaded="value => onMediaUploaded(variant, value)"
                  />
                </div>
                <div v-if="variant?.media?.length > 0"
                     class="absolute top-0 left-0 w-full h-full z-0 rounded">
                  <img :src="variant.media[0]?.url" :alt="variant.variation_title"
                       class="!object-contain rounded border border-solid border-gray-outline dark:border-dark-400"/>
                </div>
                <img v-else :src="appVars.asset_url + 'images/empty-image.svg'" :alt="translate('No Image')"/>
              </div>

              <div class="fct-product-pricing-table-item-content">
                <div class="title">
                  {{ variant.variation_title }}
                  <span v-if="variant.item_status === 'inactive'" class="fct-variant-badge fct-variant-badge-inactive">
                    {{ translate('Inactive') }}
                  </span>
                </div>
                <div class="fct-adv-attr-breadcrumb">{{ getAttrBreadcrumb(variant) || '—' }}</div>
                <ul>
                  <li>
                    <!-- formatNumber(x, true) rather than a hand-placed sign:
                         the sign was always prefixed, so a shop with
                         currency_position 'after' rendered this list as "$19.99"
                         while the grouped mobile list rendered the same variant
                         as "19,99€". -->
                    <div class="compare-price" v-if="variant.compare_price">
                      {{ formatNumber(variant.compare_price, true) }}
                    </div>
                    <div class="price">
                      {{ formatNumber(variant.item_price, true) }}
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <div class="fct-product-pricing-table-item-action">
              <div class="fct-btn-group sm">
                <IconButton size="small" tag="button" @click="openEditDrawer(variant)">
                  <DynamicIcon name="Edit"/>
                </IconButton>
                <el-dropdown class="fct-more-option-wrap" popper-class="fct-dropdown" trigger="click"
                             @command="(command) => {
                               if (command === 'toggle_status') {
                                 toggleVariantStatus(variant);
                               }
                             }">
                  <button type="button" class="more-btn" :aria-label="translate('Variation actions')">
                    <DynamicIcon name="More"/>
                  </button>
                  <template #dropdown>
                    <el-dropdown-menu>
                      <el-dropdown-item>
                        <CopyToClipboard
                            v-if="variant.id"
                            class="fct-copy-wrap-inline"
                            :text="String(variant.id)"
                            showMode="icon_with_text"
                            :buttonText="translate('Copy Variation ID')"
                        />
                      </el-dropdown-item>
                      <el-dropdown-item v-if="variant.id"
                                        command="toggle_status"
                                        :class="{ 'item-destructive': variant.item_status !== 'inactive' }">
                        <DynamicIcon :name="variant.item_status === 'inactive' ? 'CheckCircle' : 'InActive'"/>
                        {{ variant.item_status === 'inactive' ? translate('Set Active') : translate('Set Inactive') }}
                      </el-dropdown-item>
                    </el-dropdown-menu>
                  </template>
                </el-dropdown>
              </div>
            </div>
          </div>
        </div>
    </div>

    <!-- Edit Variant Drawer — uses the shared layout (left variant nav + right form),
         matching the simple-variation drawer. Advanced variants are auto-generated, so
         the "Add more" affordance is intentionally omitted. -->
    <el-drawer
        v-model="showSharedDrawer"
        :title="translate('Pricing')"
        :size="sharedDrawerSize"
        :append-to-body="true"
        :close-on-click-modal="true"
        :close-on-press-escape="false"
        :destroy-on-close="true"
        :before-close="handleSharedDrawerBeforeClose"
        class="fct-shared-variant-drawer"
        @close="closeSharedDrawer"
    >
      <div class="fct-shared-variant-drawer__layout">
        <!-- Group-edit mode: show a compact group summary in the aside -->
        <aside v-if="!useCompactVariantNav && isGroupEditMode" class="fct-shared-variant-nav">
          <VariantNavSummary :product="product" />
          <div class="fct-group-edit-aside">
            <p class="fct-group-edit-aside__label flex items-center gap-2">
                {{ translate('Editing group') }}:
                <span class="fct-group-edit-aside__title">{{ editingGroupData?.title }}</span>
            </p>
            <p class="fct-group-edit-aside__hint">
              {{
                /* translators: %1$s: number of selected variants, %2$s: total variants in the group */
                translate('Changes will apply to %1$s of %2$s variants in this group.', activeGroupVariantIds.length, editingGroupData?.count)
              }}
            </p>
            <ul class="fct-group-edit-aside__list">
              <li v-for="item in editingGroupData?.items" :key="item.key" class="fct-group-edit-aside__item">
                <el-checkbox
                  :model-value="groupIncludedKeys.has(item.key)"
                  @change="toggleGroupItem(item.key)"
                >
                  {{ item.label }}
                </el-checkbox>
              </li>
            </ul>
          </div>
        </aside>

        <!-- Normal single-variant nav -->
        <aside v-else-if="!useCompactVariantNav" class="fct-shared-variant-nav">
          <VariantNavSummary :product="product" />

          <!-- Search + per-attribute filter chips. Shares state with the main
               table (variantSearch, activeFilters) so filtering in either place
               narrows the grouped nav consistently. Kept compact for the narrow
               sidebar — search is full-width, chips wrap underneath. -->
          <div class="fct-shared-variant-nav__filters">
            <el-input
                v-model="variantSearch"
                :placeholder="translate('Search variants')"
                size="small"
                clearable
                class="fct-shared-variant-nav__search"
            >
              <template #prefix>
                <DynamicIcon name="Search"/>
              </template>
            </el-input>

            <div v-if="filterChipGroups.length > 0" class="fct-shared-variant-nav__chips">
              <el-popover
                  v-for="group in filterChipGroups"
                  :key="'nav-chip-' + group.id"
                  placement="bottom-start"
                  :width="220"
                  trigger="click"
                  popper-class="fct-adv-filter-popover"
              >
                <template #reference>
                  <button
                      class="fct-adv-filter-chip"
                      :class="{ 'is-active': getFilterCount(group.id) > 0 }"
                      :aria-pressed="getFilterCount(group.id) > 0"
                      type="button"
                  >
                    <span>{{ group.title }}</span>
                    <span v-if="getFilterCount(group.id) > 0" class="fct-adv-filter-chip__count">
                      {{ getFilterCount(group.id) }}
                    </span>
                    <DynamicIcon name="ChevronDown"/>
                  </button>
                </template>
                <div class="fct-adv-filter-options">
                  <div class="fct-adv-filter-options__header">
                    <span>{{
                      /* translators: %1$s: attribute group title (e.g. "Color", "Size") */
                      translate('Filter by %1$s', group.title)
                    }}</span>
                    <button
                        v-if="getFilterCount(group.id) > 0"
                        type="button"
                        class="fct-adv-filter-clear"
                        @click="clearGroupFilter(group.id)"
                    >
                      {{ translate('Clear') }}
                    </button>
                  </div>
                  <ul class="fct-adv-filter-options__list">
                    <li v-for="term in group.terms" :key="term.id">
                      <el-checkbox
                          :model-value="isTermActive(group.id, term.id)"
                          @change="toggleTermFilter(group.id, term.id)"
                      >
                        {{ term.title }}
                      </el-checkbox>
                    </li>
                  </ul>
                </div>
              </el-popover>
            </div>

            <button
                v-if="hasAnyActiveFilter"
                type="button"
                class="fct-shared-variant-nav__clear"
                @click="clearAllFilters"
            >
              {{ translate('Clear filters') }}
            </button>
          </div>

          <VariantNavList
              :items="variationNavItems"
              :grouped-items="groupedVariationNavItems"
              :active-key="activeVariationKey"
              :is-dirty="isCurrentVariationDirty"
              :is-saving="dirtyDialogSubmitting"
              :drawer-mode="'update'"
              :product="product"
              :product-edit-model="productEditModel"
              :can-copy-direct-checkout="canCopyDirectCheckout"
              @select="selectVariation"
              @save="handleSaveDirtyDraft"
              @command="handleVariantNavCommand"
              @edit-group="handleGroupEdit"
          />
        </aside>

        <div class="fct-shared-variant-drawer__content">
          <!-- Compact group-edit mode: replace the variant picker with a summary bar -->
          <div v-if="useCompactVariantNav && isGroupEditMode" class="fct-compact-group-edit-summary">
            <div class="fct-compact-group-edit-summary__title">
                {{ translate('Editing group') }}:
                <span>
                    {{ editingGroupData?.title }}
                </span>
            </div>
            <div class="fct-compact-group-edit-summary__count">
              {{
                /* translators: %1$s: number of selected variants, %2$s: total variants in the group */
                translate('Changes will apply to %1$s of %2$s variants in this group.', activeGroupVariantIds.length, editingGroupData?.count)
              }}
            </div>

            <ul class="fct-compact-group-edit-summary__list">
              <li v-for="item in editingGroupData?.items" :key="item.key">
                <el-checkbox
                  :model-value="groupIncludedKeys.has(item.key)"
                  @change="toggleGroupItem(item.key)"
                >
                  {{ item.label }}
                </el-checkbox>
              </li>
            </ul>
          </div>

          <!-- Compact normal mode: group-edit chips (when groups exist) + variant pills -->
          <template v-else-if="useCompactVariantNav">
            <div
                v-if="groupedVariationNavItems && groupedVariationNavItems.length"
                class="fct-compact-group-edit-bar"
            >
              <button
                  v-for="group in groupedVariationNavItems"
                  :key="'cge-' + group.termId"
                  type="button"
                  class="fct-compact-group-edit-bar__btn"
                  :disabled="isCurrentVariationDirty"
                  :aria-label="translate('Edit group %1$s', group.title)"
                  @click="handleGroupEdit(group)"
              >
                <DynamicIcon name="Edit"/>
                <span>{{ group.title }}</span>
              </button>
            </div>
            <VariantNavMobile
                :items="variationNavItems"
                :active-key="activeVariationKey"
                :is-dirty="isCurrentVariationDirty"
                @select="selectVariation"
            />
          </template>

          <div class="fct-shared-variant-drawer__form">
            <ProductPricingForm
                v-if="showSharedDrawer && selectedVariantIndex !== null && !isGroupEditMode"
                ref="pricingFormRef"
                :key="activeVariationKey"
                :index="selectedVariantIndex"
                modeType="update"
                fieldKey="variants"
                :product="product"
                :productEditModel="productEditModel"
                :variant-title-info="selectedVariantTitleInfo"
                @create-or-update-variant="handleVariantSaved"
                @close-modal="requestCloseSharedDrawer"
                @dirty-state-change="handleDirtyStateChange"
            />
            <GroupBulkEditForm
                v-else-if="showSharedDrawer && isGroupEditMode && editingGroupData"
                ref="pricingFormRef"
                :key="`group-${editingGroupData.termId}`"
                :group-variant-ids="activeGroupVariantIds"
                fieldKey="variants"
                :product="product"
                :productEditModel="productEditModel"
                @create-or-update-variant="handleVariantSaved"
                @close-modal="requestCloseSharedDrawer"
                @dirty-state-change="handleDirtyStateChange"
            />
          </div>
        </div>
      </div>
    </el-drawer>

    <!-- Unsaved-changes guard for the edit drawer. Opened by
         handleSharedDrawerBeforeClose when the merchant tries to close the
         drawer (backdrop / form Cancel) with a dirty variant form. -->
    <el-dialog
        v-model="showDirtyDraftDialog"
        :close-on-click-modal="false"
        :close-on-press-escape="!dirtyDialogSubmitting"
        :show-close="!dirtyDialogSubmitting"
        class="fct-variant-dirty-dialog"
        @close="handleContinueEditing"
    >
      <template #header="{ titleId }">
        <div class="fct-variant-dirty-dialog__header">
          <h3 :id="titleId">{{ translate('Unsaved changes') }}</h3>
        </div>
      </template>

      <p>{{ translate('You have unsaved variation changes. Discard them or save before continuing.') }}</p>

      <div class="dialog-footer">
        <div class="fct-btn-group sm">
          <el-button :disabled="dirtyDialogSubmitting" size="small" @click="handleContinueEditing">
            {{ translate('Continue editing') }}
          </el-button>
          <el-button :disabled="dirtyDialogSubmitting" size="small" @click="handleDiscardDirtyDrafts">
            {{ translate('Discard') }}
          </el-button>
          <el-button type="primary" :loading="dirtyDialogSubmitting" size="small" @click="handleSaveDirtyDraft">
            {{ translate('Save') }}
          </el-button>
        </div>
      </div>
    </el-dialog>

  </div>
</template>

