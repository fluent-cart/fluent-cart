<script setup>
import {ref, onMounted, onUnmounted, computed, nextTick} from "vue";
import {VueDraggableNext} from 'vue-draggable-next';
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import AdvancedVariationTable from "./AdvancedVariationTable.vue";
import DraggableOptionValues from "./DraggableOptionValues.vue";
import AddTermModal from "@/Modules/Attributes/AddTermModal.vue";
import AddGroupModal from "@/Modules/Attributes/AddGroupModal.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Animation from "@/Bits/Components/Animation.vue";
import TermForm from "@/Modules/Attributes/TermForm.vue";
import {createTermsInBatches} from "@/Models/Product/attributeTermCreator";

const props = defineProps({
  product: Object,
  productEditModel: Object,
})

const MAX_COMBINATIONS = 200;
// Separate cap on attribute group count. The combinations cap alone doesn't
// stop a merchant from adding 50 single-term groups (1 combo but 50 attribute
// relations per variant and a 50-segment variation_identifier string).
const MAX_GROUPS = 10;
// fct_product_variations.variation_identifier is VARCHAR(100). The backend
// builds it by joining a variant's term ids with underscores, so the cap on
// group count alone isn't enough — with large (BIGINT) term ids even 10
// groups can project past 100 chars and silently truncate, collapsing two
// distinct combinations into one row. Guard the worst-case length here.
const MAX_IDENTIFIER_LENGTH = 100;

const attributeGroups = ref([]);
const selectedAttributes = ref([]);
const generating = ref(false);
// Skeleton bookend — set to true the moment the user clicks Save (on the
// "Save to update variants" bar), cleared after the whole persist+regroup
// cycle settles. Spans a wider window than `generating` (which only covers the
// POST) — it also covers the group/term materialisation that precedes the
// generate — so the table skeleton appears immediately on click and stays
// until the grouped tbody is fully ready, without the "Updating variants…"
// text staying that long.
const isPersisting = ref(false);
const loading = ref(false);
const expandedIndex = ref(null);

const createModalIsOpen = ref(false);
const currentEditingAttrIndex = ref(null);

// Create-attribute-group dialog state. Opens from the "Create new attribute group"
// link in the option-name field; on success the new group is appended to
// attributeGroups and auto-selected for the option card that triggered it.
const createGroupModalOpen = ref(false);
const createGroupTargetIndex = ref(null);

// Editor draft — a working copy of the currently expanded attribute. The expanded card
// edits this buffer; nothing flows back to selectedAttributes (or the server) until Done.
const draft = ref(null);

// Last-saved option order — used to detect when a drag-reorder needs persisting.
// Updated after every successful generateVariations() and after loadExistingConfig().
const savedOrder = ref([]);

// Snapshot of selectedAttributes at the last successful generateVariations
// (and at initial load). Two representations:
// - lastGeneratedConfig: deep-cloned array, used by discardOrderChange to
//   RESTORE the original attr structure (preserves original variant order).
// - lastGeneratedSnapshot: a normalised JSON string (variants order-PRESERVED)
//   for the hasPendingChanges diff check — drag-reordering an option's values
//   IS a real change because it changes each combination's serial_index.
const lastGeneratedConfig = ref([]);
const lastGeneratedSnapshot = ref('');

const serializeConfig = (attrs) => {
  return JSON.stringify(attrs.map(attr => ({
    group_id: attr.group_id || null,
    // Order-sensitive on purpose: a drag-reorder of an option's values must
    // register as a pending change (it changes each combination's serial_index),
    // so do NOT sort here.
    variants: [...(attr.variants || [])].map(variantId => String(variantId)),
  })));
};

const captureSavedConfig = () => {
  lastGeneratedConfig.value = selectedAttributes.value.map(attr => ({
    group_id: attr.group_id || null,
    title: attr.title || '',
    variants: Array.isArray(attr.variants) ? [...attr.variants] : [],
  }));
  lastGeneratedSnapshot.value = serializeConfig(selectedAttributes.value);
};

const hasPendingChanges = computed(() => {
  // Compare only the COMPLETE options (a group with at least one value) against
  // the last saved snapshot. An in-progress blank card — e.g. just added via
  // "Add more" and not yet filled — must neither trigger the bar on its own NOR
  // MASK a real pending change in another option (a value removed from Color, a
  // reorder, a staged delete). Filtering the incomplete card out gives both: a
  // lone blank card diffs to "no change", while a real edit elsewhere still
  // diffs to "pending". (The snapshot only ever holds complete options — Save
  // can't persist a config with a blank card — so this is an apples-to-apples
  // comparison.)
  const completeOptions = selectedAttributes.value.filter(
    attr => attr.group_id && Array.isArray(attr.variants) && attr.variants.length > 0
  );
  // No complete options left (e.g. the last real option was deleted): pending
  // only if a non-empty config was previously saved — a staged "delete all"
  // that Save must flush as options:[].
  if (completeOptions.length === 0) {
    return lastGeneratedSnapshot.value !== serializeConfig([]);
  }
  return serializeConfig(completeOptions) !== lastGeneratedSnapshot.value;
});

// Save-bar copy. When every option has been removed, Save sends options:[]
// which DELETES all variants for the product — say so explicitly instead of the
// generic "update variants" wording, which reads oddly for a destructive clear.
const saveBarMessage = computed(() => {
  if (selectedAttributes.value.length === 0) {
    return translate('All options removed. Save to delete all variations.');
  }
  return translate('Option config changed. Save to update variants.');
});

// True when the currently expanded card's draft differs from its saved attr.
// Lets the "Save to update variants" bar surface the moment the merchant
// changes anything mid-edit — before Done has staged the draft into
// selectedAttributes (which is what hasPendingChanges compares).
const isDraftDirty = computed(() => {
  if (expandedIndex.value === null || !draft.value) return false;
  const attr = selectedAttributes.value[expandedIndex.value];
  if (!attr) return false;
  if (String(attr.group_id || '') !== String(draft.value.group_id || '')) return true;
  if ((attr.title || '') !== (draft.value.title || '')) return true;
  const savedVariants = Array.isArray(attr.variants) ? attr.variants : [];
  const draftVariants = Array.isArray(draft.value.variants) ? draft.value.variants : [];
  if (savedVariants.length !== draftVariants.length) return true;
  // Order-sensitive (no .sort()): reordering the values is itself a change the
  // "Save to update variants" bar must surface, since it reshuffles serial_index.
  const savedKeys = savedVariants.map(variantId => String(variantId));
  const draftKeys = draftVariants.map(variantId => String(variantId));
  return savedKeys.some((key, index) => key !== draftKeys[index]);
});

onMounted(() => {
  // loadExistingConfig must run first — it is synchronous and populates
  // selectedAttributes so ensureGroupTerms calls in loadAttributeGroups resolve correctly.
  loadExistingConfig();
  loadAttributeGroups();
})

onUnmounted(() => {
  // Remove any global listeners registered by the inline term form so they
  // don't persist if the component is destroyed while the form is open.
  hideInlineTermForm();
})

// Library endpoint returns the attribute groups (each stamped with a
// terms_count) but NOT their terms — terms are fetched per group on demand via
// ensureGroupTerms() so a large catalog doesn't ship every term up front.
// Pro caps the server-side response at 200 groups; the client mirrors that
// ceiling as defense-in-depth so a future endpoint contract change can't dump
// thousands of rows into the dropdown and freeze the editor.
const MAX_LIBRARY_GROUPS = 200;
const loadAttributeGroups = () => {
  loading.value = true;
  Rest.get('options/attr/groups/library').then(response => {
    attributeGroups.value = (response.groups || []).slice(0, MAX_LIBRARY_GROUPS);
    // An existing product's saved option cards reference groups whose terms
    // aren't in the library payload — fetch them so the value chips on the
    // collapsed cards (and the Option values dropdown) render.
    selectedAttributes.value.forEach(attr => ensureGroupTerms(attr.group_id));
  }).catch((errors) => {
    Notify.error(errors?.data?.message || translate('Failed to load attribute groups'));
  }).finally(() => {
    loading.value = false;
  });
}

const loadExistingConfig = () => {
  const otherInfo = props.product?.detail?.other_info;
  const config = otherInfo?.attribute_config;
  if (config && Array.isArray(config)) {
    selectedAttributes.value = config.map(attr => ({
      // PHP BIGINT columns can serialize as strings, but the library's
      // groups/terms carry numeric ids — strict-equality lookups
      // (getGroupTerms, getSelectedTerms) would miss and the saved option
      // would render with no selected terms. Coerce numeric ids to Number.
      // A custom, not-yet-saved group carries a non-numeric string id —
      // leave those untouched.
      group_id: /^\d+$/.test(String(attr.group_id)) ? parseInt(attr.group_id, 10) : attr.group_id,
      title: attr.title || '',
      variants: (attr.variants || []).map(variantId => parseInt(variantId, 10)).filter(Number.isFinite),
      errors: {name: '', values: ''},
    }));
  }
  captureSavedOrder();
  captureSavedConfig();
}

// Terms are not part of the library payload (getLibrary is groups-only with a
// terms_count). Fetch one group's terms the first time a card needs them and
// cache the result on the group object so getGroupTerms()/getSelectedTerms()
// resolve synchronously from then on. Idempotent — safe to call repeatedly.
const termsFetchInFlight = new Set();
// Reactive mirror of termsFetchInFlight — keyed by group ID (numeric), truthy
// while the initial terms fetch is in flight. Used only for the loading
// indicator: a plain Set is not reactive, so the template can't watch it.
const termsLoadingMap = ref({});
// True while the currently expanded card's group is fetching its term list for
// the first time — drives the :loading prop on all three values el-selects.
const isTermsLoading = computed(() => {
  if (!draft.value || !draft.value.group_id) return false;
  const numericId = parseInt(draft.value.group_id, 10);
  return !!termsLoadingMap.value[numericId];
});
const ensureGroupTerms = (groupId) => {
  // Custom, not-yet-saved groups carry a non-numeric string id and have no
  // server-side terms to fetch.
  if (!/^\d+$/.test(String(groupId))) return;
  const numericId = parseInt(groupId, 10);

  const group = attributeGroups.value.find(candidate => sameId(candidate.id, numericId));
  if (!group) return;
  if (Array.isArray(group.terms)) return;        // already fetched (or a fresh group)
  if (termsFetchInFlight.has(numericId)) return;  // fetch already running

  termsFetchInFlight.add(numericId);
  termsLoadingMap.value[numericId] = true;
  // terms_count (stamped by the library endpoint) sizes the page so a single
  // request returns every term; clamp to the same 200 ceiling used elsewhere.
  const perPage = Math.min(Math.max(parseInt(group.terms_count, 10) || 0, 50), 200);
  Rest.get('options/attr/group/' + numericId + '/terms', {per_page: perPage})
    .then(response => {
      group.terms = (response.terms && response.terms.data) || [];
    })
    .catch(errors => {
      Notify.error(errors?.data?.message || translate('Failed to load option values'));
    })
    .finally(() => {
      termsFetchInFlight.delete(numericId);
      delete termsLoadingMap.value[numericId];
    });
}

// Server-side term search that enriches the loaded cache as the merchant
// types. ensureGroupTerms loads at most 200 terms in one shot; a group with
// more than that would hide every term past the cap from the client-side
// filter below, and allow-create would then spawn a duplicate (the backend
// enforces unique slugs, not unique titles). Searching the server appends any
// matches into group.terms so they become selectable — and persistNewTerms'
// dedup (which reads group.terms) sees them, so the exact-title duplicate is
// avoided. Best-effort enrichment behind the existing client filter, so no
// remote/loading rewrite of the picker is needed. Debounced; skips the fetch
// when the exact typed title is already loaded.
const termSearchInFlight = new Set();
let termSearchTimer = null;
const searchGroupTermsRemote = (groupId, query) => {
  const trimmed = (query || '').trim();
  if (!trimmed) return;
  // Unsaved custom group carries a non-numeric string id and has no server terms.
  if (!/^\d+$/.test(String(groupId))) return;
  const numericId = parseInt(groupId, 10);
  const group = attributeGroups.value.find(candidate => sameId(candidate.id, numericId));
  if (!group) return;

  // Everything is already loaded — ensureGroupTerms pulls the whole set when a
  // group has <= 200 terms, so the client filter covers it and no server round
  // trip is needed. Only groups larger than the 200 cap fall through to search.
  const loadedCount = Array.isArray(group.terms) ? group.terms.length : 0;
  const totalCount = parseInt(group.terms_count, 10) || 0;
  if (totalCount && loadedCount >= totalCount) return;

  // Exact local match already present — it is selectable and dedup will catch
  // it, so there is nothing the server could add for this query.
  if (Array.isArray(group.terms) && group.terms.some(
      term => (term.title || '').trim().toLowerCase() === trimmed.toLowerCase())) {
    return;
  }

  if (termSearchTimer) clearTimeout(termSearchTimer);
  termSearchTimer = setTimeout(() => {
    const key = numericId + ':' + trimmed.toLowerCase();
    if (termSearchInFlight.has(key)) return;
    termSearchInFlight.add(key);
    Rest.get('options/attr/group/' + numericId + '/terms', {search: trimmed, per_page: 50})
      .then(response => {
        const found = (response.terms && response.terms.data) || [];
        if (!found.length) return;
        if (!Array.isArray(group.terms)) group.terms = [];
        const seen = new Set(group.terms.map(term => term.id));
        const merged = found.filter(term => !seen.has(term.id));
        if (merged.length) group.terms = [...group.terms, ...merged];
      })
      .catch(() => { /* enrichment is best-effort — stay silent on failure */ })
      .finally(() => { termSearchInFlight.delete(key); });
  }, 250);
}

// Re-pull a group's terms from the server, merging into the cached copy.
// ensureGroupTerms is idempotent (never re-fetches once cached), so terms
// created elsewhere — e.g. on the Attributes page in another tab — wouldn't
// otherwise show in an already-open edit page without a full refresh. Called
// when the option-values dropdown opens so reopening the picker reflects the
// latest server state. Merges by id rather than replacing: newly created terms
// appear, while previously loaded terms (including ones surfaced by
// searchGroupTermsRemote beyond the 200 cap) stay put so selected chips keep
// resolving their titles.
const refreshGroupTerms = (groupId) => {
  if (!/^\d+$/.test(String(groupId))) return; // unsaved custom group — no server terms
  const numericId = parseInt(groupId, 10);
  const group = attributeGroups.value.find(candidate => sameId(candidate.id, numericId));
  if (!group) return;
  if (termsFetchInFlight.has(numericId)) return; // initial load / another refresh already running

  termsFetchInFlight.add(numericId);
  const perPage = Math.min(Math.max(parseInt(group.terms_count, 10) || 0, 50), 200);
  Rest.get('options/attr/group/' + numericId + '/terms', {per_page: perPage})
    .then(response => {
      const data = (response.terms && response.terms.data) || [];
      const byId = new Map((Array.isArray(group.terms) ? group.terms : []).map(term => [term.id, term]));
      data.forEach(term => byId.set(term.id, term)); // fresh data wins for existing ids
      group.terms = Array.from(byId.values());
      const total = response.terms && response.terms.total;
      if (typeof total === 'number') group.terms_count = total;
    })
    .catch(() => { /* keep the cached terms on failure — silent */ })
    .finally(() => { termsFetchInFlight.delete(numericId); });
}

// Re-pull the attribute library (groups-only) when the option NAME picker
// opens, so a group created elsewhere — e.g. the Attributes page in another
// tab — shows without a full edit-page refresh. Merge by id (union): fresh
// metadata wins, cached per-group terms are carried over so loaded option
// values aren't wiped, and currently-selected groups are never dropped (so
// their chips/labels keep resolving). Groups are few and fully loaded up to
// MAX_LIBRARY_GROUPS, so a plain re-fetch + client filter covers them — no
// remote search needed here.
let libraryRefreshInFlight = false;
const refreshLibrary = () => {
  if (libraryRefreshInFlight) return;
  libraryRefreshInFlight = true;
  Rest.get('options/attr/groups/library')
    .then(response => {
      const fresh = (response.groups || []).slice(0, MAX_LIBRARY_GROUPS);
      const byId = new Map(attributeGroups.value.map(group => [group.id, group]));
      fresh.forEach(group => {
        const cached = byId.get(group.id);
        byId.set(group.id, cached && Array.isArray(cached.terms)
          ? {...group, terms: cached.terms}
          : group);
      });
      attributeGroups.value = Array.from(byId.values());
    })
    .catch(() => { /* keep the current library on failure — silent */ })
    .finally(() => { libraryRefreshInFlight = false; });
}

// Snapshot the current option order as the "last saved" baseline. Anything that differs
// from this in selectedAttributes triggers the dirty-order save bar.
const captureSavedOrder = () => {
  savedOrder.value = selectedAttributes.value.map(attr => attr.group_id).filter(Boolean);
}

// Revert ALL pending changes back to the last-generated snapshot —
// undoes drag-reorders AND value/card changes that were Save'd locally
// via the header-collapse path but never Generate'd. Replaces the
// selectedAttributes array wholesale from the snapshot's deep clone so
// the order and per-card variants/title both match what's actually
// generated on the server.
const discardOrderChange = () => {
  // No early-return on an empty baseline: when nothing has been saved yet
  // (lastGeneratedConfig is []), Discard must still revert to that empty state
  // and clear the staged-but-unsaved options. The map below produces [] in that
  // case, which is exactly the desired reset.
  //
  // The bar can show while a card is open, so close the editor first —
  // otherwise rolling selectedAttributes back to baseline orphans the open
  // card's draft (and its index may fall outside the restored array).
  draft.value = null;
  expandedIndex.value = null;
  selectedAttributes.value = lastGeneratedConfig.value.map(savedAttr => ({
    group_id: savedAttr.group_id,
    title: savedAttr.title,
    variants: [...savedAttr.variants],
    errors: {name: '', values: ''},
  }));
}

// Persist the new option order: same POST as generateVariations, which regenerates the
// variants on the server so titles/breadcrumbs reflect the new order.
const saveOrderChange = async () => {
  // The bar can now show while a card is still expanded (isDraftDirty), so
  // stage the open card's draft first. collapseCard validates and bails —
  // leaving the card open (expandedIndex stays set) — when the option name or
  // values are empty; in that case abort so we never generate from a
  // half-edited card.
  if (expandedIndex.value !== null) {
    await collapseCard(expandedIndex.value);
    if (expandedIndex.value !== null) {
      return;
    }
  }

  // Flip the skeleton on synchronously so the table swaps to skeleton in
  // the same frame as the click — generateVariations only sets
  // `generating` after its own validation gates. This also hides the save
  // bar (its v-if excludes isPersisting) for the whole persist→generate window.
  isPersisting.value = true;

  // Local staging deferred every DB write to here. Materialise typed option
  // names into real groups and typed values into terms BEFORE generating, so
  // the generate payload carries only numeric ids. Abort + clear the skeleton
  // if any creation fails (the offending card keeps its inline error).
  const persisted = await persistPendingAttributes();
  if (!persisted) {
    isPersisting.value = false;
    return;
  }

  generateVariations({silent: false});
}

const getAvailableGroupsFor = (currentIndex) => {
  const usedIds = selectedAttributes.value
      .filter((_, index) => index !== currentIndex)
      .map(attr => attr.group_id)
      .filter(Boolean);
  return attributeGroups.value.filter(group => !usedIds.includes(group.id));
}

const addNewAttribute = async () => {
  // Stage the currently open card before opening a new one — otherwise
  // expandCard() swaps the draft to the new card and the open card's unsaved
  // edits (name + selected terms) are lost, leaving it as "Untitled option".
  // collapseCard validates and keeps the card open when name/values are empty,
  // so abort the add in that case (the merchant finishes or deletes it first).
  if (expandedIndex.value !== null) {
    await collapseCard(expandedIndex.value);
    if (expandedIndex.value !== null) {
      return;
    }
  }

  selectedAttributes.value.push({
    group_id: null,
    title: '',
    variants: [],
    errors: {name: '', values: ''},
  });
  const newIndex = selectedAttributes.value.length - 1;
  expandCard(newIndex);
}


const removeAttribute = (index) => {
  // Staged delete: only mutate the in-memory config here — no API call. This
  // surfaces the "Save to update variants" bar (via hasPendingChanges) so the
  // removal is persisted together with any other pending edits when the
  // merchant clicks Save. Previously this immediately POSTed the whole config,
  // which silently committed every other collapsed card's unsaved edits to the
  // DB as a side effect of a single Delete. Discard restores the removed card
  // from lastGeneratedConfig.
  //
  // Deleting the last card empties the config; hasPendingChanges treats that as
  // a pending change and the Save path (generateVariations) sends options:[] to
  // clear all variants in the DB.
  selectedAttributes.value.splice(index, 1);
  if (expandedIndex.value === index) {
    expandedIndex.value = null;
    draft.value = null;
  } else if (expandedIndex.value !== null && expandedIndex.value > index) {
    expandedIndex.value--;
  }
}

// Single header click that flips between expand and collapse, so the
// merchant can fold a card back without having to click Save. Defers
// to collapseCard for the close path (so validation surfaces if the
// draft is incomplete) but passes generate=false: closing via the
// header is a "stage these changes locally" gesture, not a full
// generate. The combinations chip flips to a Generate button while
// changes are pending so the merchant decides when to rebuild.
const toggleCard = (index) => {
  if (expandedIndex.value === index) {
    collapseCard(index);
  } else {
    expandCard(index);
  }
}

// Opening a card seeds the draft from the underlying attribute. All edits inside the
// expanded editor mutate the draft only — not selectedAttributes — so nothing leaks
// back into the saved configuration until the user clicks Done.
const expandCard = (index) => {
  const attr = selectedAttributes.value[index];
  draft.value = {
    group_id: attr.group_id || null,
    title: attr.title || '',
    variants: Array.isArray(attr.variants) ? [...attr.variants] : [],
    errors: {name: '', values: ''},
  };
  expandedIndex.value = index;
  ensureGroupTerms(attr.group_id);
}

// Collapse + stage the expanded card LOCALLY — no DB writes. The typed option
// name (a string in group_id) and any newly typed values (string placeholders
// in variants) are committed to selectedAttributes as-is; they're materialised
// into real groups/terms only when the merchant clicks "Save to update
// variants" (see persistPendingAttributes). Validation runs first and keeps the
// card open (returns without clearing expandedIndex) when the name or values
// are empty — that's what gates "Add more" from opening a second card over an
// incomplete one.
const collapseCard = async (index) => {
  if (!draft.value) return;

  // Inline validation — Shopify-style, shown under each field
  draft.value.errors.name = !draft.value.group_id ? translate('Option name is required.') : '';
  draft.value.errors.values = (!draft.value.variants || draft.value.variants.length === 0)
      ? translate('Please add at least one option value.')
      : '';

  if (draft.value.errors.name || draft.value.errors.values) {
    return;
  }

  // Commit the draft back into the real attribute. No server call here — new
  // group names and new values stay as strings until the Save bar persists them.
  const attr = selectedAttributes.value[index];
  attr.group_id = draft.value.group_id;
  attr.title = draft.value.title;
  attr.variants = [...draft.value.variants];
  attr.errors = {name: '', values: ''};

  draft.value = null;
  expandedIndex.value = null;
}

// Group picked OR typed in the editor.
//  - If groupId is numeric, it's an existing AttributeGroup — pull the title from it.
//  - If groupId is a string (allow-create), the merchant typed a brand-new option name —
//    draft.title gets the typed text so the option-card summary shows it immediately;
//    the typed name is converted to a real group at the Save step
//    (persistPendingAttributes), or eagerly if a term is created inline first.
// Either way we clear draft.variants so leftover terms from a previous group don't bleed
// into the new one.
// Tracks the live filter query inside the Option name picker so we can
// suppress allow-create's "create new from typed text" candidate when the
// typed string already matches an existing group case-insensitively.
// el-select's allow-create injection bypasses our case-insensitive lookup
// otherwise — typing "style" with a "Style" group in the library would
// show BOTH rows in the dropdown and a 422 on Save if the merchant picks
// the typed one.
const groupQueryText = ref('');

const handleGroupNameFilter = (query) => {
  groupQueryText.value = query || '';
};

// Reset the query tracker when the dropdown closes so a stale "matches an
// existing group" state doesn't disable allow-create the next time the
// merchant reopens the picker.
const onGroupNamePickerVisibleChange = (visible) => {
  if (visible) {
    refreshLibrary();
    return;
  }
  groupQueryText.value = '';
};

const filteredGroupsFor = (currentIndex) => {
  const base = getAvailableGroupsFor(currentIndex);
  const query = groupQueryText.value.trim().toLowerCase();
  if (!query) return base;
  return base.filter(group => typeof group.title === 'string' && group.title.toLowerCase().includes(query));
};

const canCreateGroupFromQuery = computed(() => {
  const query = groupQueryText.value.trim().toLowerCase();
  if (!query) return true;
  return !attributeGroups.value.some(
      group => typeof group.title === 'string' && group.title.trim().toLowerCase() === query
  );
});

const onGroupChange = (groupId) => {
  if (!draft.value) return;

  if (typeof groupId === 'string' && groupId !== '') {
    // allow-create on the picker means the merchant's typed text comes
    // through as a raw string. Before treating it as a brand-new group,
    // see if the title already exists case-insensitively — typing "color"
    // when "Color" lives in the library otherwise hits the create endpoint
    // and 422s on the unique slug. Snap to the existing id instead so the
    // editor reuses the matched group.
    const typed = groupId.trim().toLowerCase();
    const existing = attributeGroups.value.find(
        candidate => typeof candidate.title === 'string' && candidate.title.trim().toLowerCase() === typed
    );
    if (existing) {
      draft.value.group_id = existing.id;
      draft.value.title = existing.title;
      draft.value.variants = [];
      ensureGroupTerms(existing.id);
    } else {
      draft.value.title = groupId;
      draft.value.variants = [];
    }
  } else {
    const group = attributeGroups.value.find(candidate => sameId(candidate.id, groupId));
    if (group) {
      draft.value.title = group.title;
      draft.value.variants = [];
    }
    ensureGroupTerms(groupId);
  }

  if (draft.value.errors) draft.value.errors.name = '';
}

// True when the editor's group_id is a string (typed by the merchant via allow-create)
// rather than a numeric id from the library.
const isCustomGroupName = computed(() => {
  if (!draft.value) return false;
  return typeof draft.value.group_id === 'string' && draft.value.group_id.trim() !== '';
})

const savingGroup = ref(false);

// Persist a typed option name (held as a string on `target.group_id`) as a real
// AttributeGroup so term endpoints resolve. Re-points target.group_id from the
// typed string to the new numeric id and registers the group locally so the
// dropdown recognises it without a full reload. Returns the new numeric id, or
// null on failure (surfacing the error inline on target.errors.name). Shared by
// the inline term path (via createGroupFromTypedName) and the Save-time
// materialisation (persistPendingAttributes).
const createGroupFromName = async (target) => {
  if (!target || typeof target.group_id !== 'string' || target.group_id.trim() === '') {
    return target ? target.group_id : null;
  }

  const title = target.group_id.trim();

  // Defensive case-insensitive match — covers races where the library
  // gained the group between selection and save (e.g. another tab created
  // it). Without this we'd POST and 422 on the unique slug.
  const lower = title.toLowerCase();
  const alreadyExists = attributeGroups.value.find(
      candidate => typeof candidate.title === 'string' && candidate.title.trim().toLowerCase() === lower
  );
  if (alreadyExists) {
    target.group_id = alreadyExists.id;
    target.title = alreadyExists.title;
    ensureGroupTerms(alreadyExists.id);
    return alreadyExists.id;
  }

  savingGroup.value = true;

  try {
    const response = await Rest.post('options/attr/group/', {
      title: title,
      slug: title,
    });
    const newGroup = response.data || response.group || response;
    if (!newGroup || !newGroup.id) {
      Notify.error(translate('Failed to create attribute group'));
      return null;
    }

    // Stamp an empty terms array so persistNewTerms can append into it.
    // Without this getGroupTerms returns [] and chips render raw IDs.
    attributeGroups.value = [...attributeGroups.value, {...newGroup, terms: newGroup.terms || []}];
    const numericId = parseInt(newGroup.id, 10);
    target.group_id = numericId;
    target.title = newGroup.title || title;

    return numericId;
  } catch (errors) {
    // Surface backend validation errors both as a toast AND inline under
    // the Option name field so the merchant sees why Save did nothing
    // (422 shape: { data: { slug: { unique: "..." } } } — Notify.error's
    // ?? data.message fallback would otherwise show "[object Object]").
    let inlineMessage = '';
    if (errors?.status_code === 422 || errors?.status === 422) {
      Notify.validationErrors(errors);
      const fieldErrors = errors?.data && typeof errors.data === 'object' ? errors.data : {};
      const firstField = Object.values(fieldErrors)[0];
      const firstMessage = firstField && typeof firstField === 'object' ? Object.values(firstField)[0] : firstField;
      inlineMessage = typeof firstMessage === 'string' ? firstMessage : translate('Failed to create attribute group');
    } else {
      inlineMessage = errors?.data?.message || translate('Failed to create attribute group');
      Notify.error(inlineMessage);
    }
    if (target.errors) {
      target.errors.name = inlineMessage;
    }
    return null;
  } finally {
    savingGroup.value = false;
  }
}

// Thin wrapper for the inline term path: materialise the draft's typed option
// name before a term is created against it. Returns true on success (or when
// there's nothing to do), false on failure.
const createGroupFromTypedName = async () => {
  if (!draft.value || !isCustomGroupName.value) return true;
  const numericId = await createGroupFromName(draft.value);
  return numericId !== null;
}

// Loose id match. PHP BIGINT columns can serialize as strings while the
// library payload carries numeric ids (or vice versa) — a strict === then
// silently misses. Custom, not-yet-saved groups carry a non-numeric string
// id, so a parseInt-based compare would break those; String() coercion
// matches numeric ids across types AND keeps custom string ids exact.
const sameId = (idA, idB) => String(idA) === String(idB);


const getGroupTerms = (groupId) => {
  const group = attributeGroups.value.find(candidate => sameId(candidate.id, groupId));
  return group ? (group.terms || []) : [];
};

const getPendingTermStrings = (groupId) => {
  if (!draft.value || !sameId(draft.value.group_id, groupId)) return [];
  const existingTitles = getGroupTerms(groupId).map(term => term.title.toLowerCase());
  return (draft.value.variants || []).filter(
    value => typeof value === 'string' && value.trim() !== '' && !existingTitles.includes(value.toLowerCase())
  );
};

const isAllTermsSelected = computed(function() {
  if (!draft.value || !draft.value.group_id) return false;
  var terms = getGroupTerms(draft.value.group_id);
  if (!terms.length) return false;
  return terms.every(function(term) {
    return draft.value.variants.some(function(variantId) { return sameId(variantId, term.id); });
  });
});

const someTermsSelected = computed(function() {
  if (!draft.value || !draft.value.group_id) return false;
  var terms = getGroupTerms(draft.value.group_id);
  var selectedCount = terms.filter(function(term) {
    return draft.value.variants.some(function(variantId) { return sameId(variantId, term.id); });
  }).length;
  return selectedCount > 0 && selectedCount < terms.length;
});


const toggleTerm = function(termId) {
  if (!draft.value) return;
  var targetId = String(termId);
  var existingIndex = draft.value.variants.findIndex(function(variantId) { return String(variantId) === targetId; });
  if (existingIndex === -1) {
    draft.value.variants.push(termId);
  } else {
    draft.value.variants.splice(existingIndex, 1);
  }
};

const toggleSelectAllTerms = function(checked) {
  if (!draft.value) return;
  var terms = getGroupTerms(draft.value.group_id);
  draft.value.variants = checked ? terms.map(function(term) { return term.id; }) : [];
};

// Card header name. An option's title is derived from its group — the stored
// attribute_config may not carry a title at all — so fall back to the group
// title, and reflect the live draft while a card is expanded so picking a
// group updates the header immediately instead of after Done.
const optionDisplayTitle = (attr, index) => {
  const source = (expandedIndex.value === index && draft.value) ? draft.value : attr;
  if (source.title) return source.title;
  const group = attributeGroups.value.find(candidate => sameId(candidate.id, source.group_id));
  if (group && group.title) return group.title;
  return translate('Untitled option');
}

// Reject duplicate group_ids — same attribute group (e.g., "Color") added twice
// would create distinct dimensions in the cartesian product, each generating
// identical variants.
const hasDuplicateGroups = computed(() => {
  const ids = selectedAttributes.value.map(attr => attr.group_id).filter(Boolean);
  return new Set(ids).size !== ids.length;
});

const tooManyGroups = computed(() => selectedAttributes.value.length > MAX_GROUPS);

const hasValidConfig = computed(() => {
  return selectedAttributes.value.length > 0 &&
      selectedAttributes.value.length <= MAX_GROUPS &&
      !hasDuplicateGroups.value &&
      selectedAttributes.value.every(attr =>
          attr.group_id &&
          attr.variants &&
          attr.variants.length > 0 &&
          attr.variants.every(variantId => typeof variantId === 'number')
      );
})

const totalCombinations = computed(() => {
  if (!hasValidConfig.value) return 0;
  return selectedAttributes.value.reduce((total, attr) => {
    return total * (attr.variants ? attr.variants.length : 0);
  }, 1);
})

const combinationsExceeded = computed(() => totalCombinations.value > MAX_COMBINATIONS);

// Combination count for the chip DISPLAY only. Unlike totalCombinations (which
// gates the generate POST and so requires every value to be a saved numeric
// term id), this counts each option's values by length regardless of whether
// they're saved ids or values still typed and awaiting the Save step (strings).
// That keeps the "N combinations" chip visible live while the merchant is still
// building, before anything is persisted.
const stagedCombinations = computed(() => {
  const groups = selectedAttributes.value.filter(
    attr => attr.group_id && Array.isArray(attr.variants) && attr.variants.length > 0
  );
  if (!groups.length) return 0;
  return groups.reduce((total, attr) => total * attr.variants.length, 1);
});

// Worst-case length of the variation_identifier the backend will build. The
// identifier for one combination is its term ids sorted and joined by "_";
// the longest possible one picks the largest-id term from every group. We
// measure that projection and block generation before a truncating write.
const projectedIdentifierLength = computed(() => {
  const groups = selectedAttributes.value.filter(attr => Array.isArray(attr.variants) && attr.variants.length > 0);
  if (!groups.length) return 0;
  let length = groups.length - 1; // underscore separators between segments
  groups.forEach(attr => {
    const ids = attr.variants.map(variantId => parseInt(variantId, 10)).filter(Number.isFinite);
    const maxId = ids.length ? Math.max(...ids) : 0;
    length += String(maxId).length;
  });
  return length;
});

const identifierTooLong = computed(() => projectedIdentifierLength.value > MAX_IDENTIFIER_LENGTH);

const generateVariations = ({silent = false} = {}) => {
  // Double-submit guard. Several paths reach here (Done click, drag-reorder
  // save, the pending-term watcher, the Save bar) — without this a second call
  // while a POST is in flight would issue a duplicate update-variant-option
  // request and race the optimistic variants swap.
  if (generating.value) {
    return;
  }

  // Empty config = the merchant deleted every option and clicked Save.
  // hasValidConfig is false when empty, so the normal validity bail below would
  // no-op and leave the old variants / fct_atts_relations rows in the DB. Send
  // options:[] — the backend treats an empty payload as "delete all variants
  // for this product" (AdvancedVariationService) — then mirror the success
  // housekeeping so the reactive store doesn't keep listing the cleared rows in
  // Inventory Management, the Default Variant picker, and Downloadable Assets.
  if (selectedAttributes.value.length === 0) {
    generating.value = true;
    Rest.post(`products/${props.product.ID}/update-variant-option`, {
      variation_type: 'advanced_variations',
      options: [],
    }).then(() => {
      const storeProduct = props.productEditModel && props.productEditModel.data
          ? props.productEditModel.data.product
          : null;
      if (storeProduct) {
        storeProduct.variants = [];
      }
      captureSavedOrder();
      captureSavedConfig();
      if (props.productEditModel && props.productEditModel.data && typeof props.productEditModel.data.reloader === 'function') {
        props.productEditModel.data.reloader();
      }
      // Refresh panels that fetch their own variant list (e.g. Map bundle items)
      // so they don't keep showing the now-deleted combinations. Scope to the
      // 'bundle' listener — regenerate only persists variant options, so it must
      // not fire the 'formreset' listener and wipe unsaved edits in other panels.
      if (props.productEditModel && typeof props.productEditModel.triggerProductUpdated === 'function') {
        props.productEditModel.triggerProductUpdated(['bundle']);
      }
    }).catch((errors) => {
      Notify.error(errors?.data?.message || translate('Failed to clear variations'));
    }).finally(() => {
      generating.value = false;
      isPersisting.value = false;
    });
    return;
  }

  // The validation gates below return BEFORE the POST whose .finally clears
  // isPersisting. The caller (saveOrderChange) flips isPersisting on
  // synchronously to show the skeleton, so every early exit here must clear it
  // or the table stays frozen in the busy skeleton. The double-submit guard
  // above is the exception: an in-flight generation owns the flag and clears it
  // on finish.
  if (!hasValidConfig.value) {
    if (!silent) Notify.error(translate('Please select at least one term for each attribute.'));
    isPersisting.value = false;
    return;
  }

  if (combinationsExceeded.value) {
    /* translators: %1$s: current combinations count, %2$s: maximum allowed combinations */
    if (!silent) Notify.error(translate('Too many combinations (%1$s). Maximum allowed is %2$s.', totalCombinations.value, MAX_COMBINATIONS));
    isPersisting.value = false;
    return;
  }

  if (identifierTooLong.value) {
    /* translators: %1$s: maximum allowed identifier length */
    if (!silent) Notify.error(translate('These options would create variant identifiers longer than %1$s characters. Use fewer attribute groups.', MAX_IDENTIFIER_LENGTH));
    isPersisting.value = false;
    return;
  }

  generating.value = true;

  // Dedupe term IDs within each group before sending. Element Plus multi-select
  // usually keeps the list clean, but programmatic edits or drag-reorder paths
  // can leave duplicates in `attr.variants` — those would project the same term
  // into multiple combinations and bloat the attribute_relations table.
  const options = selectedAttributes.value.map(attr => ({
    group_id: attr.group_id,
    title: attr.title,
    variants: [...new Set((attr.variants || []).map(variantId => parseInt(variantId, 10)).filter(Number.isFinite))],
  }));

  // Hold the success-message until the skeleton clears, so the toast and
  // the rendered table appear together. Notifying inside .then() pops the
  // toast right after the POST resolves, but the skeleton stays up
  // through the attr_map polling loop (+ wind-down ticks). The user
  // perceives that as "saved" being shown but the table still loading.
  let pendingSuccessMessage = null;

  Rest.post(`products/${props.product.ID}/update-variant-option`, {
    variation_type: 'advanced_variations',
    options: options,
  }).then(response => {
    if (!silent) {
      pendingSuccessMessage = response.message || translate('Variations generated successfully!');
    }
    captureSavedOrder();
    captureSavedConfig();

    // Optimistically swap in the fresh variants from the response so the table
    // re-renders instantly. The reloader() below still fires for product detail /
    // attr_map / pricing fields, but it's an async re-fetch that propagates
    // through a long prop chain (ProductRoute → EditProduct.editableProduct →
    // ProductPricing → here) where editableProduct is a plain `let` and doesn't
    // always trigger a re-render of the variants table on its own. Patching
    // variants directly on the reactive store is the deterministic path.
    const freshVariants = response && Array.isArray(response.data) ? response.data : null;
    const storeProduct = props.productEditModel && props.productEditModel.data
        ? props.productEditModel.data.product
        : null;
    if (freshVariants && storeProduct) {
      storeProduct.variants = freshVariants;
    }

    props.productEditModel.data.reloader();
    // Refresh panels that fetch their own variant list (e.g. Map bundle items)
    // so they reflect the regenerated combinations instead of stale ones. Scope
    // to the 'bundle' listener — regenerate only persists variant options, so it
    // must not fire the 'formreset' listener and wipe unsaved edits elsewhere.
    if (typeof props.productEditModel.triggerProductUpdated === 'function') {
      props.productEditModel.triggerProductUpdated(['bundle']);
    }
  }).catch((errors) => {
    Notify.error(errors?.data?.message || translate('Failed to generate variations'));
  }).finally(async () => {
    // Hold the skeleton until the fresh variants are actually groupable.
    // The POST response's variants may not include attr_map yet — the
    // grandchild's groupedVariants computed needs attr_map present to
    // bucket rows, otherwise isGroupingActive flips false and the flat
    // fallback table flashes between the skeleton clearing and grouping
    // returning. reloader() is fire-and-forget and refetches attr_map
    // asynchronously, so we poll the reactive store until at least one
    // variant has an attr_map, capped at a sane timeout.
    await nextTick();
    const storeRef = props.productEditModel?.data?.product;
    const variantsHaveAttrMap = () => {
      const list = storeRef ? storeRef.variants : null;
      return Array.isArray(list) && list.some(variant => Array.isArray(variant.attr_map) && variant.attr_map.length > 0);
    };
    const TIMEOUT_MS = 2500;
    const start = Date.now();
    while (!variantsHaveAttrMap() && (Date.now() - start) < TIMEOUT_MS) {
      await new Promise(resolve => setTimeout(resolve, 50));
    }
    await nextTick();
    // Hide the "Updating variants…" text first; keep the skeleton up for
    // one more frame so the grouped tbody is fully painted before the
    // skeleton clears (avoids any tail-end flat-table flash).
    generating.value = false;
    await nextTick();
    await nextTick();
    isPersisting.value = false;
    // Pop the success toast now that the skeleton is gone and the fresh
    // grouped table is on screen — keeps the "Saved!" feedback aligned
    // with what the merchant actually sees.
    if (pendingSuccessMessage) {
      Notify.success(pendingSuccessMessage);
      pendingSuccessMessage = null;
    }
  });
}

// User typed/toggled values. Writes to the draft only — no server calls while editing,
// no leak into selectedAttributes. Newly typed strings stay as placeholders in
// draft.variants and are persisted on Done via persistNewTerms().
const handleVariantChange = (values) => {
  if (!draft.value) return;
  if (draft.value.errors && values && values.length > 0) {
    draft.value.errors.values = '';
  }
};

// Persist any string placeholders in attr.variants as terms server-side, then
// swap each placeholder for the real term ID. Called from persistPendingAttributes
// at the "Save to update variants" step — local staging keeps the placeholders
// until then so nothing hits the server while the merchant is still editing.
const persistNewTerms = async (attr, { silent = false } = {}) => {
  const existingTitles = getGroupTerms(attr.group_id).map(term => term.title.toLowerCase());
  const newTitles = (attr.variants || []).filter(value =>
    typeof value === 'string' && !existingTitles.includes(value.toLowerCase())
  );

  if (!newTitles.length) return true;

  const group = attributeGroups.value.find(candidate => sameId(candidate.id, attr.group_id));

  // Adopt whatever the server actually created, even when a later batch fails:
  // those terms exist now, and leaving them unmerged would let a retry re-send
  // the same titles (the dedup above reads group.terms).
  const adoptCreatedTerms = (createdTerms) => {
    if (!createdTerms.length) return;
    if (group) {
      if (!Array.isArray(group.terms)) group.terms = [];
      group.terms = [...group.terms, ...createdTerms];
    }
    // Replace each string placeholder with its corresponding term ID.
    createdTerms.forEach(term => {
      attr.variants = attr.variants.map(value =>
        typeof value === 'string' && value.toLowerCase() === term.title.toLowerCase() ? term.id : value
      );
    });
  };

  // Batched because the endpoint caps one request at ten terms; see
  // attributeTermCreator for why that has to stay sequential.
  const {created, message, error} = await createTermsInBatches(
    (path, body) => Rest.post(path, body),
    attr.group_id,
    newTitles
  );

  adoptCreatedTerms(created);
  // Drop any strings that the server didn't return a term for.
  attr.variants = attr.variants.filter(value => typeof value !== 'string');

  if (error) {
    // A 422 carries its reason in the errors bag, not in data.message — reading
    // only data.message replaced every validation failure (a title over 50
    // characters, a colour group missing its hex) with the generic string
    // below, so the merchant never learned what was wrong. Same handling as
    // createGroupFromName above.
    if (error?.status_code === 422 || error?.status === 422) {
      Notify.validationErrors(error);
    } else {
      Notify.error(error?.data?.message || translate('Failed to create option values'));
    }
    return false;
  }

  if (!silent) Notify.success(message || translate('Option values created successfully'));

  return true;
};

// Save-time materialisation. Local staging (collapseCard) leaves brand-new
// option names as a typed string in group_id and brand-new values as string
// placeholders in variants — no DB writes happen until the merchant clicks
// "Save to update variants". This walks every staged option, creating the real
// AttributeGroup + terms via the same endpoints the inline paths use, and
// rewrites selectedAttributes in place so the subsequent generate payload
// carries only numeric ids. Sequential on purpose — slug uniqueness is derived
// from already-persisted rows, so parallel creation could collide. Returns
// false (aborting the save) if any creation fails; the failing card keeps its
// inline name error and a toast surfaces the reason.
const persistPendingAttributes = async () => {
  for (const attr of selectedAttributes.value) {
    if (typeof attr.group_id === 'string' && attr.group_id.trim() !== '') {
      const numericId = await createGroupFromName(attr);
      if (!numericId) return false;
    }
    const termsOk = await persistNewTerms(attr, { silent: true });
    if (!termsOk) return false;
  }
  return true;
};

const getGroupType = (groupId) => {
  const group = attributeGroups.value.find(candidate => sameId(candidate.id, groupId));
  return (group && group.settings && group.settings.type) ? group.settings.type : 'options';
};

const getGroupTypeLabel = (groupId) => {
  const map = { options: translate('Text'), color: translate('Color'), image: translate('Image') };
  return map[getGroupType(groupId)] || getGroupType(groupId);
};

const getGroupTypeBadgeType = (groupId) => {
  const map = { options: 'info', color: 'warning', image: 'success' };
  return map[getGroupType(groupId)] || 'info';
};

const getSelectedTerms = (attr) => {
  if (!attr.variants || attr.variants.length === 0) return [];
  const terms = getGroupTerms(attr.group_id);
  return attr.variants.map(variantId => {
    // Values typed but not yet saved are still strings (local staging defers
    // term creation to the Save step). Render them as transient chips so the
    // collapsed card reflects what the merchant entered before any DB write.
    if (typeof variantId === 'string') {
      return { id: variantId, title: variantId };
    }
    return terms.find(term => sameId(term.id, variantId));
  }).filter(Boolean);
};

// Shopify-style placeholder: "Add color" / "Add size" — falls back to generic "Add another value"
const getValuePlaceholder = (attr) => {
  if (!attr.title) return translate('Add more value');
  /* translators: %1$s: attribute name lowercase (e.g. "color", "size") */
  return translate('Add %1$s', attr.title.toLowerCase());
};

// Holds the typed query the merchant entered in the color / image option-
// values el-select. When they press Enter and no existing term matches,
// we open AddTermModal pre-seeded with this string as the title so they
// don't have to retype it. Cleared every time the modal opens / closes.
const addTermInitialTitle = ref('');

// Live filter query inside the open card's color/image option-values
// el-select. Tracked via :filter-method (which also suppresses the
// default substring filter, so we re-implement it with filteredGroupTerms
// below). Gates the "Add as new value" CTA in the #footer slot.
const valuesPickerQuery = ref('');

// Query tracked for the options-type el-select (uses :filter-method
// so we can show the #footer add CTA and pre-fill the inline form).
const optionsPickerQuery = ref('');
let optionsPickerSelectEl = null;
const setOptionsPickerRef = (el) => { optionsPickerSelectEl = el; };

// Inline term creation form — shown in the #footer of the values picker
// dropdown instead of opening AddTermModal.
const inlineTermVisible = ref(false);
const inlineTerm = ref({ title: '', settings: { color: '', image: '' } });
const inlineTermLoading = ref(false);
const inlineTermErrors = ref({});

const handleValuesPickerFilter = (query) => {
  valuesPickerQuery.value = query || '';
  if (draft.value) {
    searchGroupTermsRemote(draft.value.group_id, query);
  }
};

// Manual replacement for Element Plus's default substring filter — it
// gets disabled the moment :filter-method is set. Filters the current
// card's group's terms by case-insensitive title substring so the
// el-option list still narrows as the merchant types.
const filteredGroupTerms = computed(() => {
  if (!draft.value || !draft.value.group_id) return [];
  const terms = getGroupTerms(draft.value.group_id);
  const query = valuesPickerQuery.value.trim().toLowerCase();
  if (!query) return terms;
  return terms.filter(term => (term.title || '').toLowerCase().includes(query));
});

// CTA fired from the color picker footer — shows the inline term form
// pre-seeded with the typed query instead of opening a modal.
const addTypedTermFromEmpty = () => {
  const query = valuesPickerQuery.value.trim();
  if (!query || !draft.value || !draft.value.group_id) return;
  const type = getGroupType(draft.value.group_id);
  if (type !== 'color' && type !== 'image') return;
  showInlineTermForm(query);
};

// Enter handler on the color/image option-values el-select. Bound in the
// CAPTURE phase (@keydown.capture) — NOT bubble — because Element Plus's
// own inner-input Enter handler runs with the "stop" modifier
// (event.stopPropagation()), so a bubble-phase listener on the el-select
// root never sees Enter at all. The capture listener on the root fires
// before the event reaches the input, so EP's later stopPropagation is
// irrelevant. Reads the typed text directly off the input via
// event.target.value (rather than relying on the filter-method query)
// so we get the live text regardless of selection state. Inline
// event.key check rather than the .enter modifier to keep the handler a
// plain function we can pass the card index into.
const handleValuesPickerKeyup = (event, attrIndex) => {
  if (!event || event.key !== 'Enter') return;
  if (!draft.value || !draft.value.group_id) return;
  const type = getGroupType(draft.value.group_id);
  if (type !== 'color' && type !== 'image') return;
  const input = event.target;
  const query = (input && typeof input.value === 'string' ? input.value : '').trim();
  if (!query) return;
  const existing = getGroupTerms(draft.value.group_id).find(
      t => typeof t.title === 'string' && t.title.trim().toLowerCase() === query.toLowerCase()
  );
  if (existing) return; // existing match — let el-select handle the selection
  // Stop EP's own Enter handler so it doesn't re-open or re-focus the
  // dropdown after we handle it here. preventDefault also blocks form submit.
  event.preventDefault();
  event.stopPropagation();
  // Both color and image: show inline form in the #footer — keep dropdown open.
  showInlineTermForm(query);
};

// The currently mounted color/image option-values el-select. Captured via a
// function ref rather than a template ref because the select lives inside the
// card v-for (only the expanded card renders it), and a template ref there
// would resolve to an array. Only one card is ever expanded, so this holds
// the single live instance (or null when none is mounted).
let valuesPickerSelectEl = null;
let dropdownLockHandler = null;
let dropdownLockBodyHandler = null;
let pendingEnterKeyupHandler = null;
const setValuesPickerRef = (el) => {
  valuesPickerSelectEl = el;
};

// Close the color/image picker dropdown if it is open. Called when opening
// AddTermModal so the dropdown does not linger behind the modal. el-select's
// own blur() collapses the dropdown and blurs the inner input; it is a no-op
// when the dropdown is already closed, so it is safe on every open path.
const closeValuesPicker = () => {
  if (valuesPickerSelectEl && typeof valuesPickerSelectEl.blur === 'function') {
    valuesPickerSelectEl.blur();
  }
};

const openAddTermModal = (attrIndex) => {
  closeValuesPicker();
  currentEditingAttrIndex.value = attrIndex;
  createModalIsOpen.value = true;
};

// Open the full Add-Group dialog (title + slug + type + styling) without
// leaving the product editor. We track which option-card triggered it so
// the new group can be auto-applied to that card on save.
const openCreateGroupModal = (attrIndex) => {
  createGroupTargetIndex.value = attrIndex;
  createGroupModalOpen.value = true;
};

// Append the newly created group to attributeGroups and auto-select it on the
// option card that opened the dialog. AddGroupModal now passes the saved group
// object in the event so no re-fetch is needed — selection is instant.
const onGroupCreated = (newGroup) => {
  if (!newGroup || !newGroup.id) {
    createGroupModalOpen.value = false;
    createGroupTargetIndex.value = null;
    return;
  }

  const targetIndex = createGroupTargetIndex.value;

  // Stamp an empty terms array so the option-values dropdown renders immediately
  // without waiting for ensureGroupTerms to fire.
  attributeGroups.value = [...attributeGroups.value, {...newGroup, terms: newGroup.terms || []}];

  // Auto-select on the card that triggered the dialog.
  if (targetIndex !== null && draft.value && expandedIndex.value === targetIndex) {
    draft.value.group_id = parseInt(newGroup.id, 10);
    draft.value.title = newGroup.title || '';
    draft.value.variants = [];
    if (draft.value.errors) draft.value.errors.name = '';
    ensureGroupTerms(parseInt(newGroup.id, 10));
  }

  createGroupModalOpen.value = false;
  createGroupTargetIndex.value = null;
};

const handleOptionsPickerFilter = (query) => {
  optionsPickerQuery.value = query || '';
  if (draft.value) {
    searchGroupTermsRemote(draft.value.group_id, query);
  }
};

const filteredOptionsTerms = computed(() => {
  if (!draft.value || !draft.value.group_id) return [];
  const terms = getGroupTerms(draft.value.group_id);
  const query = optionsPickerQuery.value.trim().toLowerCase();
  if (!query) return terms;
  return terms.filter(term => (term.title || '').toLowerCase().includes(query));
});

const filteredPendingTermStrings = computed(() => {
  if (!draft.value || !draft.value.group_id) return [];
  const pending = getPendingTermStrings(draft.value.group_id);
  const query = optionsPickerQuery.value.trim().toLowerCase();
  if (!query) return pending;
  return pending.filter(pendingTitle => (pendingTitle || '').toLowerCase().includes(query));
});

const showInlineTermForm = (preTitle) => {
  inlineTerm.value = { title: (preTitle || '').trim(), settings: { color: '', image: '' } };
  inlineTermVisible.value = true;
  inlineTermErrors.value = {};
  if (!dropdownLockHandler) {
    dropdownLockHandler = function(event) {
      var popper = document.querySelector('.fct-option-values-select');
      if (popper && popper.contains(event.target)) return;
      // Color-picker panel teleports to body outside the select popper — allow
      // the mousedown to reach the panel (capture does not stop), but the
      // body-level bubble handler below stops it before EP's document handler.
      var colorPanel = document.querySelector('.el-color-picker__panel');
      if (colorPanel && colorPanel.contains(event.target)) return;
      event.stopPropagation();
    };
    document.addEventListener('mousedown', dropdownLockHandler, true);
    // Bubble-phase stopper: fires after the color-picker target gets mousedown
    // but before EP's click-outside handler on document closes the dropdown.
    dropdownLockBodyHandler = function(event) {
      var colorPanel = document.querySelector('.el-color-picker__panel');
      if (colorPanel && colorPanel.contains(event.target)) {
        event.stopPropagation();
      }
    };
    document.body.addEventListener('mousedown', dropdownLockBodyHandler);
  }
  // The Enter keydown that triggered this function will produce a keyup
  // after the form mounts and the title input auto-focuses. Block that
  // single keyup so the TermForm does not immediately emit @submit.
  // Stored in pendingEnterKeyupHandler so onUnmounted can remove it if
  // the component is destroyed before the keyup fires.
  if (pendingEnterKeyupHandler) {
    document.removeEventListener('keyup', pendingEnterKeyupHandler, true);
  }
  pendingEnterKeyupHandler = function(event) {
    if (event.key === 'Enter') {
      event.stopPropagation();
      document.removeEventListener('keyup', pendingEnterKeyupHandler, true);
      pendingEnterKeyupHandler = null;
    }
  };
  document.addEventListener('keyup', pendingEnterKeyupHandler, true);
  // Cap the popper width to the trigger width so the color swatch grid wraps
  // instead of pushing the dropdown wider than the select input.
  // EP does not set min-width inline on this popper, so we measure the trigger
  // from the mounted component ref directly.
  nextTick(() => {
    var popper = document.querySelector('.fct-option-values-select');
    if (!popper) return;
    var selectInst = valuesPickerSelectEl || optionsPickerSelectEl;
    if (!selectInst) return;
    var rootEl = selectInst.$el || selectInst;
    var triggerW = rootEl ? rootEl.getBoundingClientRect().width : 0;
    if (triggerW > 0) {
      popper.style.maxWidth = triggerW + 'px';
    }
  });
};

const hideInlineTermForm = () => {
  inlineTermVisible.value = false;
  inlineTerm.value = { title: '', settings: { color: '', image: '' } };
  inlineTermErrors.value = {};
  if (dropdownLockHandler) {
    document.removeEventListener('mousedown', dropdownLockHandler, true);
    dropdownLockHandler = null;
  }
  if (dropdownLockBodyHandler) {
    document.body.removeEventListener('mousedown', dropdownLockBodyHandler);
    dropdownLockBodyHandler = null;
  }
  if (pendingEnterKeyupHandler) {
    document.removeEventListener('keyup', pendingEnterKeyupHandler, true);
    pendingEnterKeyupHandler = null;
  }
  var popper = document.querySelector('.fct-option-values-select');
  if (popper) {
    popper.style.maxWidth = '';
  }
};

const submitInlineTerm = async (attrIndex) => {
  const title = (inlineTerm.value.title || '').trim();
  if (!title || inlineTermLoading.value || !draft.value || !draft.value.group_id) return;
  inlineTermLoading.value = true;
  inlineTermErrors.value = {};
  const payload = {
    terms: [{ title, settings: { color: inlineTerm.value.settings.color || '', image: inlineTerm.value.settings.image || '' } }],
  };
  try {
    // A brand-new typed option name has no AttributeGroup yet — draft.group_id
    // is still the raw typed string, so posting a term would hit
    // options/attr/group/<typed-name>/terms and 404 "Attribute group not found".
    // Materialize the group first (same helper the Done path uses): it
    // re-points draft.group_id to the new numeric id and registers the group
    // locally so termCreatingIsDone can cache and auto-select the term.
    if (isCustomGroupName.value) {
      const groupSaved = await createGroupFromTypedName();
      if (!groupSaved) {
        return; // inline error already surfaced under the Option name field
      }
    }
    const response = await Rest.post('options/attr/group/' + draft.value.group_id + '/terms', payload);
    const created = Array.isArray(response.data) ? response.data[0] : response.data;
    currentEditingAttrIndex.value = attrIndex;
    termCreatingIsDone(created);
    hideInlineTermForm();
    valuesPickerQuery.value = '';
    optionsPickerQuery.value = '';
  } catch (errors) {
    if (errors && errors.status_code === 422 && errors.data) {
      inlineTermErrors.value = errors.data;
    } else {
      Notify.error((errors && errors.data && errors.data.message) || (errors && errors.message) || translate('Failed to add the term.'));
    }
  } finally {
    inlineTermLoading.value = false;
  }
};

const onPickerVisibleChange = (visible) => {
  if (visible) {
    if (draft.value) refreshGroupTerms(draft.value.group_id);
    return;
  }
  hideInlineTermForm();
  valuesPickerQuery.value = '';
};

const onOptionsPickerVisibleChange = (visible) => {
  if (visible) {
    if (draft.value) refreshGroupTerms(draft.value.group_id);
    return;
  }
  hideInlineTermForm();
  optionsPickerQuery.value = '';
};

const termCreatingIsDone = (newTerm) => {
  const attrIndex = currentEditingAttrIndex.value;
  const attr = selectedAttributes.value[attrIndex];

  if (attr) {
    const groupId = draft.value ? draft.value.group_id : attr.group_id;
    if (groupId && /^\d+$/.test(String(groupId))) {
      const numericId = parseInt(groupId, 10);
      const group = attributeGroups.value.find(candidate => sameId(candidate.id, numericId));
      if (group && newTerm && Array.isArray(group.terms)) {
        // Inject the new term directly — avoids a round-trip and keeps the
        // option list reactive immediately so the auto-select below is visible.
        group.terms.push(newTerm);
      } else {
        // Terms not cached yet or no term data returned — fall back to re-fetch.
        if (group) { delete group.terms; }
        ensureGroupTerms(numericId);
      }
      // Auto-select the newly created term in the option values.
      // Also remove any string placeholder that matches the new term's title —
      // e.g. an allow-create options-type entry typed before the form submitted.
      // Without this the variants array holds both the string and the ID, which
      // fails hasValidConfig (requires every variant to be a number).
      if (newTerm && draft.value && Array.isArray(draft.value.variants)) {
        const termTitle = (newTerm.title || '').trim().toLowerCase();
        draft.value.variants = draft.value.variants.filter(function(variantId) {
          return !(typeof variantId === 'string' && variantId.trim().toLowerCase() === termTitle);
        });
        if (!draft.value.variants.some(function(variantId) { return String(variantId) === String(newTerm.id); })) {
          draft.value.variants.push(newTerm.id);
        }
      }
    }
    Notify.success(translate('Term created successfully'));
  }

  createModalIsOpen.value = false;
  currentEditingAttrIndex.value = null;
  addTermInitialTitle.value = '';
};
</script>

<template>
  <div class="fct-advanced-variation-config">
    <div class="fct-adv-var-attributes">
      <!-- Loading skeleton — shown while the attribute library is being
           fetched (loadAttributeGroups). Renders whether selectedAttributes
           is empty or not: for an existing product the cards would
           otherwise render with missing term labels (the chips look up
           into attributeGroups which is still empty). Three collapsed-
           card placeholders match the resulting layout's height so the
           swap-in doesn't shift the page. -->
      <div v-if="loading" class="fct-adv-var-loading">
        <div
          v-for="i in (selectedAttributes.length || 3)"
          :key="i"
          class="fct-adv-var-loading-card"
        >
          <span class="fct-skel-block fct-adv-var-loading-handle"/>
          <span class="fct-skel-block fct-adv-var-loading-title"/>
          <span class="fct-skel-block fct-adv-var-loading-badge"/>
        </div>
      </div>

      <!-- Empty state — V3: distinct copy per case.
           (a) No groups in the system at all (rare, post-seeder this should never
               happen) — point the merchant to the attributes admin to create some.
           (b) Groups exist but no options on this product yet — descriptive container
               with info icon + the existing "Add option" button below. Matches the
               mockup's first-time merchant view. -->
      <div v-if="selectedAttributes.length === 0 && !loading && attributeGroups.length === 0" class="fct-adv-var-empty-hint">
        {{ translate('No attribute groups found.') }}
        <router-link :to="{ name: 'attributes' }">{{ translate('Create attribute groups') }}</router-link>
      </div>

      <div
          v-else-if="selectedAttributes.length === 0 && !loading"
          class="fct-adv-var-empty-state"
      >
        <span class="fct-adv-var-empty-state__icon" aria-hidden="true">
          <DynamicIcon name="Information"/>
        </span>
        <div class="fct-adv-var-empty-state__copy">
          <strong>{{ translate('No options added yet.') }}</strong>
          <span>{{ translate('Add options like Color, Size etc. to create variations.') }}</span>
        </div>
      </div>

      <!-- Option Cards — only mount once the library has loaded so the
           term-name chips have something to resolve against. -->
      <VueDraggableNext
          v-if="selectedAttributes.length > 0 && !loading"
          v-model="selectedAttributes"
          handle=".fct-option-drag-handle"
          :disabled="selectedAttributes.length <= 1 || expandedIndex !== null"
          class="fct-option-card-list"
      >
        <div
            v-for="(attr, index) in selectedAttributes"
            :key="attr.group_id || ('new-' + index)"
            class="fct-option-card"
        >

          <!-- Always-visible accordion header. Click toggles open/closed:
               opening seeds the draft from the attr (expandCard); closing
               stages the draft locally (collapseCard) — no DB write — with
               inline validation that keeps the card open with errors when the
               draft isn't complete. Persistence happens at the Save bar below. -->
          <div
              class="fct-option-card-collapsed"
              role="button"
              tabindex="0"
              :aria-expanded="expandedIndex === index"
              @click="toggleCard(index)"
              @keydown.enter.space.prevent="toggleCard(index)"
          >
            <span class="fct-option-drag-handle" v-if="selectedAttributes.length > 1" @click.stop>
              <DynamicIcon name="ReorderDotsVertical"/>
            </span>
            <div class="fct-option-card-summary">
              <div class="fct-option-card-summary-header">
                <strong>{{ optionDisplayTitle(attr, index) }}</strong>
                <el-tag
                    v-if="attr.group_id"
                    size="small"
                    :type="getGroupTypeBadgeType(attr.group_id)"
                    class="fct-group-type-badge"
                >{{ getGroupTypeLabel(attr.group_id) }}</el-tag>

              </div>
              <div v-if="getSelectedTerms(attr).length" class="fct-option-card-chips">
                <span
                    v-for="term in getSelectedTerms(attr)"
                    :key="term.id"
                    class="fct-option-value-chip"
                    :class="`fct-option-value-chip--${getGroupType(attr.group_id)}`"
                >
                  <span
                      v-if="getGroupType(attr.group_id) === 'color'"
                      class="fct-option-value-swatch"
                      :style="{ background: term.settings && term.settings.color ? term.settings.color : '' }"
                  ></span>
                  <img
                      v-else-if="getGroupType(attr.group_id) === 'image' && term.settings && term.settings.image"
                      :src="term.settings.image"
                      :alt="term.title"
                      class="fct-option-value-img"
                  />
                  <span class="fct-option-value-chip-label">{{ term.title }}</span>
                </span>
              </div>
            </div>
            <button
                v-if="expandedIndex === index"
                type="button"
                class="fct-option-card-toggle fct-option-card-toggle--open"
                :aria-label="translate('Done')"
                :title="translate('Done')"
                @click.stop="collapseCard(index)"
            >
              <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
          </div>

          <!-- Accordion body — binds to the draft, NOT to selectedAttributes.
               Without this draft layer, every keystroke / pick would mutate the saved
               configuration immediately (Element Plus v-model is two-way) and the user
               could no longer "cancel" an edit by closing without Done. -->
          <Animation accordion :visible="expandedIndex === index">
            <div v-if="expandedIndex === index && draft" class="fct-option-card-body">
              <div class="fct-option-field" :class="{ 'fct-option-field--error': draft.errors?.name }">
                <label class="fct-option-label">{{ translate('Option name') }}</label>
                <!-- allow-create lets the merchant type a brand-new option name (e.g. "Fabric")
                     that isn't yet in the library. The typed string becomes draft.group_id;
                     onGroupChange detects the string and offers the "Save as Template" button. -->
                <el-select
                    v-model="draft.group_id"
                    filterable
                    :allow-create="canCreateGroupFromQuery"
                    default-first-option
                    :filter-method="handleGroupNameFilter"
                    popper-class="fct-option-name-select"
                    :placeholder="translate('Select or type a new option')"
                    @change="onGroupChange($event)"
                    @visible-change="onGroupNamePickerVisibleChange"
                >
                  <el-option
                      v-for="group in filteredGroupsFor(index)"
                      :key="group.id"
                      :label="group.title"
                      :value="group.id"
                  />
                </el-select>
                <!-- Inline shortcut to the full Add-Group form (title + slug + type
                     + styling + description). Without this, the merchant has to
                     leave the product editor and visit #/attributes to create a
                     Color- or Image-typed group; this opens the same modal that
                     page uses, then auto-selects the new group on success.
                     Only shown when the card has no group selected yet (new option
                     state) — once a group is picked, the merchant is editing values
                     and "create new group" becomes irrelevant noise.
                     Wrapped in mt-2 div to match the left-aligned "Add new value"
                     pattern below — without the wrapper, parent flex/form layout
                     centers the el-button. -->
                <div v-if="!draft.group_id" class="mt-2">
                  <el-button
                      type="primary"
                      size="small"
                      text
                      class="fct-option-create-group"
                      @click="openCreateGroupModal(index)"
                  >
                    <DynamicIcon name="Plus"/>
                    {{ translate('Create new attribute group') }}
                  </el-button>
                </div>
                <div v-if="draft.errors?.name" class="fct-option-error">
                  <DynamicIcon name="AlertIcon"/>
                  <span>{{ draft.errors.name }}</span>
                </div>
              </div>
              <div class="fct-option-field" :class="{ 'fct-option-field--error': draft.errors?.values }">
                <label class="fct-option-label">{{ translate('Option values') }}</label>
                <!-- SELECT TYPE -->
                <el-select
                    v-if="getGroupType(draft.group_id) === 'options'"
                    :ref="setOptionsPickerRef"
                    v-model="draft.variants"
                    multiple
                    filterable
                    allow-create
                    default-first-option
                    :filter-method="handleOptionsPickerFilter"
                    :reserve-keyword="false"
                    tag-type="info"
                    popper-class="fct-option-values-select"
                    :placeholder="getValuePlaceholder(draft)"
                    :disabled="!draft.group_id"
                    :loading="isTermsLoading"
                    :loading-text="translate('Loading...')"
                    @change="handleVariantChange($event)"
                    @visible-change="onOptionsPickerVisibleChange"
                >
                  <!-- Draggable selected values — reorder changes the combination
                       order (serial_index) for THIS product; the new order flows
                       back via v-model into draft.variants → the save payload. -->
                  <template #tag>
                    <DraggableOptionValues
                        v-model="draft.variants"
                        :terms="getGroupTerms(draft.group_id)"
                        :type="getGroupType(draft.group_id)"
                    />
                  </template>
                  <template #header>
                    <div v-if="getGroupTerms(draft.group_id).length > 0" class="fct-select-all-header">
                      <el-checkbox
                        :model-value="isAllTermsSelected"
                        :indeterminate="someTermsSelected"
                        @change="toggleSelectAllTerms"
                      />
                      <span>{{ translate('Select all') }}</span>
                    </div>
                  </template>
                  <el-option
                      v-for="term in filteredOptionsTerms"
                      :key="term.id"
                      :label="term.title"
                      :value="term.id"
                  >
                    <div class="fct-option-item">
                      <el-checkbox
                        :model-value="draft.variants.some(function(variantId){ return sameId(variantId, term.id); })"
                        tabindex="-1"
                        @click.stop
                        @change="toggleTerm(term.id)"
                      />
                      <span>{{ term.title }}</span>
                    </div>
                  </el-option>
                  <el-option
                      v-for="pending in filteredPendingTermStrings"
                      :key="pending"
                      :label="pending"
                      :value="pending"
                  >
                    <div class="fct-option-item">
                      <el-checkbox
                        :model-value="true"
                        tabindex="-1"
                        @click.stop
                        @change="toggleTerm(pending)"
                      />
                      <span>{{ pending }}</span>
                    </div>
                  </el-option>
                  <template #footer>
                    <div class="fct-picker-inline-form" @mousedown.self.prevent>
                      <template v-if="!inlineTermVisible">
                        <el-button
                          v-if="optionsPickerQuery.trim() && !getGroupTerms(draft.group_id).some(function(term){ return term.title.toLowerCase() === optionsPickerQuery.trim().toLowerCase(); })"
                          type="primary"
                          size="small"
                          text
                          class="fct-inline-add-btn"
                          @click="showInlineTermForm(optionsPickerQuery)"
                        >
                          <DynamicIcon name="Plus"/>
                          <!-- translators: %1$s: the term title the user typed -->
                          {{ translate('Add "%1$s" as new value', optionsPickerQuery.trim()) }}
                        </el-button>
                      </template>
                      <template v-else>
                        <div class="fct-picker-inline-form-body">
                          <TermForm
                            :term="inlineTerm"
                            group-type="options"
                            :show-remove-button="false"
                            :validation-errors="inlineTermErrors"
                            field-key="terms.0"
                            @submit="submitInlineTerm(index)"
                            @remove="hideInlineTermForm"
                          />
                          <div class="fct-picker-inline-form-actions">
                            <el-button
                              type="primary"
                              size="small"
                              :loading="inlineTermLoading"
                              :disabled="!inlineTerm.title.trim() || inlineTermLoading"
                              @click="submitInlineTerm(index)"
                            >{{ translate('Add') }}</el-button>
                            <el-button size="small" @click="hideInlineTermForm">{{ translate('Cancel') }}</el-button>
                          </div>
                        </div>
                      </template>
                    </div>
                  </template>
                </el-select>

                <!-- COLOR TYPE -->
                <div
                    v-else-if="getGroupType(draft.group_id) === 'color'"
                    class="fct-option-field-values"
                >
                  <el-select
                      :ref="setValuesPickerRef"
                      v-model="draft.variants"
                      multiple
                      filterable
                      :filter-method="handleValuesPickerFilter"
                      :reserve-keyword="false"
                      tag-type="info"
                      popper-class="fct-option-values-select"
                      :placeholder="getValuePlaceholder(draft)"
                      :disabled="!draft.group_id"
                      :loading="isTermsLoading"
                      :loading-text="translate('Loading...')"
                      @keydown.capture="(event) => handleValuesPickerKeyup(event, index)"
                      @visible-change="onPickerVisibleChange"
                  >
                    <template #empty>
                      <div class="fct-option-empty-default">{{ translate('No matching data') }}</div>
                    </template>
                    <template #header>
                      <div v-if="getGroupTerms(draft.group_id).length > 0" class="fct-select-all-header">
                        <el-checkbox
                          :model-value="isAllTermsSelected"
                          :indeterminate="someTermsSelected"
                          @change="toggleSelectAllTerms"
                        />
                        <span>{{ translate('Select all') }}</span>
                      </div>
                    </template>
                    <template #tag>
                      <DraggableOptionValues
                          v-model="draft.variants"
                          :terms="getGroupTerms(draft.group_id)"
                          type="color"
                      />
                    </template>
                    <el-option
                        v-for="term in filteredGroupTerms"
                        :key="term.id"
                        :label="term.title"
                        :value="term.id"
                    >
                      <div class="fct-color-option">
                        <el-checkbox
                          :model-value="draft.variants.some(function(variantId){ return sameId(variantId, term.id); })"
                          tabindex="-1"
                          @click.stop
                          @change="toggleTerm(term.id)"
                        />
                        <span
                            class="fct-color-dot"
                            :style="{ background: term.settings && term.settings.color ? term.settings.color : '' }"
                        ></span>
                        {{ term.title }}
                      </div>
                    </el-option>
                    <template #footer>
                      <div class="fct-picker-inline-form" @mousedown.self.prevent>
                        <template v-if="!inlineTermVisible">
                          <el-button
                            v-if="valuesPickerQuery.trim() && !filteredGroupTerms.some(function(term){ return term.title.toLowerCase() === valuesPickerQuery.trim().toLowerCase(); })"
                            type="primary"
                            size="small"
                            text
                            class="fct-inline-add-btn"
                            @click="addTypedTermFromEmpty()"
                          >
                            <DynamicIcon name="Plus"/>
                            <!-- translators: %1$s: the term title the user typed -->
                            {{ translate('Add "%1$s" as new value', valuesPickerQuery.trim()) }}
                          </el-button>
                          <el-button
                            v-else
                            type="primary"
                            size="small"
                            text
                            class="fct-inline-add-btn"
                            @click="showInlineTermForm('')"
                          >
                            <DynamicIcon name="Plus"/>
                            {{ translate('Add new value') }}
                          </el-button>
                        </template>
                        <template v-else>
                          <div class="fct-picker-inline-form-body">
                            <TermForm
                              :term="inlineTerm"
                              group-type="color"
                              :show-remove-button="false"
                              :validation-errors="inlineTermErrors"
                              field-key="terms.0"
                              @submit="submitInlineTerm(index)"
                              @remove="hideInlineTermForm"
                            />
                            <div class="fct-picker-inline-form-actions">
                              <el-button
                                type="primary"
                                size="small"
                                :loading="inlineTermLoading"
                                :disabled="!inlineTerm.title.trim() || inlineTermLoading"
                                @click="submitInlineTerm(index)"
                              >{{ translate('Add') }}</el-button>
                              <el-button size="small" @click="hideInlineTermForm">{{ translate('Cancel') }}</el-button>
                            </div>
                          </div>
                        </template>
                      </div>
                    </template>
                  </el-select>
                </div>

                <!-- IMAGE TYPE -->
                <div
                    v-else-if="getGroupType(draft.group_id) === 'image'"
                    class="fct-option-field-values"
                >
                  <el-select
                      :ref="setValuesPickerRef"
                      v-model="draft.variants"
                      multiple
                      filterable
                      :filter-method="handleValuesPickerFilter"
                      :reserve-keyword="false"
                      tag-type="info"
                      popper-class="fct-option-values-select"
                      :placeholder="getValuePlaceholder(draft)"
                      :disabled="!draft.group_id"
                      :loading="isTermsLoading"
                      :loading-text="translate('Loading...')"
                      @keydown.capture="(event) => handleValuesPickerKeyup(event, index)"
                      @visible-change="onPickerVisibleChange"
                  >
                    <template #empty>
                      <div class="fct-option-empty-default">{{ translate('No matching data') }}</div>
                    </template>
                    <template #header>
                      <div v-if="getGroupTerms(draft.group_id).length > 0" class="fct-select-all-header">
                        <el-checkbox
                          :model-value="isAllTermsSelected"
                          :indeterminate="someTermsSelected"
                          @change="toggleSelectAllTerms"
                        />
                        <span>{{ translate('Select all') }}</span>
                      </div>
                    </template>
                    <template #tag>
                      <DraggableOptionValues
                          v-model="draft.variants"
                          :terms="getGroupTerms(draft.group_id)"
                          type="image"
                      />
                    </template>
                    <el-option
                        v-for="term in filteredGroupTerms"
                        :key="term.id"
                        :label="term.title"
                        :value="term.id"
                    >
                      <div class="fct-image-option">
                        <el-checkbox
                          :model-value="draft.variants.some(function(variantId){ return sameId(variantId, term.id); })"
                          tabindex="-1"
                          @click.stop
                          @change="toggleTerm(term.id)"
                        />
                        <img
                            :src="term.settings && term.settings.image ? term.settings.image : ''"
                            alt=""
                        />
                        {{ term.title }}
                      </div>
                    </el-option>
                    <template #footer>
                      <div class="fct-picker-inline-form" @mousedown.self.prevent>
                        <template v-if="!inlineTermVisible">
                          <el-button
                            v-if="valuesPickerQuery.trim() && !filteredGroupTerms.some(function(term){ return term.title.toLowerCase() === valuesPickerQuery.trim().toLowerCase(); })"
                            type="primary"
                            size="small"
                            text
                            class="fct-inline-add-btn"
                            @click="showInlineTermForm(valuesPickerQuery)"
                          >
                            <DynamicIcon name="Plus"/>
                            <!-- translators: %1$s: the term title the user typed -->
                            {{ translate('Add "%1$s" as new value', valuesPickerQuery.trim()) }}
                          </el-button>
                          <el-button
                            v-else
                            type="primary"
                            size="small"
                            text
                            class="fct-inline-add-btn"
                            @click="showInlineTermForm('')"
                          >
                            <DynamicIcon name="Plus"/>
                            {{ translate('Add new value') }}
                          </el-button>
                        </template>
                        <template v-else>
                          <div class="fct-picker-inline-form-body">
                            <TermForm
                              :term="inlineTerm"
                              group-type="image"
                              :show-remove-button="false"
                              :validation-errors="inlineTermErrors"
                              field-key="terms.0"
                              @submit="submitInlineTerm(index)"
                              @remove="hideInlineTermForm"
                            />
                            <div class="fct-picker-inline-form-actions">
                              <el-button
                                type="primary"
                                size="small"
                                :loading="inlineTermLoading"
                                :disabled="!inlineTerm.title.trim() || inlineTermLoading"
                                @click="submitInlineTerm(index)"
                              >{{ translate('Add') }}</el-button>
                              <el-button size="small" @click="hideInlineTermForm">{{ translate('Cancel') }}</el-button>
                            </div>
                          </div>
                        </template>
                      </div>
                    </template>
                  </el-select>
                </div>

                <div v-if="draft.errors?.values" class="fct-option-error">
                  <DynamicIcon name="AlertIcon"/>
                  <span>{{ draft.errors.values }}</span>
                </div>
              </div>
              <!-- No per-card Save/Discard and no Done button: the header chevron
                   collapses + stages the card's edit (validation runs first, so an
                   empty option name/value keeps the card open with errors), and the
                   single "Save to update variants" bar below commits + generates all
                   combinations at once. Delete is the one in-card action. -->
              <div class="fct-option-card-actions">
                <el-button class="fct-adv-var-del-button" type="default" size="small" @click="removeAttribute(index)">
                  {{ translate('Delete') }}
                </el-button>
              </div>
            </div>
          </Animation>
        </div>
      </VueDraggableNext>

      <!-- Add option button — V3: opens the library picker dialog instead of dropping
           a blank card. The picker shows the full templates list with search;
           "+ Create new option" inside the picker falls through to addNewAttribute()
           for the legacy blank-card flow. -->
      <div
          v-if="attributeGroups.length > 0"
          class="fct-option-add-wrap"
          :class="{ 'fct-option-add-wrap--empty': selectedAttributes.length === 0 }"
      >
        <el-button
            class="fct-option-add"
            :class="{ 'fct-option-add--empty': selectedAttributes.length === 0 }"
            size="small"
            @click="addNewAttribute"
        >
          <svg fill="currentColor" width="14" height="14" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path d="M4.25 8a.75.75 0 0 1 .75-.75h2.25v-2.25a.75.75 0 0 1 1.5 0v2.25h2.25a.75.75 0 0 1 0 1.5h-2.25v2.25a.75.75 0 0 1-1.5 0v-2.25h-2.25a.75.75 0 0 1-.75-.75"></path><path fill-rule="evenodd" d="M8 15a7 7 0 1 0 0-14 7 7 0 0 0 0 14m0-1.5a5.5 5.5 0 1 0 0-11 5.5 5.5 0 1 0 0 11"></path></svg>

          {{ selectedAttributes.length === 0 ? translate('Add options like size or color') : translate('Add more') }}
        </el-button>

        <!-- Combinations counter — always shows the count the current
             option config would produce. When changes are pending, the
             save bar above the Add-more button surfaces Discard / Save
             instead; this chip stays as a status indicator (no longer
             doubles as a Generate button). -->
        <span
          v-if="stagedCombinations > 0"
          class="fct-adv-var-combinations-chip"
          :title="translate('Variant combinations the current options will generate (max %1$s)', MAX_COMBINATIONS)"
        >
          {{
            /* translators: %1$s: number of variant combinations the current option config will produce */
            translate('%1$s combinations', stagedCombinations)
          }}
        </span>
      </div>
      
      <!-- Pending-changes save bar — shows whenever the in-memory option
           config drifts from the last-generated snapshot. Covers BOTH
           drag-reorders and per-card Save'd-without-Generate edits, so
           one bar replaces the previous order-bar + Generate-chip combo
           (which both fired the same generateVariations under the hood).
           Hidden during an active edit (expandedIndex !== null) and
           during the Save-button generate cycle (isPersisting) — the
           latter prevents a flash of the bar between draft-commit and
           snapshot-update when the option card's Save fires its own
           generateVariations. -->
      <div v-if="(hasPendingChanges || isDraftDirty) && !isPersisting" class="fct-adv-order-save-bar">
        <span class="fct-adv-order-save-bar__msg">{{ saveBarMessage }}</span>
        <div class="fct-adv-order-save-bar__actions">
          <el-button size="small" :disabled="generating" @click="discardOrderChange">
            {{ translate('Discard') }}
          </el-button>
          <el-button size="small" type="primary" :loading="generating" @click="saveOrderChange">
            {{ translate('Save') }}
          </el-button>
        </div>
      </div>

      <!-- Status (only show when something needs the user's attention) -->
      <div v-if="combinationsExceeded || tooManyGroups || hasDuplicateGroups || identifierTooLong">
        <div class="fct-adv-var-generate-info fct-adv-var-generate-error" v-if="tooManyGroups">
          <span>{{
            /* translators: %1$s: maximum allowed attribute groups */
            translate('Too many attribute groups. Maximum allowed is %1$s. Remove some groups.', MAX_GROUPS)
          }}</span>
        </div>
        <div class="fct-adv-var-generate-info fct-adv-var-generate-error" v-else-if="hasDuplicateGroups">
          <span>{{ translate('The same attribute group is selected more than once. Remove the duplicate.') }}</span>
        </div>
        <div class="fct-adv-var-generate-info fct-adv-var-generate-error" v-else-if="combinationsExceeded">
          <span>{{
            /* translators: %1$s: current combinations count, %2$s: maximum allowed combinations */
            translate('Too many combinations (%1$s). Maximum allowed is %2$s. Remove some option values.', totalCombinations, MAX_COMBINATIONS)
          }}</span>
        </div>
        <div class="fct-adv-var-generate-info fct-adv-var-generate-error" v-else-if="identifierTooLong">
          <span>{{
            /* translators: %1$s: maximum allowed identifier length */
            translate('These options would create variant identifiers longer than %1$s characters. Use fewer attribute groups.', MAX_IDENTIFIER_LENGTH)
          }}</span>
        </div>
      </div>
    </div>

    <!-- Variant Table — shown whenever at least one option is fully configured
         (group_id + values) and product.variants exist in the DB. It stays
         visible while a card is expanded so the merchant keeps pricing context
         during an edit; the rows reflect the last generation until Done runs
         the regeneration. -->
    <AdvancedVariationTable
        v-if="selectedAttributes.some(attr => attr.group_id && attr.variants && attr.variants.length > 0) && product.variants && product.variants.length > 0 && product.detail?.variation_type === 'advanced_variations'"
        :product="product"
        :productEditModel="productEditModel"
        :attributeGroups="attributeGroups"
        :external-busy="isPersisting || generating || loading"
    />

    <!-- Add-term modal is only ever opened from the expanded editor, so the draft's
         group_id is the right source of truth (the underlying attribute may still be a
         stub for a brand-new option). -->
    <el-dialog :append-to-body="true" v-model="createModalIsOpen" :title="translate('Add new attribute term')" @close="addTermInitialTitle = ''">
      <AddTermModal
        v-if="createModalIsOpen && currentEditingAttrIndex !== null && draft"
        :group-id="draft.group_id"
        :group="attributeGroups.find(candidate => sameId(candidate.id, draft.group_id))"
        :initial-title="addTermInitialTitle"
        @is-term-creating-done="termCreatingIsDone"
      />
    </el-dialog>

    <!-- Add new attribute group — same modal the standalone Attributes admin page
         uses, so the merchant gets the full Title + Slug + Type + Styling form
         without leaving the product editor. v-if gates mounting so the form
         resets between opens. -->
    <el-dialog
        :append-to-body="true"
        width="50%"
        v-model="createGroupModalOpen"
        :title="translate('Add new attribute group')"
    >
      <AddGroupModal
          v-if="createGroupModalOpen"
          @when-group-create-is-done="onGroupCreated"
      />
    </el-dialog>
  </div>
</template>

<style scoped>

</style>
