<script setup>


import {onMounted, ref} from "vue";
import Search from "@/Bits/Components/Icons/Search.vue";
import translate from "../../utils/translator/Translator";

const props = defineProps({
  settings: {
    type: String,
    default: ''
  }
});

const settings = ref(JSON.parse(props.settings));
const showCategory = ref(false);

onMounted(() => {
  showCategory.value = settings.value.category_mode;
})

</script>

<template>
  <div class="fluent-cart-searchbar-block-editor">
    <div class="flex">
      <div v-if="showCategory" class="mr-4">
        <select
            name="termId"
            class="border border-gray-300 rounded-md text-lg bg-white h-full"
        >
          <option selected disabled>
            {{ translate('Category') }}
          </option>
        </select>
      </div>
      <div class="fct-searchbar-block-search-wrap w-full">
        <div class="fct-input-group">
          <div class="input-icon">
            <Search class="w-4 h-4" />
          </div>
          <input
              class="fct-input text-sm w-full bg-white !shadow-none rounded border border-solid border-gray-outline py-2 px-3.5 m-0 leading-[1.43] placeholder:text-system-light placeholder:text-[13px] focus:border-primary-500"
              type="text"
              :placeholder="translate('Search Products...')"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>

</style>
