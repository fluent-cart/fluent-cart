<script setup>
import { computed, nextTick, ref, watch } from "vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
    classes:    { type: Array,            default: () => [] },
    activeId:   { type: [Number, String], default: null },
    maxClasses: { type: Number,           default: 6 },
    creating:   { type: Boolean,          default: false },
    search:     { type: String,           default: '' },
});

const emit = defineEmits(['switch', 'delete', 'add', 'create-custom', 'update:search']);

const inlineCreating = ref(false);
const newClassName = ref('');
const inlineInput = ref(null);
const inlineError = ref(false);

const sortedClasses = computed(() => {
    return [...props.classes].sort((a, b) => {
        if (a.slug === 'standard' && b.slug !== 'standard') {
            return -1;
        }

        if (a.slug !== 'standard' && b.slug === 'standard') {
            return 1;
        }

        return 0;
    });
});

const remainingPredefined = computed(() => {
    return ['reduced', 'zero'].filter(slug => !props.classes.find(c => c.slug === slug));
});

const startInlineCreate = () => {
    newClassName.value = '';
    inlineError.value = false;
    inlineCreating.value = true;
    nextTick(() => inlineInput.value?.focus());
};

const handleCommand = (command) => {
    if (command === 'custom') {
        startInlineCreate();
        return;
    }

    emit('add', command);
};

const confirmInlineCreate = () => {
    const title = newClassName.value.trim();

    if (!title) {
        cancelInlineCreate();
        return;
    }

    emit('create-custom', title);
};

const cancelInlineCreate = () => {
    inlineCreating.value = false;
    newClassName.value = '';
    inlineError.value = false;
};

const handleInlineBlur = () => {
    if (!props.creating && !newClassName.value.trim()) {
        cancelInlineCreate();
    }
};

// Class list grows when the parent finishes creating — close the inline input.
// On failure the list is unchanged while `creating` drops back to false, so
// the typed name stays for a retry and the input is flagged red. Both sources
// share one watcher so the success check always runs before the error check.
watch([() => props.classes.length, () => props.creating], ([newLength, creating], [oldLength, wasCreating]) => {
    if (!inlineCreating.value) {
        return;
    }

    if (newLength > oldLength) {
        cancelInlineCreate();
        return;
    }

    if (wasCreating && !creating) {
        inlineError.value = true;
    }
});

// Typing again clears the error state.
watch(newClassName, () => {
    inlineError.value = false;
});
</script>

<template>
    <div class="fct-tax-class-tabs">
        <div class="fct-tax-class-tabs__list">
            <button
                v-for="tc in sortedClasses"
                :key="tc.id"
                :class="['fct-tax-class-tabs__tab', { 'fct-tax-class-tabs__tab--active': activeId == tc.id }]"
                @click="emit('switch', tc)"
            >
                {{ tc.title }}
                <span
                    v-if="tc.slug !== 'standard'"
                    class="fct-tax-class-tabs__delete"
                    :title="translate('Delete tax class')"
                    @click.stop="emit('delete', tc)"
                >
                    <DynamicIcon name="Close" />
                </span>
            </button>

            <template v-if="classes.length < maxClasses">
                <div
                    v-if="inlineCreating"
                    :class="['fct-tax-class-tabs__inline-create', { 'fct-tax-class-tabs__inline-create--error': inlineError }]"
                >
                    <el-input
                        ref="inlineInput"
                        v-model="newClassName"
                        size="small"
                        maxlength="30"
                        :disabled="creating"
                        :placeholder="translate('e.g. Luxury')"
                        @keyup.enter="confirmInlineCreate"
                        @keyup.esc="cancelInlineCreate"
                        @blur="handleInlineBlur"
                    />
                    <button
                        class="fct-tax-class-tabs__inline-confirm"
                        :disabled="creating || !newClassName.trim()"
                        :title="translate('Create tax class')"
                        @mousedown.prevent
                        @click="confirmInlineCreate"
                    >
                        <DynamicIcon name="Check" />
                    </button>
                    <button
                        class="fct-tax-class-tabs__inline-cancel"
                        :disabled="creating"
                        :title="translate('Cancel')"
                        @mousedown.prevent
                        @click="cancelInlineCreate"
                    >
                        <DynamicIcon name="Close" />
                    </button>
                </div>

                <el-dropdown
                    v-else-if="remainingPredefined.length"
                    trigger="click"
                    :disabled="creating"
                    @command="handleCommand"
                    popper-class="fct-dropdown"
                >
                    <button class="fct-tax-class-tabs__add" :disabled="creating">
                        <DynamicIcon name="Plus" />
                    </button>

                    <template #dropdown>
                        <el-dropdown-menu>
                            <el-dropdown-item
                                v-if="remainingPredefined.includes('reduced')"
                                command="reduced"
                            >{{ translate('Reduced') }}</el-dropdown-item>
                            <el-dropdown-item
                                v-if="remainingPredefined.includes('zero')"
                                command="zero"
                            >{{ translate('Zero') }}</el-dropdown-item>
                            <el-dropdown-item command="custom">{{ translate('Custom Class...') }}</el-dropdown-item>
                        </el-dropdown-menu>
                    </template>
                </el-dropdown>

                <button
                    v-else
                    class="fct-tax-class-tabs__add"
                    :disabled="creating"
                    :title="translate('Add custom tax class')"
                    @click="startInlineCreate"
                >
                    <DynamicIcon name="Plus" />
                </button>
            </template>
        </div>

        <div class="fct-tax-class-tabs__search">
            <el-input
                :model-value="search"
                :placeholder="translate('Search country or region…')"
                size="small"
                clearable
                @update:model-value="emit('update:search', $event)"
                @clear="emit('update:search', '')"
            >
                <template #prefix>
                    <DynamicIcon name="Search" class="w-3.5 h-3.5 text-system-light dark:text-gray-400" />
                </template>
            </el-input>
            <slot name="search-actions" />
        </div>
    </div>
</template>
