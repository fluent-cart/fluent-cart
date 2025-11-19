<script setup>
import { useRouter } from 'vue-router'

const props = defineProps({
  to: {
    type: Object,
    required: true
  },
  params: {
    type: Object,
    default: () => ({})
  },
})

const router = useRouter()

function handleClick() {
  const hasVisiblePopover = Array.from(
      document.querySelectorAll('.el-popper.is-light.el-popover')
  ).some(el => getComputedStyle(el).display !== 'none')

  if (hasVisiblePopover) return

  router.push({
    name: props.to.name,
    params: {
      ...props.to.params,
      ...props.params
    }
  })
}
</script>

<template>
  <router-link class="link block cursor-pointer route-cell" :to="props.to">
    <div class="table-cell">
      <slot />
    </div>
  </router-link>
</template>

<style scoped>

</style>
