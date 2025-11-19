<template>
    <router-link
        v-if="to"
        :to="to"
        class="icon-button"
        :class="[isBorder, isSize, isCircle, isBg, isHover]"
        :title="title"
        :target="target"
    >
        <slot/>
    </router-link>

    <component
        :is="isTag"
        v-else
        class="icon-button"
        :class="[isBorder, isSize, isCircle, isBg, isHover]"
        :href="href"
        :title="title"
        :target="target"
    >
        <slot/>
    </component>

</template>

<script>
    export default {
        name: "IconButton",
        props: {
            title: {
                type: String
            },
            href: {
                type: String
            },
            target: {
                type: String
            },
            border: {
                type: String
            },
            router_link: {
                type: Boolean
            },
            to: {
                type: Object
            },
            size: {
                type: String
            },
            tag: {
                type: String
            },
            circle: {
                type: Boolean
            },
            bg: {
                type: String
            },
            hover: {
                type: String
            }
        },
        computed: {
            isBorder(){
                return this.border ? 'border-' + this.border : '';
            },
            isSize(){
                return this.size ? this.size : '';
            },
            isTag(){
                return this.tag ? this.tag : 'div';
            },
            isCircle(){
                return this.circle ? 'is-circle' : '';
            },
            isBg(){
                return this.bg ? this.bg : '';
            },
            isHover(){
                return this.hover ? 'hover-' + this.hover : '';
            }
        }
    }
</script>
