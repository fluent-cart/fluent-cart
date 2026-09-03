<script setup>
import { ref, computed, watch, onBeforeUnmount, nextTick } from 'vue';
import { VueDraggableNext } from 'vue-draggable-next';
import translate from "@/utils/translator/Translator";
import GroupTermsPanel from "./GroupTermsPanel.vue";
import AddGroupModal from "./AddGroupModal.vue";
import EditGroupModal from "./EditGroupModal.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Confirmation from "@/utils/Confirmation";
import Notify from "@/utils/Notify";
import Rest from "@/utils/http/Rest";

const PER_PAGE = 10;

const selectedGroup = ref(null);
const createGroupModal = ref(false);
const addGroupModalRef = ref(null);
const editGroupModalRef = ref(null);
const editGroupModal = ref(false);
const editGroup = ref({});
const search = ref('');
const panelRef = ref(null);

const groups = ref([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const isLoading = ref(true);
const isLoadingMore = ref(false);

const hasMore = computed(() => page.value < lastPage.value);

// Holds the id of a group just created via the modal so the post-create reload
// doesn't snap the right panel away from it. New groups append to the END of the
// manual order (serial = max+1), so on installs with >1 page the fresh group
// lands on a later page and isn't in the page-1 reload — without this guard the
// list-reconcile below would reset selectedGroup to groups[0].
const justCreatedId = ref(null);

// Drag-reorder is only meaningful on the full, unfiltered, multi-item list.
// Disabled while searching (the visible set is a filtered subset, so reordering
// it would write a misleading global order) and when there's nothing to reorder.
const canReorder = computed(() => !search.value.trim() && groups.value.length > 1);

// Per-row search match — used with v-show so the dragged list stays bound to the
// full `groups` array (stable drag indices) while non-matches hide during search.
const matchesSearch = (group) => {
    if (!search.value.trim()) return true;
    return (group.title || '').toLowerCase().includes(search.value.toLowerCase());
};

// In-flight guard for the reorder POST. Dragging/keyboard-moving is blocked
// while a save is running so two quick reorders can't race — a slow earlier
// request landing after a later one would otherwise leave the server order out
// of sync with the UI. `pendingReorder` coalesces any move attempted during the
// save so the LATEST order is flushed once in flight clears.
const isSavingOrder = ref(false);
let pendingReorder = false;

// Persist the current order. vue-draggable-next (and moveGroup) have already
// mutated groups.value in place (optimistic), so we just POST the loaded order;
// on failure we reload to fall back to the server's stored order.
const persistGroupOrder = () => {
    if (isSavingOrder.value) {
        // A reorder happened while a save was in flight — remember to re-send the
        // newest order in the current save's finally() rather than firing now.
        pendingReorder = true;
        return;
    }
    const ids = groups.value.map(g => g.id);
    if (ids.length < 2) return;
    isSavingOrder.value = true;
    Rest.post('options/attr/groups/reorder', { ids })
        .then(response => {
            Notify.success(response.message || translate('Groups reordered'));
        })
        .catch(errors => {
            Notify.error(errors.data?.message || translate('Failed to reorder groups'));
            reloadGroups();
        })
        .finally(() => {
            isSavingOrder.value = false;
            if (pendingReorder) {
                pendingReorder = false;
                persistGroupOrder();
            }
        });
};

// Keyboard-accessible reorder: move a group up (-1) or down (+1) within the
// loaded list, then persist — the focusable handle button calls this on
// Arrow Up/Down so the feature isn't mouse-drag only. Focus follows the moved
// row so repeated presses keep working. Bounded to the loaded set, same as drag.
const moveGroup = (group, direction) => {
    // No isSavingOrder guard: keyboard moves stay responsive during an in-flight
    // save — persistGroupOrder() coalesces them so the latest order wins without
    // racing (and the button stays focused so repeated presses keep working).
    if (!canReorder.value) return;
    const list = groups.value;
    const index = list.findIndex(g => g.id === group.id);
    const target = index + direction;
    if (index === -1 || target < 0 || target >= list.length) return;
    const swap = list[target];
    list[target] = list[index];
    list[index] = swap;
    persistGroupOrder();
    nextTick(() => {
        const el = document.querySelector('.fct-attr-group-handle[data-group-id="' + group.id + '"]');
        if (el) el.focus();
    });
};

// Monotonic request id — every fetch captures its own version, and the
// then/catch/finally blocks short-circuit when a newer fetch has started.
// Without this, a slow response for "co" can arrive after a fast response
// for "color" and clobber the visible results.
let fetchVersion = 0;

const fetchGroups = (p = 1, append = false) => {
    const myVersion = ++fetchVersion;
    if (append) {
        isLoadingMore.value = true;
    } else {
        isLoading.value = true;
    }
    const params = {
        per_page: PER_PAGE,
        page: p,
    };
    // Push the search term to the server so matches on unloaded pages are
    // included — client-side filtering only saw the loaded subset, which
    // caused "No results" for groups sitting on page 2+.
    if (search.value && search.value.trim()) {
        params.search = search.value.trim();
    }
    return Rest.get('options/attr/groups', params).then(res => {
        if (myVersion !== fetchVersion) return false; // stale response — discard
        const paginator = res.groups || {};
        const data = paginator.data || [];
        groups.value = append ? groups.value.concat(data) : data;
        total.value = Number(paginator.total || 0);
        lastPage.value = Number(paginator.last_page || 1);
        page.value = Number(paginator.current_page || p);

        // Reconcile selectedGroup with the fresh list. On an append-mode
        // load (Load More / infinite scroll) the currently-selected group
        // is always still present, so skip the check. On a replace-mode
        // load (initial fetch, search-triggered refresh, post-save reload)
        // drop the selection if the group is no longer in the result set,
        // otherwise the right panel would keep editing a now-hidden group.
        if (!append) {
            const stillVisible = selectedGroup.value
                && groups.value.some(g => g.id === selectedGroup.value.id);
            // A freshly-created group appends to the end of the order and may sit
            // on an unloaded page, so it won't be "visible" in this page-1 reload —
            // keep it selected anyway (the right panel renders from the object) so
            // the merchant stays on what they just created.
            const isJustCreated = selectedGroup.value
                && selectedGroup.value.id === justCreatedId.value;
            if (!stillVisible && !isJustCreated) {
                selectedGroup.value = groups.value.length ? groups.value[0] : null;
            }
            justCreatedId.value = null;
        }
        return true; // loaded successfully — reveal walk may continue
    }).catch(errors => {
        if (myVersion !== fetchVersion) return false; // stale response — discard
        Notify.error(errors.data?.message || translate('Failed to load attributes'));
        return false; // failed — reveal walk must stop, not keep paging
    }).finally(() => {
        if (myVersion !== fetchVersion) return; // newer fetch owns the loading flags
        isLoading.value = false;
        isLoadingMore.value = false;
    });
};

fetchGroups(1, false);

const loadMore = () => {
    if (!hasMore.value || isLoadingMore.value || isLoading.value) return;
    fetchGroups(page.value + 1, true);
};

// Infinite scroll on the sidebar group list — same gating as the right
// panel: loadMore() short-circuits while a fetch is in flight, so rapid
// scroll events don't queue duplicate pages.
const onGroupListScroll = (e) => {
    const el = e.target;
    if (!el) return;
    const remaining = el.scrollHeight - el.scrollTop - el.clientHeight;
    if (remaining < 120) loadMore();
};

const reloadGroups = () => {
    page.value = 1;
    fetchGroups(1, false);
};

// Re-query the backend when the search term settles. Debounced to avoid
// firing on every keystroke. Resets to page 1; the existing append-mode
// pagination then layers more search-scoped results on infinite scroll.
let searchDebounceId = null;
watch(search, () => {
    if (searchDebounceId) clearTimeout(searchDebounceId);
    searchDebounceId = setTimeout(() => {
        page.value = 1;
        fetchGroups(1, false);
    }, 300);
});

// Cancel the pending debounce on unmount so a delayed search fetch
// doesn't fire against a torn-down instance after the user navigates away.
onBeforeUnmount(() => {
    if (searchDebounceId) clearTimeout(searchDebounceId);
});

// Defensive client-side filter on top of the server query — covers race
// windows during the debounce and keeps the visible set tight if the
// backend ignores the search param.
const filteredGroups = computed(() => {
    if (!search.value.trim()) return groups.value;
    const q = search.value.toLowerCase();
    return groups.value.filter(g => (g.title || '').toLowerCase().includes(q));
});

// Varied widths so the skeletons don't look like a perfect grid.
const titleWidths = ['65%', '50%', '70%', '45%', '60%', '55%'];
const metaWidths  = ['80%', '70%', '85%', '65%', '75%', '90%'];
const groupTitleWidth = (i) => titleWidths[(i - 1) % titleWidths.length];
const groupMetaWidth  = (i) => metaWidths[(i - 1) % metaWidths.length];

const getTypeLabel = (group) => {
    const map = {
        options: translate('Options'),
        color:   translate('Color'),
        image:   translate('Image'),
    };
    return map[group.settings?.type || 'options'] || translate('Options');
};

const getStylingLabel = (group) => {
    const map = {
        dropdown: translate('Dropdown'),
        button:   translate('Button'),
    };
    return map[group.settings?.styling || 'button'] || translate('Button');
};

const groupAriaLabel = (group) => {
    /* translators: %1$s: attribute group title */
    return translate('Open attribute group: %1$s', group.title || translate('No Name'));
};

const handleEditGroup = (row) => {
    editGroup.value = row;
    editGroupModal.value = true;
};

const handleDeleteGroup = (row) => {
    if (row.used_terms_count > 0) {
        Notify.error(translate("Group's terms are already in use, please remove the terms from product first."));
        return;
    }
    Confirmation.ofDelete(
        translate('Are you sure you want to delete this group? This action is not recoverable.'),
        translate('Confirm Delete!'),
        { confirmButtonText: translate('Yes, Delete this group') }
    ).then(() => {
        Rest.delete('options/attr/group/' + row.id)
            .then(response => {
                Notify.success(response.message);
                if (selectedGroup.value?.id === row.id) {
                    selectedGroup.value = null;
                }
                reloadGroups();
            })
            .catch(errors => {
                Notify.error(errors.data?.message || translate('Delete failed'));
            });
    }).catch(() => {});
};

const groupCreatingDone = async (newGroup) => {
    createGroupModal.value = false;
    const newId = newGroup && newGroup.id ? newGroup.id : null;
    // Pre-seed selectedGroup with the freshly created group so the right pane
    // jumps straight to it instead of staying on whatever was selected before.
    if (newId) {
        selectedGroup.value = newGroup;
        justCreatedId.value = newId;
    }
    // Reload from page 1, then page forward until the new group is in the loaded
    // set. New groups append to the END of the serial order, so on installs with
    // more than one page the fresh group sits on a later page — without walking
    // to it the sidebar shows no highlighted row and can't scroll to it. Bounded
    // by hasMore (stops at the last page) plus a hard guard. Pages load
    // sequentially so the intermediate rows fill in with no gaps or duplicates.
    if (!(await fetchGroups(1, false))) return; // reload failed/stale — nothing to walk to
    let guard = 0;
    while (newId && !groups.value.some(g => g.id === newId) && hasMore.value && guard < 50) {
        guard++;
        if (!(await fetchGroups(page.value + 1, true))) return; // stop on failure/stale
    }
    // Scroll the sidebar so the freshly-selected row is visible. scrollActive…
    // polls a few frames for the .is-active row to appear before scrolling.
    scrollActiveGroupIntoView();
};

const scrollActiveGroupIntoView = (attempts = 10) => {
    nextTick(() => {
        // Groups now render in the merchant's manual serial order and new groups
        // append to the END, so a scroll-to-top no longer frames the fresh group.
        // Scroll the active row into view if it's in the loaded set; when the new
        // group sits on an unloaded page it isn't in the DOM yet — the right panel
        // still shows it, and it'll appear in the sidebar on Load More.
        const list = document.querySelector('.fct-attr-group-list');
        if (list) {
            const active = list.querySelector('.fct-attr-group-item.is-active');
            if (active) {
                active.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
            return;
        }
        if (attempts > 0) {
            // List wasn't in the DOM yet (e.g. the dialog @opened fired
            // before the sidebar's v-if branch mounted). Retry next
            // frame.
            requestAnimationFrame(() => scrollActiveGroupIntoView(attempts - 1));
        }
    });
};

const groupEditingDone = () => {
    editGroup.value = {};
    editGroupModal.value = false;
    // No reloadGroups() call here — the modal already mutated
    // props.group.settings in place, and `selectedGroup` is the
    // same reference as the row in `groups.value`, so the right
    // panel reflects the edit immediately. A reload would reset
    // page.value = 1 and the still-selected group, if it lived on
    // a later page, would fall outside the visible window — the
    // post-fetch reconcile then snaps `selectedGroup` to
    // groups[0], jumping the right panel away from what the user
    // was just editing. Add/delete paths still call reloadGroups()
    // because those genuinely change the result set.
};
</script>

<template>
    <div class="fct-attr-split-page fct-layout-width">
        <h1 class="fct-attr-split-page-title">{{ translate('Attributes & Terms') }}</h1>
        <div class="fct-attr-split-card">

            <!-- Left sidebar -->
            <div class="fct-attr-split-left">
                <div class="fct-attr-split-left-header">
                    <div class="fct-attr-split-title">
                        <span>{{ translate('Attributes') }}</span>
                        <span class="fct-attr-split-count">{{ groups.length }}</span>
                    </div>
                    <button
                        class="fct-attr-icon-btn"
                        :aria-label="translate('Add attribute group')"
                        @click="createGroupModal = true"
                    >
                        <DynamicIcon name="Plus"/>
                    </button>
                </div>

                <div class="fct-attr-split-search">
                    <el-input
                        v-model="search"
                        :placeholder="translate('Search attributes')"
                        size="small"
                        clearable
                    >
                        <template #prefix>
                            <DynamicIcon name="Search"/>
                        </template>
                    </el-input>
                </div>

                <div class="fct-attr-group-list" @scroll.passive="onGroupListScroll">
                  <VueDraggableNext
                      :list="groups"
                      handle=".fct-attr-group-handle"
                      :disabled="!canReorder || isLoading || isLoadingMore || isSavingOrder"
                      :animation="150"
                      ghost-class="fct-attr-group-item--ghost"
                      tag="div"
                      class="fct-attr-group-list-inner"
                      @end="persistGroupOrder"
                  >
                    <div
                        v-for="group in groups"
                        v-show="matchesSearch(group)"
                        :key="group.id"
                        class="fct-attr-group-item"
                        :class="{ 'is-active': selectedGroup?.id === group.id }"
                        role="button"
                        tabindex="0"
                        :aria-pressed="selectedGroup?.id === group.id"
                        :aria-label="groupAriaLabel(group)"
                        @click="selectedGroup = group"
                        @keydown.enter.prevent="selectedGroup = group"
                        @keydown.space.prevent="selectedGroup = group"
                    >
                        <!-- translators: %1$s: attribute group title -->
                        <button
                            v-if="canReorder"
                            type="button"
                            class="fct-attr-group-handle"
                            :data-group-id="group.id"
                            :aria-label="translate('Reorder %1$s — drag, or use arrow up and down keys', group.title || translate('No Name'))"
                            @click.stop
                            @mousedown.stop
                            @keydown.up.prevent.stop="moveGroup(group, -1)"
                            @keydown.down.prevent.stop="moveGroup(group, 1)"
                        >
                            <DynamicIcon name="ReorderDotsVertical"/>
                        </button>
                        <div class="fct-attr-group-item-body">
                            <span class="fct-attr-group-item-name">{{ group.title || translate('No Name') }}</span>
                            <span class="fct-attr-group-item-meta">
                                {{ getTypeLabel(group) }}
                                &middot;
                                {{ getStylingLabel(group) }}
                                &middot;
                                {{ group.terms_count || 0 }} {{ translate('terms') }}
                            </span>
                        </div>
                        <div class="fct-attr-group-item-actions" @click.stop>
                            <button
                                class="fct-attr-icon-btn fct-attr-group-action"
                                :aria-label="translate('Edit group')"
                                @click="handleEditGroup(group)"
                            >
                                <DynamicIcon name="Edit"/>
                            </button>
                            <button
                                class="fct-attr-icon-btn fct-attr-group-action fct-attr-group-action--delete"
                                :aria-label="translate('Delete group')"
                                @click="handleDeleteGroup(group)"
                            >
                                <DynamicIcon name="Delete"/>
                            </button>
                        </div>
                        <DynamicIcon
                            v-if="selectedGroup?.id === group.id"
                            name="ChevronRight"
                            class="fct-attr-group-chevron"
                        />
                    </div>
                  </VueDraggableNext>

                    <!-- Hold off on the definitive "No results" state while a
                         search is mid-flight (debounce window + in-flight fetch).
                         Otherwise typing flashes "No results" between keystrokes. -->
                    <div v-if="!isLoading && !isLoadingMore && !filteredGroups.length" class="fct-attr-group-empty">
                        {{ search ? translate('No results found') : translate('No attributes yet') }}
                    </div>

                    <div v-if="isLoading" class="fct-attr-group-loading">
                        <div
                            v-for="i in 6"
                            :key="i"
                            class="fct-attr-group-skeleton"
                        >
                            <span class="fct-skel-block fct-skel-group-title" :style="{ width: groupTitleWidth(i) }"/>
                            <span class="fct-skel-block fct-skel-group-meta" :style="{ width: groupMetaWidth(i) }"/>
                        </div>
                    </div>
                </div>

                <div class="fct-attr-split-left-footer">
                    <button
                        class="fct-attr-add-attr-btn"
                        :disabled="!hasMore || isLoadingMore"
                        @click="loadMore"
                    >
                        <DynamicIcon name="Reload" :class="{ 'fct-spin': isLoadingMore }"/>
                        <template v-if="isLoadingMore">{{ translate('Loading...') }}</template>
                        <template v-else-if="hasMore">
                            {{ translate('Load More') }}
                            <span class="fct-attr-load-more-count">({{ groups.length }}/{{ total }})</span>
                        </template>
                        <template v-else>{{ translate('All loaded') }} ({{ total }})</template>
                    </button>
                </div>
            </div>

            <!-- Right panel -->
            <div class="fct-attr-split-right">
                <GroupTermsPanel
                    v-if="selectedGroup"
                    ref="panelRef"
                    :key="selectedGroup.id"
                    :group="selectedGroup"
                    @edit-group="handleEditGroup(selectedGroup)"
                />
                <!-- While the initial groups fetch is in flight there's no
                     selected group yet — show a skeleton so the right panel
                     doesn't briefly flash the empty state. -->
                <div v-else-if="isLoading" class="fct-attr-panel-skeleton">
                    <div class="fct-attr-panel-skeleton-header">
                        <div class="fct-skel-block fct-skel-breadcrumb"/>
                        <div class="fct-attr-panel-skeleton-chips">
                            <span class="fct-skel-block fct-skel-chip"/>
                            <span class="fct-skel-block fct-skel-chip"/>
                            <span class="fct-skel-block fct-skel-chip"/>
                        </div>
                    </div>
                    <div class="fct-attr-panel-skeleton-body">
                        <div
                            v-for="i in 6"
                            :key="i"
                            class="fct-term-row-item fct-term-row-item--skeleton"
                        >
                            <span class="fct-skel-block fct-skel-handle"/>
                            <span class="fct-skel-block fct-skel-dot"/>
                            <span class="fct-skel-block fct-skel-title" :style="{ width: groupTitleWidth(i) }"/>
                            <span class="fct-skel-block fct-skel-action"/>
                        </div>
                    </div>
                </div>
                <div v-else class="fct-attr-split-empty">
                    <DynamicIcon name="Empty/ListView"/>
                    <p>{{ translate('Select an attribute to manage its terms') }}</p>
                </div>
            </div>

        </div>

        <el-dialog
            :append-to-body="true"
            width="50%"
            v-model="createGroupModal"
            :title="translate('Add new attribute group')"
            @opened="addGroupModalRef?.focusTitle?.()"
        >
            <template v-if="createGroupModal">
                <AddGroupModal ref="addGroupModalRef" @when-group-create-is-done="groupCreatingDone"/>
            </template>
        </el-dialog>

        <el-dialog
            :append-to-body="true"
            width="50%"
            v-model="editGroupModal"
            :title="translate('Edit attribute group')"
            @opened="editGroupModalRef?.focusTitle?.()"
        >
            <template v-if="editGroupModal">
                <EditGroupModal
                    ref="editGroupModalRef"
                    :group="editGroup"
                    :group-id="editGroup.id"
                    @when-group-edit-is-done="groupEditingDone"
                />
            </template>
        </el-dialog>
    </div>
</template>
