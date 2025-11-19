<template>
  <UserCan permission="licenses/view">
    <div class="fct_licenses_table hide-on-mobile">
      <el-table :data="formattedLicense" class="w-full compact-table">

        <el-table-column :label="translate('License Key')" :width="200">
          <template #default="scope">
            <div class="fct-license-key-wrap flex flex-col">
              <span>
                <Badge :status="scope.row.status" :key="scope.row.id"/>
              </span>

              <span class="fct-license-key max-w-full pr-1">
                <span class="fct-license-key-inner pr-2">
                  <router-link class="link" :to="{ name: 'view_license', params: { license_id: scope.row?.id } }">
                     {{ scope.row.license_key }}
                  </router-link>
                </span>

                <el-tooltip :content="scope.row.license_key" placement="top"
                            popper-class="fct-tooltip">
                  <DynamicIcon class="cursor-pointer p-1 w-6 h-6 flex-none" name="Copy" @click="()=>{
                    Clipboard.copy(scope.row.license_key,{
                      'successMessage': translate('License Key copied to clipboard'),
                      'errorMessage': translate('Failed to copy License Key')
                    });
                  }"/>
                </el-tooltip>
              </span>
            </div>
          </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('product') !== -1" :width="200" :label="translate('Product')">
          <template #default="scope">
            <RouteCell class="link block hover:no-underline"
                       :to="{ name: 'view_license', params: { license_id: scope.row?.id } }">

              <ProductInfo
                  :product="scope.row.product"
                  :hideProductId="true"
                  :hideViewIcon="true"
                  :hideImage="true"
              />

            </RouteCell>
          </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('order_id') !== -1" :width="80" :label="translate('Order ID')">
          <template #default="scope">
            <RouteCell class="link order_id block hover:no-underline relative w-full"
                       :to="{ name: 'view_order', params: { order_id: scope.row.order_id } }">

              <!--                minus extra 16px of padding-->
              <router-link
                  class="link absolute left-1/2 top-1/2 translate-x-[calc(-50%-16px)] translate-y-[-50%]"
                  :to="{ name: 'view_order', params: { order_id: scope.row.order_id } }"
              >
                #{{ scope.row.order_id }}
              </router-link>
            </RouteCell>
          </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('customer') !== -1" :width="100" :label="translate('Customer')">
          <template #default="scope">
            <router-link
                class="link"
                :to="{
                    name: 'view_customer',
                    params: { customer_id: scope.row.customer_id },
                  }"
            >
              {{ scope.row?.customer?.full_name || translate('No Name') }}
            </router-link>
          </template>
        </el-table-column>

        <el-table-column :width="90" :label="translate('Activations')">
          <template #default="scope">
            <RouteCell class="link block hover:no-underline relative w-full"
                       :to="{ name: 'view_license', params: { license_id: scope.row?.id } }">
              {{ translateNumber(scope.row.activation_count) }} /
              {{ scope.row.limit == 0 ? "Unlimited" : scope.row.limit }}
            </RouteCell>

          </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('date') !== -1" :width="140" :label="translate('Date')">
          <template #default="scope">
            <RouteCell class="block hover:no-underline w-full"
                       :to="{ name: 'view_license', params: { license_id: scope.row?.id } }">
                <div>
                    <span>
                    {{ formatDate(scope.row.created_at) }}
                    </span>
                    <span class="text-gray-300 px-2">&#8211;</span>
                    <span v-if="scope.row.expiration_date">
                    {{ formatDate(scope.row.expiration_date) }}
                    </span>
                    <span v-else>{{ translate('Lifetime') }}</span>
                </div>
            </RouteCell>
          </template>
        </el-table-column>
        <template #empty>
          <Empty icon="Empty/ListView" :has-dark="true" :text="translate('No Licenses found based on your filter')"/>
        </template>
      </el-table>
    </div>

    <!-- mobile view -->
    <div class="fct_licenses_table_mobile">
      <div class="fct-table-mobile-wrap">
        <div v-for="(row, rowIndex) in formattedLicense" :key="rowIndex" class="fct-table-mobile-row">
          <div
              class="fct-table-mobile-header"
              @click="$router.push({ name: 'view_license', params: { license_id: row?.id } })"
          >
            <div class="fct-table-mobile-header-left">
              <div class="fct-table-status-col">
                <Badge :status="row.status" :key="row.id"/>
              </div>

              <div class="fct-table-activations-col">
                {{ row.activation_count }} / {{ row.limit == 0 ? "Unlimited" : row.limit }}
              </div>
            </div><!-- fct-table-mobile-header-left -->
          </div><!-- fct-table-mobile-header -->

          <div class="fct-table-mobile-body">
            <div class="fct-table-product-col" @click="$router.push({ name: 'view_license', params: { license_id: row?.id } })">
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

                <DynamicIcon class="cursor-pointer p-1 w-6 h-6" name="Copy" @click="()=>{
                    Clipboard.copy(row.license_key,{
                      'successMessage': translate('License Key copied to clipboard'),
                      'errorMessage': translate('Failed to copy License Key')
                    });
                }"/>
              </div>
            </div>
          </div><!-- fct-table-mobile-body -->

        </div>
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
import translate, {translateNumber} from "@/utils/translator/Translator";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import ProductInfo from "@/Bits/Components/Card/ProductInfo.vue";

export default {
  name: 'LicenseTable',
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
    formatDate,
    translateNumber,
    getProductName(product) {
      if (!product?.product?.post_title) {
        return "n/a";
      }
      return product?.product?.post_title;
    },
    getProductVariationName(product) {
      if (!product?.product_variant?.variation_title) {
        return "n/a";
      }
      return product?.product_variant?.variation_title;
    },
  }
}

</script>
