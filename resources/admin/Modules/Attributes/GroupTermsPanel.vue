<script setup>
import { ref, computed, nextTick } from 'vue';
import translate from "@/utils/translator/Translator";
import Rest from '@/utils/http/Rest';
import Confirmation from "@/utils/Confirmation";
import Notify from "@/utils/Notify";
import AttrTermsTableBody from "./AttrTermsTableBody.vue";
import AttrTermsTableLoader from "./AttrTermsTableLoader.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import TermForm from "./TermForm.vue";

const props = defineProps({
    group: { type: Object, required: true },
});

// The group-edit modal lives in the parent (AttrGroups) alongside the
// sidebar's pencil; the breadcrumb edit icon emits up so both entry points
// reuse the same EditGroupModal flow.
const emit = defineEmits(['edit-group']);

const groupId = computed(() => String(props.group.id));

const groupType = computed(() => {
    const settings = props.group && props.group.settings;
    return (settings && settings.type) ? settings.type : 'options';
});

const typeLabel = computed(() => {
    const map = {
        options: translate('Options'),
        color:   translate('Color'),
        image:   translate('Image'),
    };
    return map[groupType.value] || translate('Options');
});

const stylingLabel = computed(() => {
    const map = {
        dropdown: translate('Dropdown'),
        button:   translate('Button'),
    };
    return map[props.group.settings?.styling || 'button'] || translate('Button');
});

// Inline edit: editingTerm holds a working copy of the term whose pill has
// been swapped for an inline form. null means no term is being edited.
const editingTerm = ref(null);
const editingTermSnapshot = ref(null);
const isSavingEdit = ref(false);
const editValidationErrors = ref({});

const PER_PAGE = 15;
const page = ref(1);
const allTerms = ref([]);
const total = ref(0);
const isLoading = ref(true);
const isLoadingMore = ref(false);

const hasMore = computed(() => allTerms.value.length < total.value);

const syncBtnLabel = computed(() => {
    if (hasMore.value) {
        /* translators: %1$s: loaded count, %2$s: total count */
        return translate('Load More (%1$s/%2$s)', allTerms.value.length, total.value);
    }
    /* translators: %1$s: total count */
    return translate('All loaded (%1$s)', total.value);
});

// Monotonic request id — every fetch captures its own version, and the
// then/catch/finally short-circuit when a newer fetch has started. Without
// this, a slow page-2 response can arrive after a faster reload (post-save
// refresh, etc.) and clobber the visible list.
let fetchVersion = 0;

const fetchPage = (p, append) => {
    const myVersion = ++fetchVersion;
    if (!append) {
        isLoading.value = true;
    } else {
        isLoadingMore.value = true;
    }
    return Rest.get('options/attr/group/' + groupId.value + '/terms', {
        per_page: PER_PAGE,
        page: p,
    }).then(res => {
        if (myVersion !== fetchVersion) return false; // stale — newer fetch in flight
        const data = (res.terms && res.terms.data) ? res.terms.data : [];
        total.value = (res.terms && res.terms.total) ? Number(res.terms.total) : 0;
        allTerms.value = append ? allTerms.value.concat(data) : data;
        return true; // loaded successfully — reveal walk may continue
    }).catch(errors => {
        if (myVersion !== fetchVersion) return false;
        Notify.error(errors.data?.message || translate('Failed to load terms'));
        return false; // failed — reveal walk must stop, not keep paging
    }).finally(() => {
        if (myVersion !== fetchVersion) return; // newer fetch owns the loading flags
        isLoading.value = false;
        isLoadingMore.value = false;
    });
};

fetchPage(1, false);

const loadMore = () => {
    if (!hasMore.value || isLoadingMore.value || isLoading.value) return;
    page.value++;
    fetchPage(page.value, true);
};

// Infinite scroll: when the user is within 120px of the bottom of the
// scrollable body, kick off the next page. The hasMore + isLoadingMore
// gating inside loadMore() prevents duplicate fetches.
const onBodyScroll = (e) => {
    const el = e.target;
    if (!el) return;
    const remaining = el.scrollHeight - el.scrollTop - el.clientHeight;
    if (remaining < 120) {
        loadMore();
    }
};

const reload = () => {
    page.value = 1;
    fetchPage(1, false);
};

// Scroll container for the term list — captured so a freshly created term can
// be scrolled into view (template ref rather than a global DOM query).
const panelBodyRef = ref(null);

// New terms append to the END of the serial order, so on a multi-page list they
// land on a later page. Reload page 1, then page forward until the last created
// term is loaded, then scroll the panel body to the bottom where the fresh terms
// render — mirrors the group-create reveal in AttrGroups.vue so a term added on
// a long list doesn't stay hidden below the fold. Bounded by hasMore (stops at
// the last page) plus a hard guard; pages load sequentially so rows fill in with
// no gaps or duplicates.
const revealNewTerms = async (lastId) => {
    page.value = 1;
    if (!(await fetchPage(1, false))) return; // reload failed/stale — nothing to walk to
    let guard = 0;
    while (lastId && !allTerms.value.some(t => t.id === lastId) && hasMore.value && guard < 50) {
        guard++;
        page.value++;
        if (!(await fetchPage(page.value, true))) return; // stop on failure/stale
    }
    nextTick(() => {
        const body = panelBodyRef.value;
        if (body) body.scrollTo({ top: body.scrollHeight, behavior: 'smooth' });
    });
};

defineExpose({ refresh: reload });

const handleEdit = (row) => {
    // Snapshot the original so Cancel can revert in-place without a refetch.
    editingTermSnapshot.value = JSON.parse(JSON.stringify(row));
    editingTerm.value = JSON.parse(JSON.stringify(row));
    if (!editingTerm.value.settings) {
        editingTerm.value.settings = { color: '', image: '' };
    }
    editValidationErrors.value = {};
    // TermForm watches its `term` prop and auto-focuses + re-seeds the
    // swatch tabindex anchor when this ref changes.
};

const cancelEdit = () => {
    editingTerm.value = null;
    editingTermSnapshot.value = null;
    editValidationErrors.value = {};
};

const saveEdit = () => {
    if (!editingTerm.value) return;
    const title = (editingTerm.value.title || '').trim();
    if (!title || isSavingEdit.value) return;
    isSavingEdit.value = true;
    editValidationErrors.value = {};
    Rest.post('options/attr/group/' + groupId.value + '/term/' + editingTerm.value.id, {
        title,
        settings: {
            color: editingTerm.value.settings?.color || '',
            image: editingTerm.value.settings?.image || '',
        },
    }).then(response => {
        Notify.success(response.message || translate('Term updated'));
        editingTerm.value = null;
        editingTermSnapshot.value = null;
        reload();
    }).catch(errors => {
        if (errors?.status_code == 422 && errors.data) {
            // Single-term update endpoint returns flat field keys
            // (`title`, `settings.color`, `settings.image`) — TermForm
            // resolves them as-is.
            editValidationErrors.value = errors.data;
        } else {
            Notify.error(errors.data?.message || errors?.message || translate('Failed to update term'));
        }
    }).finally(() => {
        isSavingEdit.value = false;
    });
};

const handleDelete = (row) => {
    Confirmation.ofDelete(
        translate('Are you sure you want to delete this term? This action is not recoverable.'),
        translate('Confirm Delete!'),
        { confirmButtonText: translate('Yes, Delete') }
    ).then(() => {
        Rest.delete('options/attr/group/' + groupId.value + '/term/' + row.id)
            .then(response => {
                Notify.success(response.message);
                reload();
            })
            .catch(errors => {
                Notify.error(errors.data?.message || translate('Delete failed'));
            });
    }).catch(() => {});
};

const handleReorder = (ids) => {
    Rest.post('options/attr/group/' + groupId.value + '/terms/reorder', { ids })
        .then(response => {
            Notify.success(response.message);
        })
        .catch(errors => {
            Notify.error(errors.data?.message || translate('Failed to save order'));
            reload();
        });
};


/* ---- Inline draft creation: add multiple terms at once ----
   The LAST draft in the array is the active form section; earlier drafts
   render as compact list rows. "Add more" finalises the current form and
   pushes a new empty draft on top. */

// Mirrors Pro's AttrTermRequest::rules() cap of 10 terms per batch — the
// frontend disables "Add more" past this limit so the user gets immediate
// feedback instead of a 422 at save time.
const MAX_DRAFTS = 10;

const drafts = ref([]);
const isSavingDrafts = ref(false);
// Index of a completed draft the user clicked to re-open. While set, that
// row renders as an inline TermForm instead of the compact pill — lets the
// user fix a per-row validation error without deleting and re-adding.
const editingDraftIdx = ref(null);
// Raw 422 payload from the bulk endpoint, keys preserved
// (`terms.0.title`, `terms.2.settings.color`, `terms.required`, …).
// Each TermForm composes its lookups via a `fieldKeyPrefix` matching the
// payload row it represents — no key normalization happens here.
const draftValidationErrors = ref({});
// Reverse of payloadIndexToDraftIndex from the last save attempt — maps a
// completed-draft index back to the index the server actually saw, so per-row
// rendering knows which `terms.N.` prefix to use.
const draftIdxToPayloadIdx = ref({});

const activeDraft = computed(() =>
    drafts.value.length ? drafts.value[drafts.value.length - 1] : null
);
const completedDrafts = computed(() => drafts.value.slice(0, -1));
// Resolve the row identifier `terms.<payloadIdx>` for a given drafts[]
// index — matches the VariantPrice convention where parents pass the
// per-row base (`variants.0`) and the child composes `${fieldKey}.field`.
// Returns '' if the row wasn't in the last save attempt, which makes
// child lookups no-op (fields stay un-errored).
const draftFieldKey = (draftIdx) => {
    const payloadIdx = draftIdxToPayloadIdx.value[draftIdx];
    return payloadIdx === undefined ? '' : `terms.${payloadIdx}`;
};

const hasDraftError = (draftIdx) => {
    const key = draftFieldKey(draftIdx);
    if (!key) return false;
    const prefix = `${key}.`;
    return Object.keys(draftValidationErrors.value).some(k => k.indexOf(prefix) === 0);
};

const openDraftForEdit = (idx) => {
    editingDraftIdx.value = idx;
};

const closeDraftEdit = () => {
    editingDraftIdx.value = null;
};

// A draft is "complete" once all type-required fields are filled — title
// is always required, plus a color hex for color groups or an image URL
// for image groups. Mirrors the backend AttrTermRequest rules so the user
// can't push a row to "Add more" that the API will then reject.
const isDraftComplete = (d) => {
    if (!d) return false;
    if (!d.title || !d.title.trim()) return false;
    if (groupType.value === 'color') return !!(d.settings && d.settings.color);
    if (groupType.value === 'image') return !!(d.settings && d.settings.image);
    return true;
};

const validDrafts = computed(() => drafts.value.filter(isDraftComplete));

const isActiveDraftComplete = computed(() => isDraftComplete(activeDraft.value));

const canAddMoreDrafts = computed(() => {
    if (drafts.value.length >= MAX_DRAFTS) return false;
    // The first draft can be added without any precondition; subsequent
    // "Add more" requires the current active draft to be complete so we
    // never push a fresh form on top of an unfinished one.
    if (activeDraft.value && !isActiveDraftComplete.value) return false;
    return true;
});

const addMoreDisabledLabel = computed(() => {
    if (drafts.value.length >= MAX_DRAFTS) {
        /* translators: %1$s: maximum number of draft terms in one batch */
        return translate('Limit reached (%1$s)', MAX_DRAFTS);
    }
    return translate('Add more');
});

const addMoreDisabledTitle = computed(() => {
    if (drafts.value.length >= MAX_DRAFTS) {
        /* translators: %1$s: maximum number of draft terms in one batch */
        return translate('Save these %1$s and add more after.', MAX_DRAFTS);
    }
    if (groupType.value === 'color') {
        return translate('Fill the title and color before adding another.');
    }
    if (groupType.value === 'image') {
        return translate('Fill the title and image before adding another.');
    }
    return translate('Fill the title before adding another.');
});

const addDraft = () => {
    if (!canAddMoreDrafts.value) return;
    drafts.value.push({
        title: '',
        settings: { color: '', image: '' },
    });
    // TermForm auto-focuses on prop change (watch in component).
};

const removeDraft = (idx) => {
    drafts.value.splice(idx, 1);
    if (editingDraftIdx.value === idx) {
        editingDraftIdx.value = null;
    } else if (editingDraftIdx.value !== null && editingDraftIdx.value > idx) {
        editingDraftIdx.value -= 1;
    }
    // Re-index the draft→payload map: shift entries past `idx` down by
    // one, drop the removed row's mapping. The raw error keys
    // (`terms.<payloadIdx>.<field>`) stay as-is — `payloadIdx` is the
    // server's frame, independent of the local draft array.
    const next = {};
    Object.entries(draftIdxToPayloadIdx.value).forEach(([k, v]) => {
        const i = Number(k);
        if (i < idx) next[i] = v;
        else if (i > idx) next[i - 1] = v;
    });
    draftIdxToPayloadIdx.value = next;
};

const onDraftSubmit = () => {
    // Enter in the title input. For options-type, add another empty draft;
    // color/image have richer controls so Enter shouldn't auto-advance.
    if (groupType.value === 'options') addDraft();
};

const saveDrafts = () => {
    if (!validDrafts.value.length || isSavingDrafts.value) return;
    isSavingDrafts.value = true;
    draftValidationErrors.value = {};
    // Build payload + remember which drafts[] index each payload row came
    // from. After a 422, this map lets each row look up its server prefix
    // (`terms.<payloadIdx>.`) for inline error rendering.
    const reverseMap = {};
    const termsPayload = [];
    drafts.value.forEach((d, i) => {
        if (d.title && d.title.trim() !== '') {
            reverseMap[i] = termsPayload.length;
            termsPayload.push({
                title: d.title.trim(),
                settings: {
                    color: d.settings.color || '',
                    image: d.settings.image || '',
                },
            });
        }
    });
    draftIdxToPayloadIdx.value = reverseMap;
    Rest.post('options/attr/group/' + groupId.value + '/terms', { terms: termsPayload })
        .then(response => {
            Notify.success(response.message || translate('Terms added'));
            drafts.value = [];
            editingDraftIdx.value = null;
            draftIdxToPayloadIdx.value = {};
            const createdIds = (response.data || []).map(term => term.id);
            revealNewTerms(createdIds.length ? createdIds[createdIds.length - 1] : null);
        })
        .catch(errors => {
            if (errors?.status_code == 422 && errors.data) {
                // Store the raw server map — each TermForm (and the
                // per-row ValidationErrors below) composes lookups via
                // its own `terms.<payloadIdx>.` prefix.
                draftValidationErrors.value = errors.data;
            } else {
                Notify.error(errors.data?.message || translate('Failed to save terms'));
            }
        })
        .finally(() => {
            isSavingDrafts.value = false;
        });
};

</script>

<template>
    <div class="fct-group-terms-panel">

        <!-- Panel header -->
        <div class="fct-attr-panel-header">
            <div class="fct-attr-panel-header-top">
                <div class="fct-attr-panel-breadcrumb">
                    <span class="fct-attr-panel-breadcrumb-root">{{ translate('Attributes') }}</span>
                    <span class="fct-attr-panel-breadcrumb-sep">›</span>
                    <span class="fct-attr-panel-breadcrumb-current">{{ group.title }}</span>
                    <button
                        type="button"
                        class="fct-attr-icon-btn"
                        :aria-label="translate('Edit group')"
                        @click="emit('edit-group')"
                    >
                        <DynamicIcon name="Edit"/>
                    </button>
                </div>
                <el-button
                    v-if="drafts.length"
                    size="small"
                    type="primary"
                    :disabled="!validDrafts.length || isSavingDrafts"
                    :loading="isSavingDrafts"
                    @click="saveDrafts"
                >
                    {{ translate('Save') }}
                    <template v-if="validDrafts.length"> ({{ validDrafts.length }})</template>
                </el-button>
            </div>
            <div class="fct-attr-panel-chips">
                <span class="fct-attr-meta-chip">{{ total }} {{ translate('Terms') }}</span>
                <span class="fct-attr-meta-chip">
                    <span class="fct-attr-meta-chip-label">{{ translate('Type') }}</span>
                    {{ typeLabel }}
                </span>
                <span class="fct-attr-meta-chip">
                    <span class="fct-attr-meta-chip-label">{{ translate('Styling') }}</span>
                    {{ stylingLabel }}
                </span>
            </div>
        </div>

        <!-- Scrollable body: terms + drafts + add button -->
        <div ref="panelBodyRef" class="fct-attr-panel-body" @scroll.passive="onBodyScroll">
            <AttrTermsTableLoader v-if="isLoading"/>
            <template v-else>
                <!-- Drag-reorder is blocked while more pages exist: posting a
                     partial id list to /terms/reorder would truncate the
                     persisted order. role="status" + aria-live so screen
                     readers hear when reorder becomes enabled/disabled as
                     the user crosses a pagination boundary. Wording avoids
                     hard-coding the "Load More" label since that is itself
                     translated and may not match a localized build. -->
                <div
                    v-if="hasMore"
                    class="fct-term-reorder-locked"
                    role="status"
                    aria-live="polite"
                >
                    {{ translate('Load every term before reordering.') }}
                </div>
                <AttrTermsTableBody
                    :rows="allTerms"
                    :group="group"
                    :editing-term-id="editingTerm ? editingTerm.id : null"
                    :disable-reorder="hasMore"
                    @edit="handleEdit"
                    @delete="handleDelete"
                    @reorder="handleReorder"
                >
                    <template #edit-form>
                        <TermForm
                            v-if="editingTerm"
                            class="fct-term-edit-form"
                            :term="editingTerm"
                            :group-type="groupType"
                            :show-remove-button="false"
                            :validation-errors="editValidationErrors"
                            @remove="cancelEdit"
                            @submit="saveEdit"
                        >
                            <template #actions>
                                <div class="fct-term-edit-actions">
                                    <el-button size="small" @click="cancelEdit">
                                        {{ translate('Cancel') }}
                                    </el-button>
                                    <el-button
                                        size="small"
                                        type="primary"
                                        :disabled="!(editingTerm.title || '').trim() || isSavingEdit"
                                        :loading="isSavingEdit"
                                        @click="saveEdit"
                                    >
                                        {{ translate('Save') }}
                                    </el-button>
                                </div>
                            </template>
                        </TermForm>
                    </template>
                </AttrTermsTableBody>

                <!-- Completed drafts: compact list rows. Each row is wrapped
                     so a per-row validation error block can render directly
                     beneath the row it belongs to — matches the pattern in
                     VariantPrice/VariantTitleMedia where ValidationError is
                     keyed dynamically by row index (`variants.${idx}.*`). -->
                <div v-if="completedDrafts.length" class="fct-term-drafts-completed">
                    <div
                        v-for="(draft, idx) in completedDrafts"
                        :key="'completed-' + idx"
                        class="fct-term-row-item-wrap"
                    >
                        <!-- Re-open mode: a server-side validation error
                             cannot be fixed from the compact pill, so when
                             the user clicks the row (or its error) we swap
                             it for a full TermForm bound to the same draft
                             object. Re-using `drafts[idx]` keeps the array
                             order — no reshuffling — and shares the same
                             `draftValidationErrors[idx]` map for inline
                             error rendering. -->
                        <TermForm
                            v-if="editingDraftIdx === idx"
                            :term="drafts[idx]"
                            :group-type="groupType"
                            :validation-errors="draftValidationErrors"
                            :field-key="draftFieldKey(idx)"
                            @remove="closeDraftEdit"
                            @submit="closeDraftEdit"
                        />
                        <template v-else>
                            <div
                                class="fct-term-row-item fct-term-row-item--draft"
                                :class="{ 'is-error': hasDraftError(idx), 'is-clickable': hasDraftError(idx) }"
                                role="button"
                                tabindex="0"
                                :aria-label="translate('Edit draft')"
                                @click="openDraftForEdit(idx)"
                                @keydown.enter.prevent="openDraftForEdit(idx)"
                                @keydown.space.prevent="openDraftForEdit(idx)"
                            >
                                <span class="fct-term-row-handle" aria-hidden="true">
                                    <DynamicIcon name="ReorderDotsVertical"/>
                                </span>

                                <span
                                    v-if="groupType === 'color' && draft.settings.color"
                                    class="fct-term-row-color-dot"
                                    :style="{ background: draft.settings.color }"
                                />
                                <img
                                    v-else-if="groupType === 'image' && draft.settings.image"
                                    :src="draft.settings.image"
                                    :alt="draft.title"
                                    class="fct-term-row-img"
                                />

                                <span class="fct-term-row-name">{{ draft.title || translate('No Name') }}</span>

                                <button
                                    type="button"
                                    class="fct-term-row-delete"
                                    :aria-label="translate('Remove draft')"
                                    @click.stop="removeDraft(idx)"
                                >
                                    <DynamicIcon name="Delete"/>
                                </button>
                            </div>

                        </template>
                    </div>
                </div>

                <!-- Active draft: full form section (last in drafts array) -->
                <TermForm
                    v-if="activeDraft"
                    :term="activeDraft"
                    :group-type="groupType"
                    :validation-errors="draftValidationErrors"
                    :field-key="draftFieldKey(drafts.length - 1)"
                    @remove="removeDraft(drafts.length - 1)"
                    @submit="onDraftSubmit"
                />

            </template>
        </div>

        <!-- Sticky footer: + Add Terms (main) + small sync icon for Load More -->
        <div v-if="!isLoading" class="fct-attr-panel-footer fct-attr-panel-footer--split">
            <button
                type="button"
                class="fct-attr-add-attr-btn fct-attr-add-attr-btn--main"
                :disabled="!canAddMoreDrafts"
                :title="canAddMoreDrafts
                    ? null
                    : addMoreDisabledTitle"
                @click="addDraft"
            >
                <DynamicIcon name="Plus"/>
                <template v-if="!canAddMoreDrafts">{{ addMoreDisabledLabel }}</template>
                <template v-else>{{ drafts.length ? translate('Add more') : translate('Add Terms') }}</template>
            </button>
            <button
                v-if="total > 0"
                type="button"
                class="fct-attr-panel-footer-sync"
                :disabled="!hasMore || isLoadingMore"
                :title="syncBtnLabel"
                :aria-label="syncBtnLabel"
                @click="loadMore"
            >
                <DynamicIcon name="Reload" :class="{ 'fct-spin': isLoadingMore }"/>
            </button>
        </div>
    </div>
</template>
