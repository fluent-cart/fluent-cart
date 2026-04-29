<template>
    <div :class="`fct-card ${border ? 'fct-card-border' : ''}`">
        <slot></slot>
    </div>
</template>

<script>
    export default {
        name: "Card",
        props: {
            border: {
                type: Boolean
            }
        }
    }
</script>
