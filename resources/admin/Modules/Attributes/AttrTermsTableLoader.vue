<template>
    <div class="fct-term-rows-list fct-term-rows-loader">
        <div
            v-for="i in 6"
            :key="i"
            class="fct-term-row-item fct-term-row-item--skeleton"
        >
            <span class="fct-skel-block fct-skel-handle"/>
            <span class="fct-skel-block fct-skel-dot"/>
            <span class="fct-skel-block fct-skel-title" :style="{ width: titleWidth(i) }"/>
            <span class="fct-skel-block fct-skel-action"/>
        </div>
    </div>
</template>

<script setup>
// Vary widths so the loader doesn't read as a perfect grid.
const widths = ['60%', '40%', '70%', '35%', '55%', '45%'];
const titleWidth = (i) => widths[(i - 1) % widths.length];
</script>
