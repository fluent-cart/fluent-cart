<script setup>

import Empty from "@/Bits/Components/Table/Empty.vue";
import translateNumber from "@/utils/translator/Translator";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {$confirm} from "@/Bits/common";
import Notify from "@/utils/Notify";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  orderBumpTable: {
    type: Object,
    required: true,
  }
});

const deleteOrderBump = (id) => {
  if (!id) {
    Notify.error(translate('Please select a order bump'));
    return;
  }

  Rest.delete('order_bump/' + id)
      .then(response => {
        Notify.success(response.message);
        props.orderBumpTable.fetch();
      })
      .catch((errors) => {
        console.log(errors);
      })
      .finally(() => {
      });
}


const handleCommand = (command) => {
  if (command.action === 'delete') {
    $confirm(translate('Are you sure you want to delete this order bump?'), translate("Confirm Delete!"), {
      confirmButtonText: translate("Yes, Delete!"),
      cancelButtonText: translate("Cancel"),
      type: "warning",
    })
        .then(() => {
          deleteOrderBump(command.orderBump.id);
        })
        .catch(() => {
        });

  }

}

</script>

<template>
  <el-table :data="orderBumpTable.getTableData()" class="w-full compact-table">
    <el-table-column :width="100" :label="translate('ID')">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{ name: 'view_order_bump', params: { id: scope.row.id } }">
          #{{ translateNumber(scope.row.id) }}
        </RouteCell>
      </template>
    </el-table-column>
    <el-table-column :label="translate('Title')">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{ name: 'view_order_bump', params: { id: scope.row.id } }">
          <div>
              <span>{{ scope.row.title }}</span>
          </div>
        </RouteCell>
      </template>
    </el-table-column>

      <el-table-column :label="translate('Product')">
          <template #default="scope">
              <RouteCell
                  class="hover:no-underline"
                  :to="{ name: 'view_order_bump', params: { id: scope.row.id } }"
              >
                <div>
                  <div class="mb-1">{{scope.row?.product_variant?.product?.post_title}}</div>
                  <div v-if="scope.row?.product_variant?.other_info?.billing_summary" class="text-system-mid dark:text-gray-300">
                    {{scope.row?.product_variant?.other_info?.billing_summary}}
                  </div>
                  <div v-else class="text-system-mid dark:text-gray-300">
                    {{formatNumber(scope.row?.product_variant?.item_price, true)}}
                  </div>
                </div>
              </RouteCell>
          </template>
      </el-table-column>

    <el-table-column :width="130" :label="translate('Discount')">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{ name: 'view_order_bump', params: { id: scope.row.id } }">
            <span v-if="scope.row.config.discount?.discount_type === 'fixed'"> {{formatNumber(scope.row.config.discount?.discount_amount * 100)}} </span> <span v-else> {{scope.row.config.discount?.discount_amount}}%</span>
        </RouteCell>
      </template>
    </el-table-column>

    <el-table-column :width="90" :label="translate('Status')">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{ name: 'view_order_bump', params: { id: scope.row.id } }">
          <Badge :status="scope.row.status" :hide-icon="true" :key="scope.row.id"/>
        </RouteCell>
      </template>
    </el-table-column>

    <el-table-column :width="80" align="right">
      <template #default="scope">
        <el-dropdown
            trigger="click"
            class="fct-more-option-wrap"
            popper-class="fct-dropdown"
            @command="handleCommand"
            placement="bottom-end"
        >
          <span class="more-btn mr-2">
            <DynamicIcon name="More"/>
          </span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item
                  :command="{
                            action: 'delete',
                            orderBump: scope.row
                          }"
                  class="item-destructive"
              >
                <DynamicIcon name="Delete"/>
                {{ translate("Delete") }}
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </template>
    </el-table-column>

    <template #empty>
      <Empty icon="Empty/ListView" :has-dark="true"
             :text="translate('No order bumps found!')"/>
    </template>
  </el-table>
</template>

<style scoped>

</style>
