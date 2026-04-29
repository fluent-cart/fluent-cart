<template>
    <div :class="{'fct-content-card': show_heading!== false}">
        <div class="fct-content-card-header" v-if="show_heading!== false">
            <div class="title" v-if="title || $slots['title']">
                <slot name="title">{{ title }}</slot>
            </div>
            <div class="fct-content-card-action" v-if="$slots['action']">
                <slot name="action"/>
            </div>
        </div>
        <div :class="{'fct-content-card-body': show_heading!== false}" >
            <slot/>
        </div>
    </div>
</template>

<script setup>
const props = defineProps(['title', 'show_heading']);
</script>
