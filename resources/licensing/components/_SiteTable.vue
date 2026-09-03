<template>
  <UserCan permission="licenses/view">
    <div class="fct_sites_table">
      <el-table :data="sites" class="w-full compact-table">

        <el-table-column :label="translate('Site URL')" :min-width="200">
          <template #default="scope">
            <router-link class="link block hover:no-underline route-cell" :to="{ name: 'view_site', params: { site_id: scope.row.id } }">
              <div class="table-cell">
                <div>
                  <span>{{ scope.row.site_url }}</span>
                  <Badge v-if="scope.row.is_local" status="warning" size="small" :hide-icon="true" :text="translate('local')" style="margin-left: 8px;"/>
                </div>
              </div>
            </router-link>
          </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('products') !== -1" :min-width="180" :label="translate('Products')">
          <template #default="scope">
            <div class="table-cell" v-if="scope.row.products && scope.row.products.length">
              <div class="fct-popover-box">
                <el-popover placement="bottom-start" :width="300" trigger="click">
                  <div class="fct-popover-content">
                    <div class="fct-product-orders-items is-scroll">
                      <p v-for="(product, idx) in scope.row.products" :key="idx">
                        <span class="title">{{ product.name }}</span>
                        <span v-if="product.variation" class="variation-title">– {{ product.variation }}</span>
                      </p>
                    </div>
                  </div>
                  <template #reference>
                    <div class="fct-popover-box-action" tabindex="0" role="button" @keydown.enter.space.prevent="">
                      <template v-if="scope.row.products.length === 1">
                        <span class="block w-full truncate mr-4">{{ scope.row.products[0].name }}</span>
                      </template>
                      <template v-else>
                        <span class="block w-full truncate mr-4">{{ scope.row.products.length }} {{ translate('products') }}</span>
                      </template>
                      <div class="fct-popover-box-action-icon">
                        <DynamicIcon name="ChevronDown"/>
                      </div>
                    </div>
                  </template>
                </el-popover>
              </div>
            </div>
            <div class="table-cell" v-else>
              <span class="text-gray-300">—</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('active_licenses') !== -1" :width="130" :label="translate('Active Licenses')">
          <template #default="scope">
            <router-link class="link block hover:no-underline route-cell" :to="{ name: 'view_site', params: { site_id: scope.row.id } }">
              <div class="table-cell">
                {{ scope.row.active_licenses_count }}
              </div>
            </router-link>
          </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('customer') !== -1" :width="150" :label="translate('Customer')">
          <template #default="scope">
            <div class="table-cell">
              <div v-if="scope.row.customers && scope.row.customers.length">
                <span>{{ scope.row.customers.join(', ') }}</span>
              </div>
              <span v-else class="text-gray-300">—</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('last_activity') !== -1" :width="130" :label="translate('Last Activity')">
          <template #default="scope">
            <div class="table-cell">
              <span v-if="scope.row.last_activity">{{ formatDate(scope.row.last_activity) }}</span>
              <span v-else class="text-gray-300">—</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('created_at') !== -1" :width="130" :label="translate('Created At')">
          <template #default="scope">
            <div class="table-cell">
              {{ formatDate(scope.row.created_at) }}
            </div>
          </template>
        </el-table-column>

        <template #empty>
          <Empty icon="Empty/ListView" :has-dark="true" :text="translate('No activated sites found')"/>
        </template>
      </el-table>
    </div>
  </UserCan>
</template>

<script setup>
import Empty from "@/Bits/Components/Table/Empty.vue";
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {formatDate} from "@/Bits/common";
import translate from "@/utils/translator/Translator";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";

defineProps({
  sites: {
    type: Array,
    required: true
  },
  columns: {
    type: Array,
    default() {
      return ['products', 'customer', 'active_licenses', 'last_activity', 'created_at'];
    }
  }
});
</script>
