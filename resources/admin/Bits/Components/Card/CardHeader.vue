<template>
    <div :class="`fct-card-header ${border_bottom ? 'border-bottom' : ''}`">
        <div class="flex-1" v-if="title || text || $slots['title']">
          <template v-if="title || text">
            <h2 :class="`fct-card-header-title ${title_size ? 'is-' + title_size : ''}`" v-if="title">
              <dynamic-icon v-if="icon" :name="icon"/>
              <img v-if="img" :src="img" alt="">
              {{title}}
            </h2>
            <p class="fct-card-header-text" v-if="text">{{text}}</p>
          </template>

          <template v-if="$slots['title']">
            <slot name="title"/>
          </template>
        </div>

        <div class="fct-card-header-actions" v-if="$slots['action']">
            <slot name="action"/>
        </div>

        <slot/>
    </div>
</template>

<script>
    import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

    export default {
        name: "CardHeader",
        components: {
            DynamicIcon
        },
        props: {
            title: {
                type: String
            },
            text: {
                type: String
            },
            border_bottom: {
                type: Boolean
            },
            icon: {
                type: String
            },
            title_size: {
                type: String
            },
            img: {
                type: String
            },
        }
    }
</script>
