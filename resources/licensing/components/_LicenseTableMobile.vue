<template>
  <UserCan permission="licenses/view">
    <div class="fct-table-mobile-wrap fct-licenses-table-mobile">
      <div v-for="(row, rowIndex) in formattedLicense" :key="row.id" class="fct-table-mobile-row">
        <div
            class="fct-table-mobile-header"
            @click="$router.push({ name: 'view_license', params: { license_id: row?.id } })"
        >
          <div class="fct-table-status-col">
            <Badge :status="row.status" :key="row.id"/>
          </div>

          <div class="fct-table-date-col" v-if="columns.indexOf('date') !== -1">
            <span>
              {{ formatDate(row.created_at) }}
            </span>
            <span class="px-1">-</span>
            <span v-if="row.expiration_date">
                {{ formatDate(row.expiration_date) }}
            </span>
            <span v-else>{{ translate('Lifetime') }}</span>
          </div>
        </div><!-- fct-table-mobile-header -->

        <div class="fct-table-mobile-body">
          <div
              v-if="columns.indexOf('product') !== -1" class="fct-table-product-col"
              @click="$router.push({ name: 'view_license', params: { license_id: row?.id } })"
          >
            <ProductInfo
                :product="row.product"
                :hideProductId="true"
                :hideViewIcon="true"
                :hideImage="true"
            />
          </div>

          <div class="fct-license-key-wrap has-bg">
            <div class="fct-license-key">
              <div class="fct-license-key-inner pr-2">
                <router-link class="link" :to="{ name: 'view_license', params: { license_id: row?.id } }">
                  {{ row.license_key }}
                </router-link>
              </div>

              <el-tooltip :content="row.license_key" placement="top"
                          popper-class="fct-tooltip">
                <DynamicIcon class="cursor-pointer p-1 w-6 h-6" name="Copy" @click="()=>{
                    Clipboard.copy(row.license_key,{
                      'successMessage': translate('License Key copied to clipboard'),
                      'errorMessage': translate('Failed to copy License Key')
                    });
                  }"/>
              </el-tooltip>
            </div>
          </div>
        </div><!-- fct-table-mobile-body -->

        <div class="fct-table-mobile-footer">
          <div class="fct-table-mobile-footer-row">
            <div v-if="columns.indexOf('order_id') !== -1" class="fct-table-id-col">
              <div class="title">{{translate('Order ID')}}</div>
              <router-link
                  class="value"
                  :to="{ name: 'view_order', params: { order_id: row.order_id } }"
              >
                #{{ row.order_id }}
              </router-link>
            </div>

            <div v-if="columns.indexOf('customer') !== -1" class="fct-table-customer-col">
              <div class="title">{{translate('Customer')}}</div>
              <router-link
                  class="value"
                  :to="{
                    name: 'view_customer',
                    params: { customer_id: row.customer_id },
                  }"
              >
                {{ row?.customer?.full_name || translate('No Name') }}
              </router-link>
            </div>

            <div class="fct-table-activations-col">
              <div class="title">{{translate('Activations')}}</div>
              <router-link
                  class="value"
                  :to="{
                    name: 'view_license',
                    params: { license_id: row?.id },
                  }"
              >
                {{ row.activation_count }} {{'/'}}
                {{ row.limit === 0 ? "Unlimited" : row.limit }}
              </router-link>
            </div>
          </div>

        </div><!-- fct-table-mobile-footer -->





      </div>

      <!-- Empty State -->
      <div v-if="formattedLicense.length === 0" class="py-6 text-center">
        <Empty icon="Empty/ListView" has-dark :text="emptyText"/>
      </div>
    </div>
  </UserCan>

</template>
<script type="text/babel">
import Badge from "@/Bits/Components/Badge.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import {formatDate} from "@/Bits/common";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Clipboard from "@/utils/Clipboard";
import translate from "@/utils/translator/Translator";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import ProductInfo from "@/Bits/Components/Card/ProductInfo.vue";

export default {
  name: 'LicenseTableMobile',
  components: {
    ProductInfo,
    UserCan,
    RouteCell,
    DynamicIcon,
    IconButton,
    CopyToClipboard,
    Empty,
    Badge
  },
  props: {
    licenses: {
      type: Array,
      required: true
    },
    columns: {
      type: Array,
      default() {
        return ['product', 'order_id', 'customer', 'date'];
      }
    },
    emptyText: {
      type: String,
      default: translate('No Licenses found based on your filter')
    }
  },
  computed: {
    Clipboard() {
      return Clipboard
    },
    formattedLicense() {
      return this.licenses.map(license => {
        return {
          ...license,
          product: {
            ...license.product,
            variation_title: license.product_variant?.variation_title || 'n/a'
          }
        };
      });
    }
  },
  methods: {
    translate,
    formatDate
  }
}

</script>
