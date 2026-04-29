<template>
  <div>
    <el-dialog
        :model-value="showModal"
        :title="$t('Quick Edit')"
        width="45%"
        :before-close="handleModalClose"
    >
      <el-form label-position="top" require-asterisk-position="right">
        <el-row :gutter="20">
          <el-col :lg="12">
            <div class="fct-admin-input-wrapper">
              <el-form-item :label="$t('Title')" >
                <el-input :placeholder="$t('Title')" type="text"  v-model="productData.post_title">
                </el-input>
              </el-form-item>
            </div>
          </el-col>
          <el-col :lg="12">
            <div class="fct-admin-input-wrapper">
              <el-form-item :label="$t('Slug')">
                <el-input :placeholder="$t('Slug')" type="text" v-model="productData.post_name">
                </el-input>
              </el-form-item>
            </div>
          </el-col>
          <el-col :lg="12">
            <div class="fct-admin-input-wrapper">
              <el-form-item :label="$t('Status')">
                <el-select v-model="productData.post_status" :placeholder="$t('Select')">
                  <el-option :label="$t('Published')" value="publish"/>
                  <el-option :label="$t('Draft')" value="draft"/>
                  <el-option :label="$t('Scheduled')" value="future"/>
                </el-select>
              </el-form-item>
            </div>
          </el-col>
          <el-col :lg="12">
            <div class="fct-admin-input-wrapper">
              <el-form-item :label="$t('Product Type')">
                <el-select v-model="productData.detail.fulfillment_type" :placeholder="$t('Product Type')">
                  <el-option :label="$t('Physical Product')" value="physical"/>
                  <el-option :label="$t('Digital Product')" value="digital"/>
                </el-select>
              </el-form-item>
            </div>
          </el-col>
          <el-col :lg="24" v-if="productData.post_status === 'future'">
            <div class="fct-admin-input-wrapper">
              <el-form-item :label="$t('Scheduled Date')">
                <el-date-picker
                    :clearable="false"
                    v-model="productData.post_date"
                    type="datetime"
                    :placeholder="$t('Schedule Date')"
                    value-format="YYYY-MM-DDTHH:mm:ssZ"
                    @change="value => {updateProductValue('post_date',value)}"
                />
              </el-form-item>
            </div>
          </el-col>
          <el-col :lg="24">
            <div class="fct-admin-input-wrapper">
              <el-form-item :label="$t('Variation Type')">
                <el-select v-model="productData.detail.variation_type" :placeholder="$t('Select')">
                  <el-option :label="$t('Simple')" value="simple"/>
                  <el-option :label="$t('Simple Variation')" value="simple_variations"/>
                </el-select>
              </el-form-item>
            </div>
          </el-col>
          <el-col :lg="24">
            <div class="fct-admin-input-wrapper">
              <el-checkbox v-model="productData.comment_status" true-value="open" false-value="closed">
                {{ productData.comment_status === 'open' ? $t('Enable') : $t('Disable')}} {{$t('Comment') }}
              </el-checkbox>
            </div>
          </el-col>

        </el-row>
      </el-form>
      <div class="dialog-footer">
        <el-button type="info" soft @click="handleModalClose">
          {{$t('Cancel')}}
        </el-button>
        <el-button type="primary" @click="handleProductUpdate">
          {{$t('Update')}}
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>

import {handleError, handleSuccess} from "@/Bits/common";
import {updatePostStatus} from "@/Bits/productService";
import dayjs from "dayjs";
import Notify from "@/utils/Notify";

export default {
  name: 'QuickEditProductModal',
  props: {
    product: {
      type: Object,
      required: true
    },
    showModal: {
      type: Boolean,
      required: true
    }
  },
  data() {
    return {
      productData: {},
      singleProductRow: {}
    }
  },
  methods: {
    updateProductValue (name, value) {
      if (name === 'post_date') {
        this.productData[name] = dayjs(value).format('YYYY-MM-DDTHH:mm:ssZ');
      }
    },
    handleSingleProductRowUpdate() {
      this.$emit('singleProductRowUpdate', this.singleProductRow);
    },
    fetchProduct() {
      this.$emit('fetch');
    },
    handleModalClose() {
      this.$emit('closeModal');
    },
    handleProductUpdate(){
      const url = 'products/' + this.productData.ID + '/pricing';
      let data = this.productData;

      this.$post(url, data)
        .then(response => {
          this.singleProductRow = response.data;
          this.handleSingleProductRowUpdate();
          this.fetchProduct();
          this.handleModalClose();
          handleSuccess(response.message);
        })
        .catch((errors) => {
          if (errors.status_code == '422') {
            Notify.validationErrors(errors);
          } else {
            Notify.error(errors.data?.message);
          }
        })
        .finally(() => {

        });
    }
  },
  watch: {
    // Watch for changes to the 'product' prop
    product: {
      immediate: true, // Run the handler immediately on component mount
      handler(newVal) {
        // Update the 'data' object with the new value of 'product'
        this.productData = { ...newVal }; // Spread operator to create a new object
      }
    }
  },
}
</script>
