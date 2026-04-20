<script setup>
const props = defineProps({
    items: Array,
    activeKey: [String, Number],
    isDirty: Boolean,
});

const emit = defineEmits(['select']);
</script>

<template>
    <div class="fct-shared-variant-nav-mobile">
        <button
            v-for="item in items"
            :key="`mobile-${item.key}`"
            type="button"
            class="fct-shared-variant-nav-mobile__item"
            :class="{ 'is-active': activeKey === item.key, 'is-draft': item.isDraft }"
            :disabled="isDirty && activeKey !== item.key"
            @click="emit('select', item)"
        >
            {{ item.label }}
        </button>
    </div>
</template>
