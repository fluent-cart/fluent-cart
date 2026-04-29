<template>
  <div class="fct-product-route-wrap">
    <template v-if="route.name === 'product_edit' && loading">
      <SingleProductLoader/>
    </template>


    <NotFound v-if="notFound.show" :button-text="notFound.buttonText" :message="notFound.message"
              :route="notFound.route"/>
    <router-view v-if="product && notFound.show == false" :product="product" :taxonomies="taxonomies"/>
  </div>
</template>

<script setup>
import {ref, onMounted, onBeforeUnmount, watch, reactive, provide} from 'vue';
import {useRoute} from 'vue-router';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {formatNumber} from "@/Bits/productService";
import Rest from "@/utils/http/Rest";
import SingleProductLoader from "@/Modules/Products/parts/SingleProductLoader.vue";
import NotFound from "@/Pages/NotFound.vue";
import Arr from "@/utils/support/Arr";

import dayjs from "dayjs";
import Notify from "@/utils/Notify";


const props = defineProps({
  product_id: {
    type: [String, Number],
    required: true
  }
});

const notFound = reactive({
  show: false,
  message: '',
  buttonText: '',
  route: ''
});


const product = ref(false);
const taxonomies = ref(false);
const loading = ref(false);

const route = useRoute();
const renderMenu = ref(true);

const fetchProduct = () => {
  loading.value = true;
  notFound.show = false;
  Rest.get('products/' + props.product_id + '/pricing', {
    with: ['product_menu']
  })
      .then(response => {
        product.value = response.product;
        product.value.variants = formatPricing(product.value.variants);
        taxonomies.value = response.taxonomies;
        product.value.post_date = dayjs.utc(product.value.post_date).local().format('YYYY-MM-DDTHH:mm:ssZ');

        // Append a product menu to the DOM

        if(renderMenu.value){
          jQuery('#fct_admin_menu_holder').append(response.product_menu);
        }

        setActiveMenu(true);
      })
      .catch((errors) => {
        if (errors?.code === 'fluent_cart_entity_not_found') {
          notFound.show = true;
          notFound.buttonText = Arr.get(errors, 'data.buttonText');
          notFound.message = Arr.get(errors, 'data.message');
          notFound.route = Arr.get(errors, 'data.route');
          jQuery('.fct-admin-product-header').remove();
        } else {
          if (errors.status_code == '422') {
            Notify.validationErrors(errors);
          } else {
            Notify.error(errors.data?.message);
          }
        }
      })
      .finally(() => {
        loading.value = false;
      });
}

const formatPricing = (prices) => {
  let result = {};

  prices.forEach((pricing, idx) => {
    pricing.item_price = pricing.item_price ? formatNumber(pricing.item_price, false) : 0;
    pricing.item_cost = pricing.item_cost ? formatNumber(pricing.item_cost, false) : 0;
    pricing.compare_price = pricing.compare_price ? formatNumber(pricing.compare_price, false) : '';

    if (pricing.other_info) {
      pricing.other_info.signup_fee = pricing.other_info.signup_fee ? formatNumber(pricing.other_info.signup_fee, false) : 0;
    }

    pricing.rowId = idx;

    result[pricing.id] = pricing;
  });

  return prices;
}

const setActiveMenu = (isNew = false) => {
  let productMenu = jQuery('.fct-admin-product-menu');
  let routeName = route.name;

  if (productMenu.length) {
    productMenu.find('li').removeClass('active-menu');
    productMenu.find(`li.fct_menu_${routeName}`).addClass('active-menu');
  }

  if (isNew && product.value) {
    jQuery('#wp-admin-bar-root-default .fct_view_link').remove();
    jQuery('#wp-admin-bar-root-default').append('<li class="fct_view_link" id="wp-admin-bar-view"><a class="ab-item" href="' + product.value.guid + '" target="_blank">View Product</a></li>');
  }
}


watch(() => props.product_id, (newVal, oldVal) => {
  if (newVal !== oldVal) {
    fetchProduct();
  }
});

onMounted(() => {
  fetchProduct();
  setActiveMenu();

  window.addEventListener('hashchange', setActiveMenu);
});

onBeforeUnmount(() => {
  jQuery('.fct-admin-product-header').remove();
  window.removeEventListener('hashchange', setActiveMenu);
});

const reload = () => {
  renderMenu.value = false;
  fetchProduct();

}

// Provide the method to child components
provide('reload', reload)
</script>
