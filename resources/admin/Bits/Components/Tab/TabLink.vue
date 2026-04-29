<template>
    <router-link
        v-if="to"
        :to="to"
        class="fct-tab-link"
    >
        <slot/>
    </router-link>

    
    <div v-else class="fct-tab-link">
        <slot/>
    </div>
</template>

<script>
    export default {
        name: "TabLink",
        props: {
            to: {
                type: [Object, String]
            }
        }
    }
</script>
