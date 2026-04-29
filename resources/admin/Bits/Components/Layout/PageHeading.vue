<template>
    <div class="page-heading-wrap">
      <slot name="title" v-if="$slots['title']"/>

      <h1 class="page-title" v-else>
          <router-link :to="to" v-if="to">
              <DynamicIcon v-if="icon" :name="icon"/>
          </router-link>
         {{title}}
      </h1>

      <div class="actions" v-if="$slots['action']">
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
