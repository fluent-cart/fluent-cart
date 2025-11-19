<template>
  <div class="fct-not-found-page">
    <div class="fct-not-found-page-inner">
      <img :src="getNotFoundImage()" alt="404">
      <div>
        <h5>
          {{ data.message }}
        </h5>
        <p>Please check the URL and try again, or use the search bar to find what you need.</p>
        <el-button tag="router-link" type="primary" :to="data.route">
          {{ data.buttonText }}
        </el-button>
      </div>
    </div>
  </div>
</template>

<script setup>

import Asset from "@/utils/support/Asset";
import Card from "@/Bits/Components/Card/Card.vue";
import CardBody from "@/Bits/Components/Card/CardBody.vue";
import translate from "@/utils/translator/Translator";
import {onMounted, ref} from "vue";
import {useRoute, useRouter} from "vue-router";


const props = defineProps({
  buttonText: {
    type: String,
    default: translate('Back to Dashboard')
  },

  route: {
    type: String,
    default: '/'
  },

  message: {
    type: String,
    default: translate('Oops! That page can\'t be found.')
  }
})

const data = ref({});

const route = useRoute();
const router = useRouter();

onMounted(() => {
  data.value = {
    buttonText: route.query.buttonText || props.buttonText,
    route: route.query.route || props.route,
    message: route.query.message || props.message
  };
  router.replace({ query: {} });
})

const getNotFoundImage = () => {
  return Asset.getUrl('images/404.svg')
}

</script>
