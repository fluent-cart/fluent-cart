<script setup>

import EmptyState from "@/Bits/Components/Layout/EmptyState.vue";
import {translateNumber} from "@/utils/translator/Translator";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import OrderBumpOfferCell from "@/Modules/OrderBump/parts/OrderBumpOfferCell.vue";
import OrderBumpPriceCell from "@/Modules/OrderBump/parts/OrderBumpPriceCell.vue";
import OrderBumpShowsOnCell from "@/Modules/OrderBump/parts/OrderBumpShowsOnCell.vue";
import OrderBumpDiscountCell from "@/Modules/OrderBump/parts/OrderBumpDiscountCell.vue";
import OrderBumpStatusSwitch from "@/Modules/OrderBump/parts/OrderBumpStatusSwitch.vue";
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
      })
      .finally(() => {
      });
}


// A bump that no longer fits the Active or Draft tab leaves it, so the tab loads again.
const handleStatusChanged = (row, status) => {
  row.status = status;

  const tab = props.orderBumpTable.getSelectedTab();
  if ((tab === 'active' || tab === 'draft') && tab !== status) {
    props.orderBumpTable.fetch();
  }
}

// A deleted product or variation cannot add anything at checkout, so its price, discount, priority and status mean nothing.
const isProductGone = row => !row.product_variant?.product;

const handleCommand = (command) => {
  if (command.action === 'delete') {
    $confirm(translate('Are you sure you want to delete "%s"?', command.orderBump.title), translate("Confirm Delete!"), {
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
  <fct-table :data="orderBumpTable.getTableData()" class="w-full compact-table">
    <fct-table-column :width="90" :label="translate('ID')">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{ name: 'view_order_bump', params: { id: scope.row.id } }">
          #{{ translateNumber(scope.row.id) }}
        </RouteCell>
      </template>
    </fct-table-column>
    <fct-table-column :min-width="160" :label="translate('Bump Title')">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{ name: 'view_order_bump', params: { id: scope.row.id } }">
          <div>
              <span>{{ scope.row.title }}</span>
          </div>
        </RouteCell>
      </template>
    </fct-table-column>

    <fct-table-column :min-width="380" :label="translate('Offer')">
      <template #default="scope">
        <OrderBumpOfferCell :bump="scope.row"/>
      </template>
    </fct-table-column>

    <fct-table-column :width="190" :label="translate('Price')">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{ name: 'view_order_bump', params: { id: scope.row.id } }">
          <OrderBumpPriceCell :bump="scope.row"/>
        </RouteCell>
      </template>
    </fct-table-column>

    <fct-table-column :width="170" :label="translate('Discount')">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{ name: 'view_order_bump', params: { id: scope.row.id } }">
          <span v-if="isProductGone(scope.row)" aria-hidden="true">—</span>
          <OrderBumpDiscountCell v-else :bump="scope.row"/>
        </RouteCell>
      </template>
    </fct-table-column>

    <fct-table-column :width="250" :label="translate('Shows on')">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{ name: 'view_order_bump', params: { id: scope.row.id } }">
          <span v-if="isProductGone(scope.row)" aria-hidden="true">—</span>
          <OrderBumpShowsOnCell v-else :bump="scope.row"/>
        </RouteCell>
      </template>
    </fct-table-column>

    <fct-table-column :width="90" align="center" :label="translate('Priority')">
      <template #default="scope">
        <RouteCell
            class="fct-order-bump-priority hover:no-underline"
            :to="{ name: 'view_order_bump', params: { id: scope.row.id } }">
          <span v-if="isProductGone(scope.row)" aria-hidden="true">—</span>
          <template v-else>{{ translateNumber(scope.row.priority) }}</template>
        </RouteCell>
      </template>
    </fct-table-column>

    <fct-table-column :width="110" align="center" :label="translate('Status')">
      <template #default="scope">
        <span v-if="isProductGone(scope.row)" aria-hidden="true">—</span>
        <OrderBumpStatusSwitch v-else :bump="scope.row" @changed="status => handleStatusChanged(scope.row, status)"/>
      </template>
    </fct-table-column>

    <fct-table-column :width="80" align="right" fixed="right">
      <template #default="scope">
        <fct-dropdown
            trigger="click"
            class="fct-more-option-wrap"
            :items="[{command: {action: 'delete', orderBump: scope.row}, label: translate('Delete'), icon: 'Delete', danger: true}]"
            @command="handleCommand"
            placement="bottom-end"
        >
          <span class="more-btn me-2">
            <DynamicIcon name="More"/>
          </span>
        </fct-dropdown>
      </template>
    </fct-table-column>

    <template #empty>
      <EmptyState icon="Empty/ListView" :text="translate('No order bumps found!')"/>
    </template>
  </fct-table>
</template>
