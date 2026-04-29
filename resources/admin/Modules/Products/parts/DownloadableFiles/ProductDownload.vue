<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ProductDownloadAddModal from "@/Modules/Products/parts/DownloadableFiles/ProductDownloadAddModal.vue";
import ProductDownloadEditModal from "@/Modules/Products/parts/DownloadableFiles/ProductDownloadEditModal.vue";
import translate from "@/utils/translator/Translator";
import Notify from "@/utils/Notify";
import Clipboard from "@/utils/Clipboard";

const props = defineProps({
  product: Object,
  productEditModel: Object,
  productDownloadableModel: Object
})

import Animation from "@/Bits/Components/Animation.vue";
import Rest from "@/utils/http/Rest";


const getDownloadableFile = function (row) {
  Rest.get(`products/getDownloadableUrl/${row.id}`)
      .then(response => {
        Clipboard.copy(response.url);
      }).catch(error => {
    Notify.error({
      title: translate('Error'),
      message: translate('Failed to copy file URL')
    });
  });
}

const handleDropdownCommand = (command) => {
  if (command.action === 'copy-download-link') {
    getDownloadableFile(command.data);
  } else if (command.action === 'delete') {
    props.productDownloadableModel.deleteDownloadableFile(command.data.id, command.idx);
  }
}

</script>

<template>
  <div class="fct-product-download-wrap">
    <Card.Container>
      <Card.Header class="pb-5">
        <template #action>
          <el-switch v-if="product.detail" v-model="product.detail.manage_downloadable" active-value="1"
                     inactive-value="0" :active-text="$t('Downloadable Asset(s)')"
                     @change="value =>{
                          //Trigger the change when the Downloadable Asset option is turned off or if files are already attached.
                          // However, do not trigger the change on the initial setup, as the option is automatically enabled when files are saved.
                       if(value.toString() === '0' || productDownloadableModel.hasDownloadableFiles()){
                         productEditModel.setHasChange(true)
                       }
                     }">
          </el-switch>
        </template>
      </Card.Header>
      <Animation :visible="product.detail?.manage_downloadable.toString() === '1'" accordion>
        <Card.Body class="p-0">
          <div class="fct-product-download-inner-wrap border border-x-0 border-b-0 border-solid border-gray-divider dark:border-dark-400"
               v-if="productDownloadableModel.hasDownloadableFiles()">

            <div class="fct-media-content-list">
              <div class="fct-media-content-list-item"
                   v-for="(item, itemIndex) in productDownloadableModel.downloadableFiles" :key="itemIndex">
                <div class="product-thumbnail bg-gray-100 rounded flex items-center justify-center dark:bg-dark-500">
                  {{ item.type }}
                </div>

                <div class="product-details">
                  <div class="product-details-content-row">
                    <div class="product-details-content-first">
                      <div class="product-title text-sm font-medium text-system-dark dark:text-gray-50">
                        {{ item.title }}
                        <span v-if="item.driver !== 'local'" class="font-normal text-system-mid">
                          - {{ item.driver }}
                        </span>
                      </div>

                      <el-popover
                          placement="top"
                          trigger="hover"
                          :width="200"
                          v-if="productDownloadableModel.getHiddenVariationNames(item.product_variation_id, product).length"
                      >
                        <div style="margin-top: 4px;"
                             v-for="(variation, index) in productDownloadableModel.getHiddenVariationNames(item.product_variation_id, product)"
                             :key="index">
                          {{ variation }}
                        </div>

                        <template #reference>
                          <div class="product-variation-title inline-flex">
                            &#8211; {{
                              productDownloadableModel.generateDownloadableVariationName(item.product_variation_id, product)
                            }}
                          </div>
                        </template>
                      </el-popover>

                      <!-- fallback when there's nothing hidden -->
                      <div class="product-variation-title" v-else>
                        &#8211; {{
                          productDownloadableModel.generateDownloadableVariationName(item.product_variation_id, product)
                        }}
                      </div>
                    </div>
                    <div class="product-details-content-second">
                      <el-dropdown
                          trigger="click"
                          class="fct-more-option-wrap"
                          popper-class="fct-dropdown"
                          placement="bottom-end"
                          @command="handleDropdownCommand"
                      >
                      <span class="more-btn">
                        <DynamicIcon name="More"/>
                      </span>

                        <template #dropdown>
                          <el-dropdown-menu>
                            <el-dropdown-item class="item-link">
                              <ProductDownloadEditModal
                                  :product="product"
                                  :productDownloadableModel="productDownloadableModel"
                                  :productEditModel="productEditModel"
                                  :productDownloadable="item"
                                  :index="itemIndex"
                              />
                            </el-dropdown-item>

                            <el-dropdown-item :command="{
                              action: 'copy-download-link',
                              data: item
                          }">
                              <DynamicIcon name="Copy"/>
                              {{ translate('Copy Download Link') }}
                            </el-dropdown-item>

                            <el-dropdown-item
                                :command="{
                              action: 'delete',
                              data: item,
                              idx: itemIndex
                          }"
                                class="item-destructive"
                            >
                              <DynamicIcon name="Delete"/>
                              {{ translate('Delete') }}
                            </el-dropdown-item>
                          </el-dropdown-menu>
                        </template>
                      </el-dropdown>
                    </div>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <ProductDownloadAddModal :product="product" :productEditModel="productEditModel"
                                   :productDownloadableModel="productDownloadableModel"/>
        </Card.Body>
      </Animation>
    </Card.Container>


  </div>
</template>

