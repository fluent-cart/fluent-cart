<script setup>
import {getCurrentInstance, onMounted, ref, useTemplateRef} from "vue";
import Collapsible from "@/Modules/Products/BulkInsert/Collapsible.vue";
import Importer from "@/Modules/Products/BulkInsert/Importer.vue";
import bulkInsetModel from "@/Models/BulkInsetModel";
import ResizeableColumns from "@/Modules/Products/BulkInsert/ResizeableColumns.vue";
import Gallery from "@/Bits/Components/Attachment/Gallery.vue";

const {options, products} = bulkInsetModel.data;

const getDummyVariation = (product) => {
  return bulkInsetModel.getDummyVariation(product)
};

const pushVariationToProduct = (product, defaults = {}) => {
  let dummyVariation = getDummyVariation(product);
  dummyVariation = {
    ...dummyVariation,
    ...defaults
  }

  product.variants.push({
    ...dummyVariation
  });
}

const insertDummyProduct = () => {
  bulkInsetModel.populateDummyProduct()
}

const self = getCurrentInstance().ctx;

const onDataPopulated = (populatedProducts) => {
  const {concat} = populatedProducts;
  if (concat) {
    products.value = products.value.concat(populatedProducts.products);
  } else {
    products.value = populatedProducts.products;
  }
}
const index = (1);

const columns = ref([]);


onMounted(() => {
  columns.value = [
    {title: self.$t('Title'), width: 150, minWidth: 100},
    {title: self.$t('Media'), width: 100, minWidth: 50},
    {title: self.$t('Description'), width: 200, minWidth: 150},
    {title: self.$t('Short Description'), width: 150, minWidth: 100},
    {title: self.$t('Status'), width: 100, minWidth: 50},
    {title: self.$t('Product Type'), width: 200, minWidth: 150},
    {title: self.$t('Pricing Type'), width: 200, minWidth: 150},
    {title: self.$t('Best Price'), width: 100, minWidth: 100},
    {title: self.$t('Compare-at Price'), width: 200, minWidth: 150},
    {title: self.$t('Track Quantity'), width: 100, minWidth: 100},
    {title: self.$t('Stock'), width: 150, minWidth: 150},
  ]
})

// Reactive properties


</script>

<template>


  <Importer @on-data-populated="onDataPopulated"/>


  <div class="relative">
    <ResizeableColumns :columns="columns">

      <template v-for="product in products">
        <tr class="border-black border-b-black border-b" style="border-bottom: 1px solid">
          <td class="sticky left-0 z-40 bg-white">
            <input class="w-full h-[40px] border-0 ring-0" v-model="product.post_title" />
          </td>

          <td>

            <div class="max-h-[30px] overflow-clip">
              <Gallery
                  :attachments="product.gallery"
                  @mediaUploaded="value => {
                  product.gallery = value;
                }"
                  @removeImage="value => {
                  product.gallery.splice(value, 1)
                  //scope.row.gallery = [...scope.row.gallery];
                }"
                  @onMediaChange="()=>{}"
              />
            </div>

          </td>

          <td>
            <input v-model="product.post_content" />
          </td>

          <td>
            <input v-model="product.post_excerpt" />
          </td>

          <td>
            <el-select v-model="product.post_status">
              <el-option
                  v-for="option in options.status"
                  :key="option.value"
                  :label="$t(option.title)"
                  :value="option.value"
              />
            </el-select>
          </td>

          <td>
            <el-select v-model="product.detail.fulfillment_type">
              <el-option
                  v-for="option in options.fulfilment"
                  :key="option.value"
                  :label="$t(option.title)"
                  :value="option.value"
              />
            </el-select>
          </td>

          <td>
            <el-select v-model="product.detail.variation_type" @change="(variationType)=>{
            bulkInsetModel.handleVariationChanged(product,variationType);
          }">
              <el-option
                  v-for="option in options.variation"
                  :key="option.value"
                  :label="$t(option.title)"
                  :value="option.value"
              />
            </el-select>
          </td>

          <td class="text-center">
            -----
          </td>

          <td class="text-center">
            -----
          </td>

          <td style="text-align: center">
            <el-switch v-model="product.detail.manage_stock" active-value="1" inactive-value="0">
            </el-switch>
          </td>

          <td class="text-center">
            -----
          </td>
        </tr>

        <template v-if="product.detail.variation_type !== 'simple' && Array.isArray(product.variants)" >
          <tr class="border-black border-b-black" style="border-bottom: 1px solid" v-for="variant in product.variants">

            <td class="sticky left-0 z-40 bg-white pl-2 ">
              <input v-model="variant.variation_title" :placeholder="$t('Title')"/>
            </td>

            <td>Image will be there</td>

            <td><span>-----</span></td>

            <td><span>-----</span></td>

            <td><span>-----</span></td>

            <td><span>-----</span></td>

            <td><span>-----</span></td>

            <td>
              <input v-model="variant.item_price" :placeholder="$t('Item Price')"/>
            </td>

            <td>
              <input v-model="variant.compare_price" :placeholder="$t('Compare Price')"/>
            </td>

            <td>
              -----
            </td>

            <td>
              -----
            </td>

          </tr>

          <tr>
            <td :colspan="columns.length">
              <button @click.prevent="bulkInsetModel.addVariationToProduct(product)">Add Variant</button>
            </td>
          </tr>
        </template>

      </template>

    </ResizeableColumns>
  </div>


  <div class="overflow-scroll" v-if="false">
    <ul class="list-none p-0">
      <li class="fct-collapsible-list-item" v-for="(product,index) in products">
        <div class="fct-collapsible-list-item-inner">
          <div class="fct-collapsible-item" :style="{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '10px',
            flexDirection: 'column',
            'border-bottom': '1px solid'
        }">
            <Collapsible :product="product" :pushVariationToProduct="(defaultData)=>{
              pushVariationToProduct(product,defaultData)
            }"/>
            <div>
              <el-button v-if="product?.detail?.variation_type !== 'simple'" @click="()=>{
                pushVariationToProduct(product,{})
              }">
                {{ $t('Add Variation') }}
              </el-button>
            </div>
          </div>
        </div>
      </li>
    </ul>
  </div>
  <el-button @click="insertDummyProduct">
    {{ $t('Add Product') }}
  </el-button>

</template>

<style scoped>
  td input{
    @apply w-full h-[40px] border-0 ring-0
  }
  td{
    border-right: 1px dashed grey;
  }
</style>
