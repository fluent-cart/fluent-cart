<script setup>
import {useProductEditModel} from "@/Models/Product/ProductEditModel";
import {onMounted, provide, ref, watch} from "vue";
import {saveAndCreateNewButtonShortcutMixin} from "@/mixin/saveAndCreateNewButtonShortcutMixin";
import Alert from "@/Bits/Components/Alert.vue";
import SaveBar from '@/Bits/Components/SaveBar.vue';
import ProductInfo from "@/Modules/Products/parts/ProductInfo.vue";
import ProductPricing from "@/Modules/Products/parts/ProductPricing.vue";
import ProductInventory from "@/Modules/Products/parts/ProductInventory.vue";
import ProductDownload from "@/Modules/Products/parts/DownloadableFiles/ProductDownload.vue";
import ProductStatus from "@/Modules/Products/parts/ProductStatus.vue";
import ProductMedia from "@/Modules/Products/parts/ProductMedia.vue";
import ProductTermTaxonomy from "@/Modules/Products/parts/ProductTermTaxonomy.vue";
import {useProductDownloadableModel} from "@/Models/Product/ProductDownloadableModel";
import ShippingClass from "@/Modules/Products/parts/ShippingClass.vue";
import TaxClass from "@/Modules/Products/parts/TaxClass.vue";
import DynamicTemplates from "@/Bits/Components/DynamicTemplates/DynamicTemplates.vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import {onBeforeRouteLeave} from "vue-router";
import {inject} from 'vue'

const props = defineProps({
  product: Object,
  product_id: String,
  taxonomies: Object
})

const dynamicTemplates = ref();

const productEditModel = useProductEditModel();


let editableProduct = null;

const productDownloadableModel = useProductDownloadableModel();
productEditModel.setProductDownloadableModel(productDownloadableModel)

const isEditingReady = ref(false);
const setupProduct = (product) => {

  isEditingReady.value = false;
  productEditModel.setProduct(product);
  editableProduct = productEditModel.data.product;

  productDownloadableModel.setDownloadableFiles(product.downloadable_files);

  editableProduct.gallery = editableProduct?.detail?.gallery_image?.meta_value ?? [];

  editableProduct.taxonomies = props.taxonomies;
  editableProduct.product_terms = {};

  Object.keys(editableProduct.taxonomies).map((key) => {
    editableProduct.product_terms[key] = editableProduct[key] ?? [];
  });

  isEditingReady.value = true;
}


//this is here to be used with keyboard shortcut// and passed to a Product download component


const saveAndCreateShortcut = saveAndCreateNewButtonShortcutMixin();

saveAndCreateShortcut.onSave(() => {
  productEditModel.data.metaValue = dynamicTemplates.value?.getFormStates();
  productEditModel.update();
})

const keyboardShortcuts = useKeyboardShortcuts();

keyboardShortcuts.bind(['mod+s'], (event) => {
  event.preventDefault();
  if (productEditModel.data.hasChange) {
    if (productDownloadableModel.isAddModalOpen()) {
      productDownloadableModel.attachInsertableFiles(props.product.ID)
    } else if (productDownloadableModel.isEditModalOpen()) {
      productDownloadableModel.updateDownloadableFile()
    } else {
      productEditModel.data.metaValue = dynamicTemplates.value?.getFormStates();
      productEditModel.update();
    }
  }
});

const triggerChange = () => {
  productEditModel.setHasChange(true);
}

const handleProductAction = (command) => {
  if (command === 'delete') {
    productEditModel.delete();
  }
}

provide('triggerChange', triggerChange)

const reload = inject('reload')

watch(() => props.product, (newVal, oldVal) => {
  if (newVal !== oldVal) {
    setupProduct(newVal);
  }
});
onMounted(() => {
  setupProduct(props.product);
  productEditModel.setReloader(reload);


  const header = document.querySelector('.fct-admin-product-header');
  if (header) {
    const app = document.querySelector('#fluent_cart_plugin_app');
    app.prepend(header);
  }
});

onBeforeRouteLeave(() => {
  keyboardShortcuts.unbind('mod+s');
});


// Inject the parent method


</script>

<template>
  <div class="fct-edit-product-wrapper fct-layout-width">
    <!--    route.name === 'product_edit'-->
    <teleport to="#fct-admin-product-header-buttons">
      <div class="fct-btn-group sm">
        <el-dropdown
            trigger="click"
            class="fct-more-option-wrap"
            popper-class="fct-dropdown"
            @command="handleProductAction">

          <el-button>
            <span class="hide-on-mobile">
              {{ translate('More Actions') }}
              <DynamicIcon name="ChevronDown"/>
            </span>

            <span class="show-on-mobile">
              <DynamicIcon name="More"/>
            </span>
          </el-button>

          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="preview" class="item-link">
                <a :href="productEditModel.viewUrl()" target="_blank">
                  <DynamicIcon name="Eye"/>
                  {{ translate('Preview') }}
                </a>
              </el-dropdown-item>

              <el-dropdown-item command="delete" class="item-destructive">
                <DynamicIcon name="Delete"/>
                {{ translate('Delete') }}
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </teleport>

    <SaveBar
        v-if="isEditingReady"
        :isActive="productEditModel.data.hasChange ? 'is-active' : ''"
        :loading="productEditModel.data.saving"
        @discard="productEditModel.discard"
        @save="()=>{
          productEditModel.data.metaValue = dynamicTemplates?.getFormStates();
          productEditModel.update();
        }"
        :saveButtonText="translate('Update')"
        :loadingText="translate('Updating')"
        :show-cmnd-icon="true"
    >
    </SaveBar>


    <div class="single-page-body" v-if="isEditingReady">

      <Alert
          class="mb-3"
          v-if="productEditModel.shouldShowShippingMethodAlert()"
          type="warning"
          icon="InformationFill"
      >

        <span v-html="
        /* translators: %s is the link to shipping settings */
        translate(
    'This Product has physical variants. Please %s',
          '<a href=&quot;' + productEditModel.shippingSettingsUrl() + '&quot; target=&quot;_blank&quot;>' + translate('Add Shipping') + '</a>'
          )"
        >
        </span>

      </Alert>

      <Alert
          class="mb-3"
          v-if="productEditModel.isInDraft()"
          type="warning"
          icon="InformationFill"
          :content="translate('This product is currently in draft and cannot be sold to customers. Please publish the product to make it available for purchase.')"
      />

      <div class="fct-product-edit">
        <div class="fct-product-main">
          <product-info
              :product="editableProduct"
              :productEditModel="productEditModel"
              :reload="reload"
          />
          <div
              class="fct-show-on-tablet fct-product-publish-and-media-widgets el-form el-form--default el-form--label-top">
            <product-status :product="editableProduct"
                            :productEditModel="productEditModel"/>

            <ProductMedia :product="editableProduct"
                          :productEditModel="productEditModel"/>
          </div>

          <product-pricing
              :product="editableProduct"
              :productEditModel="productEditModel"
              :productDownloadableModel="productDownloadableModel"
          />

          <product-inventory
              :product="editableProduct"
              :productEditModel="productEditModel"/>

          <product-download
              :product="editableProduct"
              :productEditModel="productEditModel"
              :productDownloadableModel="productDownloadableModel"
          />
        </div>
        <div class="fct-product-aside">
          <div class="el-form el-form--default el-form--label-top">
            <product-status class="fct-hide-on-tablet" :product="editableProduct"
                            :productEditModel="productEditModel"/>

            <ProductMedia class="fct-hide-on-tablet" :product="editableProduct"
                          :productEditModel="productEditModel"/>

            <ProductTermTaxonomy :product="editableProduct"
                                 :productEditModel="productEditModel"/>

            <ShippingClass
                v-if="productEditModel.hasPhysicalVariation()"
                :product="editableProduct"
                :productEditModel="productEditModel"/>

            <TaxClass
                v-if="productEditModel.isTaxEnabled()"
                :product="editableProduct"
                :productEditModel="productEditModel"/>

            <div v-if="editableProduct">
              <DynamicTemplates
                  ref="dynamicTemplates"
                  filter="single_product_page"
                  :widgets-query="{
                      'product_id': editableProduct.ID
                    }"
                  :data="{ editableProduct }"
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
