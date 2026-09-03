<script setup>
import {ref, computed, watch, nextTick, onMounted, onBeforeUnmount} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import Asset from "@/utils/support/Asset";
import Animation from "@/Bits/Components/Animation.vue";

const getPlaceholderImage = () => Asset.getUrl('images/placeholder.svg');

const props = defineProps({
    items: Array,
    // Optional grouped shape: [{termId, title, count, items: [navItem]}].
    // When provided (and non-empty), the list renders collapsible group headers
    // instead of the flat items list. Mirrors the AdvancedVariationGroupedTable
    // pattern from the main page so the modal feels consistent with the table.
    groupedItems: {type: Array, default: null},
    activeKey: [String, Number],
    isDirty: Boolean,
    isSaving: Boolean,
    drawerMode: String,
    product: Object,
    productEditModel: Object,
    canCopyDirectCheckout: Function,
});

const isButtonDisabled = (item) => {
    const isDirtyAndNotActive = props.isDirty && props.activeKey !== item.key;
    const isCreateModeAndNotDraft = props.drawerMode === 'create' && item.key !== 'draft-variation';
    return isDirtyAndNotActive || isCreateModeAndNotDraft;
};

const emit = defineEmits(['select', 'command', 'save', 'edit-group']);

// Only switch to grouped rendering when we actually got groups — keeps the
// component backward-compatible with consumers that still pass flat items.
const isGrouped = computed(() => Array.isArray(props.groupedItems) && props.groupedItems.length > 0);

// Accordion: exactly one group is expanded at a time — the group holding the
// active variant — and the rest stay collapsed. The right-hand panel already
// shows the selected variant's detail, so there's no need to keep every group
// open. Holds the termId (as a string) of the open group, or null when all are
// collapsed.
const expandedGroupKey = ref(null);

// termId (string) of the group containing the given variant key, or null.
const groupKeyForActive = (groups, key) => {
    for (const g of (groups || [])) {
        if ((g.items || []).some(i => i.key === key)) {
            return String(g.termId);
        }
    }
    return null;
};

// On (re)group, open the active variant's group, falling back to the first
// group so the nav never opens fully collapsed.
watch(() => props.groupedItems, (groups) => {
    if (!Array.isArray(groups) || !groups.length) {
        expandedGroupKey.value = null;
        return;
    }
    expandedGroupKey.value = groupKeyForActive(groups, props.activeKey) || String(groups[0].termId);
}, { immediate: true });

// Selecting a variant in another group (prev/next arrows, click) expands that
// group and collapses the rest so the active row is never hidden.
watch(() => props.activeKey, (key) => {
    if (!isGrouped.value || key == null) return;
    const k = groupKeyForActive(props.groupedItems, key);
    if (k) expandedGroupKey.value = k;
});

const isExpanded = (group) => String(group.termId) === expandedGroupKey.value;
const toggleGroup = (group) => {
    const k = String(group.termId);
    // Accordion toggle: re-clicking the open header collapses it; clicking a
    // collapsed header opens it and closes whichever was open.
    expandedGroupKey.value = expandedGroupKey.value === k ? null : k;
};

// Scroll-into-view for the active variant. When the drawer opens from a row's
// edit icon (or on prev/next nav) the active variant can sit far down a long
// list — bring it into view so the merchant sees which one they're editing.
const listRef = ref(null);
let scrollTimer = null;

const scrollActiveIntoView = () => {
    // Wait out the accordion expand animation (220ms) so the active row has its
    // final offset before scrolling. block:'center' parks the active row in the
    // middle of the list with context above and below (rather than flush against
    // an edge); it no-ops near the top/bottom where the list can't scroll
    // further. Guard the timer so teardown can't fire scroll on a detached node.
    if (scrollTimer) clearTimeout(scrollTimer);
    nextTick(() => {
        scrollTimer = setTimeout(() => {
            scrollTimer = null;
            const el = listRef.value?.querySelector('.fct-shared-variant-nav__item-shell.is-active');
            if (el) el.scrollIntoView({block: 'center'});
        }, 240);
    });
};

// Drawer just mounted with a preselected variant — scroll to it once the list
// (and the active group's accordion) has rendered.
onMounted(scrollActiveIntoView);

// Active variant changed (click in another group, prev/next arrows). The
// activeKey watcher above expands the owning group first; scroll after it.
watch(() => props.activeKey, () => scrollActiveIntoView());

onBeforeUnmount(() => {
    if (scrollTimer) clearTimeout(scrollTimer);
});
</script>

<template>
    <div ref="listRef" class="fct-shared-variant-nav__list">
        <!-- Grouped mode — collapsible group headers with variant count, then
             the same per-item shell rendered inside each expanded group. -->
        <template v-if="isGrouped">
            <div
                v-for="group in groupedItems"
                :key="'g-' + group.termId"
                class="fct-shared-variant-nav__group"
                :class="{ 'is-collapsed': !isExpanded(group) }"
            >
                <div class="fct-shared-variant-nav__group-header">
                    <button
                        type="button"
                        class="fct-shared-variant-nav__group-toggle"
                        :aria-expanded="isExpanded(group) ? 'true' : 'false'"
                        @click="toggleGroup(group)"
                    >
                        <span class="fct-shared-variant-nav__group-caret">
                            <DynamicIcon :name="isExpanded(group) ? 'CaretDown' : 'CaretRight'"/>
                        </span>
                        <span class="fct-shared-variant-nav__group-title">{{ group.title }}</span>
                        <span class="fct-shared-variant-nav__group-count">
                            {{
                              /* translators: %1$s: number of variants in the attribute group */
                              translate('%1$s variants', group.count)
                            }}
                        </span>
                    </button>
                    <button
                        type="button"
                        class="fct-shared-variant-nav__group-edit"
                        :aria-label="translate('Edit group')"
                        @click.stop="emit('edit-group', group)"
                    >
                        <DynamicIcon name="Edit"/>
                    </button>
                </div>

                <Animation :visible="isExpanded(group)" accordion :duration="220">
                <div class="fct-shared-variant-nav__group-body">
                    <div
                        v-for="item in group.items"
                        :key="item.key"
                        class="fct-shared-variant-nav__item-shell"
                        :class="{ 'is-active': activeKey === item.key, 'is-draft': item.isDraft }"
                    >
                        <button
                            type="button"
                            class="fct-shared-variant-nav__item"
                            :class="{ 'is-disabled': isButtonDisabled(item) }"
                            :disabled="isButtonDisabled(item)"
                            :aria-current="activeKey === item.key ? 'true' : undefined"
                            @click="!isButtonDisabled(item) && emit('select', item)"
                        >
                            <div class="fct-shared-variant-nav__item-image-wrapper">
                                <div class="fct-shared-variant-nav__item-image">
                                    <img
                                        v-if="item.variant?.media?.[0]?.url"
                                        :src="item.variant?.media?.[0]?.url"
                                        :alt="item.label"
                                    />
                                    <img
                                        v-else
                                        class="fct-shared-variant-placeholder"
                                        :src="getPlaceholderImage()"
                                        :alt="item.label"
                                    />
                                </div>
                                <span v-if="activeKey === item.key" class="fct-shared-variant-nav__item-dot"></span>
                            </div>
                            <div class="fct-shared-variant-nav__item-content">
                                <span class="fct-shared-variant-nav__item-title">
                                    {{ item.label }}
                                    <span v-if="item.variant?.item_status === 'inactive' && product.detail?.variation_type === 'advanced_variations'" class="fct-variant-badge fct-variant-badge-inactive">
                                        {{ translate('Inactive') }}
                                    </span>
                                </span>
                                <span class="fct-shared-variant-nav__item-meta">
                                    {{ item.meta }}
                                </span>
                            </div>
                        </button>

                        <button
                            type="button"
                            v-if="isDirty && activeKey === item.key"
                            class="fct-shared-variant-check-icon"
                            :class="{ 'is-saving': isSaving }"
                            :disabled="isSaving"
                            @click="emit('save')"
                            :aria-label="translate('Save variant')"
                        >
                            <DynamicIcon v-if="!isSaving" name="Save" />
                            <DynamicIcon v-else name="Loading" class="fct-rotation-icon"/>
                        </button>

                        <el-dropdown
                            v-if="!item.isDraft"
                            trigger="click"
                            placement="bottom-end"
                            popper-class="fct-dropdown"
                            class="fct-shared-variant-nav__menu fct-more-option-wrap"
                            :disabled="isButtonDisabled(item)"
                            @command="command => !isButtonDisabled(item) && emit('command', command, item)"
                        >
                            <button
                                type="button"
                                class="fct-shared-variant-nav__menu-trigger"
                                :class="{ 'is-disabled': isButtonDisabled(item) }"
                                :disabled="isButtonDisabled(item)"
                                @click.stop
                                :aria-label="translate('Variation actions')"
                            >
                                <span class="more-btn">
                                    <DynamicIcon name="More"/>
                                </span>
                            </button>
                            <template #dropdown>
                                <el-dropdown-menu>
                                    <el-dropdown-item
                                        command="duplicate"
                                        v-if="!['simple', 'advanced_variations'].includes(product.detail?.variation_type)"
                                    >
                                        <DynamicIcon name="Duplicate"/>
                                        {{ translate('Duplicate') }}
                                    </el-dropdown-item>
                                    <el-dropdown-item command="copy_variation_id">
                                        <DynamicIcon name="Copy"/>
                                        {{ translate('Copy Variation ID') }}
                                    </el-dropdown-item>
                                    <el-dropdown-item
                                        command="copy_direct_checkout"
                                        :disabled="!canCopyDirectCheckout(item)"
                                    >
                                        <DynamicIcon name="Copy"/>
                                        {{ translate('Direct Checkout') }}
                                    </el-dropdown-item>
                                    <!-- Advanced variations manage their combination set via the
                                         attribute config, so the per-row action is an active/inactive
                                         toggle (no delete). Simple / variable variations keep delete. -->
                                    <el-dropdown-item
                                        command="toggle_status"
                                        v-if="item.variant?.id && product.detail?.variation_type === 'advanced_variations'"
                                        :class="{ 'item-destructive': item.variant?.item_status !== 'inactive' }"
                                    >
                                        <DynamicIcon :name="item.variant?.item_status === 'inactive' ? 'CheckCircle' : 'InActive'"/>
                                        {{ item.variant?.item_status === 'inactive' ? translate('Set Active') : translate('Set Inactive') }}
                                    </el-dropdown-item>
                                    <el-dropdown-item
                                        command="delete"
                                        class="item-destructive"
                                        v-if="product.detail?.variation_type !== 'advanced_variations' && productEditModel.variantsLength() > 1"
                                    >
                                        <DynamicIcon name="Delete"/>
                                        {{ translate('Delete') }}
                                    </el-dropdown-item>
                                </el-dropdown-menu>
                            </template>
                        </el-dropdown>
                    </div>
                </div>
                </Animation>
            </div>
        </template>

        <!-- Flat mode (default for simple variation + any consumer that doesn't
             pass groupedItems). Unchanged from the original render. -->
        <template v-else>
            <div
                v-for="item in items"
                :key="item.key"
                class="fct-shared-variant-nav__item-shell"
                :class="{ 'is-active': activeKey === item.key, 'is-draft': item.isDraft }"
            >
                <button
                    type="button"
                    class="fct-shared-variant-nav__item"
                    :class="{ 'is-disabled': isButtonDisabled(item) }"
                    :disabled="isButtonDisabled(item)"
                    :aria-current="activeKey === item.key ? 'true' : undefined"
                    @click="!isButtonDisabled(item) && emit('select', item)"
                >
                    <div class="fct-shared-variant-nav__item-image-wrapper">
                        <div class="fct-shared-variant-nav__item-image">
                            <img
                                v-if="item.variant?.media?.[0]?.url"
                                :src="item.variant?.media?.[0]?.url"
                                :alt="item.label"
                            />

                            <img
                                v-else
                                class="fct-shared-variant-placeholder"
                                :src="getPlaceholderImage()"
                                :alt="item.label"
                            />
                        </div>
                        <span v-if="activeKey === item.key" class="fct-shared-variant-nav__item-dot"></span>
                    </div>
                    <div class="fct-shared-variant-nav__item-content">
                        <span class="fct-shared-variant-nav__item-title">
                            {{ item.label }}
                            <span v-if="item.variant?.item_status === 'inactive' && product.detail?.variation_type === 'advanced_variations'" class="fct-variant-badge fct-variant-badge-inactive">
                                {{ translate('Inactive') }}
                            </span>
                        </span>
                        <span class="fct-shared-variant-nav__item-meta">
                            {{ item.meta }}
                        </span>
                    </div>
                </button>

                <!-- Show check icon when active variant has unsaved changes -->
                <button
                    type="button"
                    v-if="isDirty && activeKey === item.key"
                    class="fct-shared-variant-check-icon"
                    :class="{ 'is-saving': isSaving }"
                    :disabled="isSaving"
                    @click="emit('save')"
                    :aria-label="translate('Save variant')"
                >
                    <DynamicIcon v-if="!isSaving" name="Save" />

                    <DynamicIcon v-else name="Loading" class="fct-rotation-icon"/>
                </button>

                <el-dropdown
                    v-if="!item.isDraft"
                    trigger="click"
                    placement="bottom-end"
                    popper-class="fct-dropdown"
                    class="fct-shared-variant-nav__menu fct-more-option-wrap"
                    :disabled="isButtonDisabled(item)"
                    @command="command => !isButtonDisabled(item) && emit('command', command, item)"
                >
                    <button
                        type="button"
                        class="fct-shared-variant-nav__menu-trigger"
                        :class="{ 'is-disabled': isButtonDisabled(item) }"
                        :disabled="isButtonDisabled(item)"
                        @click.stop
                        :aria-label="translate('Variation actions')"
                    >
                        <span class="more-btn">
                            <DynamicIcon name="More"/>
                        </span>
                    </button>
                    <template #dropdown>
                        <el-dropdown-menu>
                            <el-dropdown-item
                                command="duplicate"
                                v-if="!['simple', 'advanced_variations'].includes(product.detail?.variation_type)"
                            >
                                <DynamicIcon name="Duplicate"/>
                                {{ translate('Duplicate') }}
                            </el-dropdown-item>
                            <el-dropdown-item command="copy_variation_id">
                                <DynamicIcon name="Copy"/>
                                {{ translate('Copy Variation ID') }}
                            </el-dropdown-item>
                            <el-dropdown-item
                                command="copy_direct_checkout"
                                :disabled="!canCopyDirectCheckout(item)"
                            >
                                <DynamicIcon name="Copy"/>
                                {{ translate('Direct Checkout') }}
                            </el-dropdown-item>
                            <!-- Advanced variations manage their combination set via the
                                 attribute config, so the per-row action is an active/inactive
                                 toggle (no delete). Simple / variable variations keep delete. -->
                            <el-dropdown-item
                                command="toggle_status"
                                v-if="item.variant?.id && product.detail?.variation_type === 'advanced_variations'"
                                :class="{ 'item-destructive': item.variant?.item_status !== 'inactive' }"
                            >
                                <DynamicIcon :name="item.variant?.item_status === 'inactive' ? 'CheckCircle' : 'InActive'"/>
                                {{ item.variant?.item_status === 'inactive' ? translate('Set Active') : translate('Set Inactive') }}
                            </el-dropdown-item>
                            <el-dropdown-item
                                command="delete"
                                class="item-destructive"
                                v-if="product.detail?.variation_type !== 'advanced_variations' && productEditModel.variantsLength() > 1"
                            >
                                <DynamicIcon name="Delete"/>
                                {{ translate('Delete') }}
                            </el-dropdown-item>
                        </el-dropdown-menu>
                    </template>
                </el-dropdown>
            </div>
        </template>
    </div>
</template>
