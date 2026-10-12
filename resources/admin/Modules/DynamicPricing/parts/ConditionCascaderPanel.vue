<script setup>
import {ref, useId} from "vue";
import TransitionAccordion from "@/Bits/Components/TransitionAccordion.vue";

/*
 * Accordion attribute picker: a group expands in place to reveal its
 * attributes, and a group nested in it (Checkout Fields → Billing Address)
 * renders as a panel of its own. Emits the attribute key on its own — a stored
 * condition is a flat `{key, operator, value}` and never records which group
 * it came from.
 */
defineProps({
    options: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['select']);

const openGroup = ref(null);

/*
 * Every group renders its own popover, so this panel can be on the page more
 * than once — namespace the aria-controls ids per instance to keep them unique.
 */
const panelId = useId();

const groupPanelId = (value) => `${panelId}-${value}`;

const toggleGroup = (value) => {
    openGroup.value = openGroup.value === value ? null : value;
};

const selectAttribute = (key) => {
    emit('select', key);
    openGroup.value = null;
};
</script>

<template>
    <div class="fct-cascader-panel">
        <div
            v-for="option in options"
            :key="option.value"
            class="fct-cascader-option"
        >
            <!--
                Native buttons: below 768px this panel replaces the element-plus
                cascader, so it has to be reachable without a pointer.
            -->
            <button
                type="button"
                class="fct-cascader-button"
                :aria-expanded="openGroup === option.value"
                :aria-controls="groupPanelId(option.value)"
                @click="toggleGroup(option.value)"
            >
                {{ option.label }}
            </button>

            <TransitionAccordion :visible="openGroup === option.value">
                <ul :id="groupPanelId(option.value)" class="fct-cascader-content">
                    <li v-for="child in option.children" :key="child.value">
                        <ConditionCascaderPanel
                            v-if="Array.isArray(child.children)"
                            :options="[child]"
                            @select="selectAttribute"
                        />
                        <button
                            v-else
                            type="button"
                            class="fct-cascader-item"
                            @click="selectAttribute(child.value)"
                        >
                            {{ child.label }}
                        </button>
                    </li>
                </ul>
            </TransitionAccordion>
        </div>
    </div>
</template>
