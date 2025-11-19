<script setup>
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import * as Card from '@/Bits/Components/Card/Card.js';
import {onMounted, ref} from "vue";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import {useRoute} from 'vue-router'
import translateNumber from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";
import GutenbergEditor from "@/Bits/Components/Inputs/GutenbergEditor.vue";
import * as Fluid from "@/Bits/Components/FluidTab/FluidTab.js";

const props = defineProps({
  product: Object,
  productEditModel: Object,
  reload: Function
})

const isDomReady = ref(false);
const activeTab = ref('wp-editor'); // Default to classic editor


const currentTarget = ref('#gt');

let lastContent = '';
const route = useRoute();


const wpEditor = ref();
const handleTabChange = (newTab) => {
  props.productEditModel.updateLongDescEditorChange(newTab);
  activeTab.value = newTab;
}

onMounted(() => {
  isDomReady.value = true

  props.productEditModel.getMaxExcerptWordCount();

  if (props.product?.post_excerpt !== undefined && props.product?.post_excerpt.toString().length > 0) {
    props.productEditModel.setExcerptWordCount(props.product?.post_excerpt.toString().split(' ').length)
  }

  if (props.product?.detail?.other_info?.active_editor) {
    activeTab.value = props.product.detail.other_info.active_editor;
  }


})


</script>

<template>
  <div class="fct-product-info-wrap">
    <Card.Container>
      <Card.Body>
        <el-form label-position="top" require-asterisk-position="right">
          <div class="fct-admin-input-wrapper">
            <el-form-item :label="translate('Title')" required>
              <el-input :class="productEditModel.hasValidationError('post_title') ? 'is-error' : ''"
                        :id="`post_title`"
                        :placeholder="translate('Title')"
                        type="text" v-model="product.post_title"
                        @input="value => {productEditModel.onChangeInputField('post_title',value)}"
                        @focus="productEditModel.clearValidationError(`post_title`)">
              </el-input>
              <ValidationError :validation-errors="productEditModel.validationErrors"
                               field-key="post_title"/>
            </el-form-item>
          </div>


          <div class="fct-admin-input-wrapper">
            <el-form-item :label="translate('Short description')">
              <el-input
                  @input="value => {productEditModel.onChangeInputField('post_excerpt',value)}"
                  v-model="product.post_excerpt"
                  :rows="2"
                  type="textarea"
                  @focus="productEditModel.clearValidationError(`post_excerpt`)"
              />

              <div>
                <div class="form-note">
                  <p class="mt-[7px]">
                    {{
                      /* translators: %1$s - current word count, %2$s - max word count */
                      translate('%1$s Words of %2$s', translateNumber(productEditModel.data.excerptWordCount), translateNumber(productEditModel.maxExcerptWordCount))
                    }}</p>
                </div>
                <ValidationError :validation-errors="productEditModel.validationErrors"
                                 field-key="post_excerpt"/>
              </div>
            </el-form-item>
          </div>


          <!-- <el-button text type="primary" class="mt-2" @click="showIframe = true">
            <DynamicIcon name="Edit"/>
            {{ $t('Edit') }}
          </el-button>

          <teleport :to="currentTarget" v-if="isDomReady">
            <iframe :src="`${baseUrl}?page=hidden-admin-page&id=`+route.params.product_id" width="100%" height="500px"/>
          </teleport>

          <div id="gt">


          </div>

          <el-dialog :append-to-body="true" width="70%" v-model="showIframe" @opened="() => {
            currentTarget = '#gt2'
          }" @closed="() => {
            currentTarget = '#gt'
          }">
            <div id="gt2">

            </div>
          </el-dialog> -->

        </el-form>
      </Card.Body>
    </Card.Container>

    <Card.Container>
      <Card.Header :title="translate('Long Description')">
        <template #action>
          <!-- Tab Navigation -->
          <div class="editor-tabs-nav" v-loading="productEditModel.hasChangeLongDescEditor">
            <Fluid.Tab>
              <Fluid.Item
                  :label="translate('Classic Editor')"
                  :class="activeTab === 'wp-editor' ? 'active' : ''"
                  @click="handleTabChange('wp-editor')"
              />
              <Fluid.Item
                  :label="translate('Builder')"
                  :class="activeTab === 'gutenberg' ? 'active' : ''"
                  @click="handleTabChange('gutenberg')"
              />
            </Fluid.Tab>
          </div>
        </template>
      </Card.Header>

      <Card.Body>
        <el-form label-position="top" require-asterisk-position="right">

          <div class="fct-custom-long-desc-wrap fct-admin-input-wrapper">
            <el-form-item>
              <div class="custom-wp-editor-wrapper relative">

                <!-- Tab Content -->
                <div class="relative rounded overflow-hidden">
                  <!-- WP Editor Tab -->
                  <div v-show="activeTab === 'wp-editor'" class="editor-tab-content">
                    <wp-editor
                        ref="wpEditor"
                        :modelValue="product.post_content"
                        @update="value => {
                          productEditModel.onChangeInputField('post_content', value)
                        }"
                    />
                  </div>

                  <!-- Gutenberg Editor Tab -->
                  <div v-show="activeTab === 'gutenberg'" class="editor-tab-content">
                    <GutenbergEditor :post_id="product.ID" :product="product" :reload="reload" :productEditModel="productEditModel"/>
                  </div>
                </div>


              </div>
            </el-form-item>
          </div>
        </el-form>
      </Card.Body>
    </Card.Container>


  </div>
</template>

<style lang="scss">
.fct-custom-gutenberg-editor-wrap {
  @apply relative overflow-hidden;
  .fct-custom-gutenberg-editor-wrap-overlay {
    @apply absolute left-0 top-0 w-full h-full z-50;
  }

  iframe {
    overflow: hidden;

    &::-webkit-scrollbar {
      width: 0;
    }
  }
}

.fct-custom-gutenberg-editor-dialog {
  overscroll-behavior: contain;
  @apply z-[99999999] #{!important};
  .el-overlay-dialog {
    overscroll-behavior: contain;
    @apply h-[100vh] overflow-hidden block bg-white #{!important};
    .el-dialog {
      @apply w-full h-full m-0 rounded-none shadow-none #{!important};
      .el-dialog__body {
        padding: 0;
        @apply h-[calc(100%-22px)] #{!important};
        .fct-custom-gutenberg-editor-wrap {
          height: 100%;

          iframe {
            height: 100%;
          }
        }
      }

      .el-dialog__header {
        padding-top: 5px;
        padding-bottom: 5px;

        .dialog-header {
          @apply flex justify-between items-center w-full;
        }
      }
    }
  }

  .fct-custom-gutenberg-editor-close-btn {

    .icon {
      svg {
        @apply w-4 h-4;
      }
    }
  }
}

</style>
