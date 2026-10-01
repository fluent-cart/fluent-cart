<template>
  <div class="fct-user-avatar" :aria-hidden="decorative ? 'true' : null">
    <img
        v-if="url && !failed"
        class="fct-user-avatar-img"
        :src="url"
        :alt="decorative ? '' : name"
        loading="lazy"
        @error="failed = true"
    />
    <DynamicIcon v-else name="User"/>
  </div>
</template>

<script setup>
import {ref, watch} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  // Display name — the image's alt text when the avatar is not decorative.
  name: {
    type: String,
    default: ''
  },
  // Avatar URL. Empty, or a URL that fails to load, falls back to the user
  // icon — avatars requested from Gravatar with default=404 do exactly that
  // when the address has no avatar of its own.
  url: {
    type: String,
    default: ''
  },
  // Avatars usually sit beside the name they belong to, so by default they
  // are hidden from screen readers rather than announcing that name a second
  // time. Pass false where the avatar stands alone.
  decorative: {
    type: Boolean,
    default: true
  }
});

const failed = ref(false);

watch(() => props.url, () => {
  failed.value = false;
});

</script>
