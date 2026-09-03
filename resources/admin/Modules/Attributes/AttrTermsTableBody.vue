<script setup>
import { computed } from 'vue';
import { VueDraggableNext } from 'vue-draggable-next';
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
    group: {
        type: Object,
        default: () => ({}),
    },
    editingTermId: {
        type: [String, Number],
        default: null,
    },
    // When true (more pages haven't been loaded yet), drag-reorder is
    // disabled. Reordering with only a partial list would post a
    // truncated id sequence and corrupt the persisted order.
    disableReorder: {
        type: Boolean,
        default: false,
    },
});

const groupType = computed(() => {
    const settings = props.group && props.group.settings;
    return (settings && settings.type) ? settings.type : 'options';
});

const emit = defineEmits(['edit', 'delete', 'reorder']);

const onDragEnd = () => {
    emit('reorder', props.rows.map(r => r.id));
};

const isEditing = (row) => {
    const id = props.editingTermId;
    if (id === null || id === undefined) return false;
    return String(row.id) === String(id);
};
</script>

<template>
    <div class="fct-term-rows-list">
        <div v-if="!rows.length" class="fct-term-rows-empty">
            {{ translate('No terms yet — click + Add Terms below to create one.') }}
        </div>

        <VueDraggableNext
            v-else
            :list="rows"
            handle=".fct-term-row-handle"
            :animation="150"
            ghost-class="fct-term-row--ghost"
            :disabled="disableReorder"
            tag="div"
            class="fct-term-rows-inner"
            @end="onDragEnd"
        >
            <template v-for="row in rows" :key="row.id">

                <!-- Edit-in-place: parent renders the form via slot -->
                <div v-if="isEditing(row)" class="fct-term-edit-slot">
                    <slot name="edit-form" :term="row"/>
                </div>

                <!-- Default: pill with hover edit + delete -->
                <div v-else class="fct-term-row-item">
                    <span
                        class="fct-term-row-handle"
                        :class="{ 'is-disabled': disableReorder }"
                        :aria-label="disableReorder
                            ? translate('Load all terms before reordering')
                            : translate('Drag to reorder')"
                        :aria-disabled="disableReorder ? 'true' : null"
                        :title="disableReorder ? translate('Load all terms before reordering') : null"
                    >
                        <DynamicIcon name="ReorderDotsVertical"/>
                    </span>

                    <span
                        v-if="groupType === 'color' && row.settings && row.settings.color"
                        class="fct-term-row-color-dot"
                        :style="{ background: row.settings.color }"
                    />
                    <img
                        v-else-if="groupType === 'image' && row.settings && row.settings.image"
                        :src="row.settings.image"
                        :alt="row.title"
                        class="fct-term-row-img"
                    />

                    <span class="fct-term-row-name">{{ row.title || translate('No Name') }}</span>

                    <div class="fct-term-row-actions">
                        <button
                            type="button"
                            class="fct-term-row-edit"
                            :aria-label="translate('Edit')"
                            @click="emit('edit', row)"
                        >
                            <DynamicIcon name="Edit"/>
                        </button>
                        <button
                            type="button"
                            class="fct-term-row-delete"
                            :aria-label="translate('Delete')"
                            @click="emit('delete', row)"
                        >
                            <DynamicIcon name="Delete"/>
                        </button>
                    </div>
                </div>
            </template>
        </VueDraggableNext>
    </div>
</template>
