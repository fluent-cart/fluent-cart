<template>
    <div class="page-heading-wrap">
        <h1 class="page-title">
            <router-link :to="to" v-if="to">
                <DynamicIcon v-if="icon" :name="icon"/>
            </router-link>
            {{title}}
        </h1>
      <div class="actions">
        <slot name="action"/>
      </div>
    </div><!-- .page-heading-wrap -->
</template>

<script>
    import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

    export default {
        name: "PageHeading",
        components: {
            DynamicIcon
        },
        props: {
            title: {
                type: String
            },
            icon: {
                type: String
            },
            to: {
                type: Object
            }
        }
    }
</script>
