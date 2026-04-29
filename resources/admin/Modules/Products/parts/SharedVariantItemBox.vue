<script setup>
</script>

<template>
    <div class="fct-shared-variant-item-box">
        <div v-if="$slots.label || $slots.action" class="fct-shared-variant-item-box__header">
            <div class="fct-shared-variant-item-box__label">
                <slot name="label"/>
            </div>
            <div class="fct-shared-variant-item-box__action">
                <slot name="action"/>
            </div>
        </div>

        <div class="fct-shared-variant-item-box__body">
            <slot/>
        </div>
    </div>
</template>
