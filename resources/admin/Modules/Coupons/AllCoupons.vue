<template>
  <UserCan permission="coupons/view">
    <div class="fct-all-coupons-page fct-layout-width">
      <PageHeading :title="translate('Coupons')">
        <template #action>
          <fct-button
              type="primary"
              :to="{
                name: 'add_or_edit_coupon'
              }"
          >
            {{ translate("Add Coupon") }}
          </fct-button>
        </template>
      </PageHeading>

      <div class="fct-all-coupons-wrap">
        <TableWrapper :table="couponTable" :has-mobile-slot="true">
          <AllCouponsLoader v-if="couponTable.isLoading()" :couponTable="couponTable"
                            :next-page-count="couponTable.nextPageCount" />
          <div v-else>
            <fct-table
                :data="couponTable.getTableData()"
                class="w-full compact-table full-compact"
            >
              <fct-table-column :width="100" :label="translate('ID')">
                <template #default="{ row }">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: row.id },
                    }"
                  >
                    #{{ translateNumber(row.id) }}
                  </RouteCell>
                </template>
              </fct-table-column>

              <fct-table-column
                  v-if="couponTable.isColumnVisible('title')"
                  :label="translate('Title')"
                  :width="220"
              >
                <template #default="{ row }">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: row.id },
                    }"
                  >
                    {{ row.title }}
                  </RouteCell>
                </template>
              </fct-table-column>

              <fct-table-column :label="translate('Code')" :width="200">
                <template #default="{ row }">
                  <code class="copyable-content" @click="Clipboard.copy(row.code);">
                    {{ row.code }}
                  </code>
                </template>
              </fct-table-column>

              <fct-table-column :label="translate('Amount')" :width="120">
                <template #default="{ row }">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: row.id },
                    }"
                  >
                    {{
                      row.type !== "percentage"
                          ? formatNumber(row.amount, true)
                          : row.amount + "%"
                    }}
                  </RouteCell>
                </template>
              </fct-table-column>

              <fct-table-column :label="translate('Usage')" :width="150">
                <template #default="{ row }">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: row.id },
                    }"
                  >
                    <span>
                      {{ translateNumber(row.use_count ?? 0) }}
                    </span>
                    <span class="px-1">/</span>
                    <span>
                      {{
                        row.conditions.max_uses > 0
                            ? row.conditions.max_uses
                            : translate("Unlimited")
                      }}
                    </span>
                  </RouteCell>
                </template>
              </fct-table-column>
              <fct-table-column
                  v-if="couponTable.isColumnVisible('stackable')"
                  :label="translate('Stackable')"
                  :width="120"
              >
                <template #default="{ row }">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: row.id },
                    }"
                  >
                    <span class="capitalize">
                      {{ row.stackable }}
                    </span>
                  </RouteCell>
                </template>
              </fct-table-column>

              <fct-table-column :label="translate('Status')" :width="120">
                <template #default="{ row }">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: row.id },
                    }"
                  >
                    <Badge :status="row.status" kind="coupon">
                      {{
                        isExpired(row.end_date)
                            ? _x('Expired', 'Coupon status')
                            : row.status
                      }}
                    </Badge>
                    <fct-tooltip
                        v-if="row.status === 'scheduled'"
                        placement="top"
                        popper-class="fct-label-hint-popover"
                        :show-after="400"
                        :hide-after="0"
                    >
                      <template #content>
                        {{
                          /* translators: %s - date */
                          translate('Available On: %s', formatDate(row.start_date, true))
                        }}
                      </template>
                      <DynamicIcon name="InformationFill" class="w-4 h-4 flex-none"/>
                    </fct-tooltip>
                  </RouteCell>
                </template>
              </fct-table-column>

              <fct-table-column :label="translate('Expiry Date')" :width="120">
                <template #default="{ row }">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: row.id },
                    }"
                  >
                    <span>
                      <ConvertedTime
                          :date-time="row.end_date"
                          v-if="!isNaN(Date.parse(row.end_date))"
                          :with-time="true"
                      />
                      <template v-else>n/a</template>
                    </span>
                  </RouteCell>
                </template>
              </fct-table-column>

              <fct-table-column
                  v-if="couponTable.isColumnVisible('actions')"
                  :label="translate('Actions')"
                  :width="100"
                  align="right"
                  fixed="right"
              >
                <template #default="{ row }">
                  <fct-dropdown
                      trigger="click"
                      class="fct-more-option-wrap"
                      :items="rowActions(row)"
                      @command="(command) => handleCommand(command, row)"
                      placement="bottom-end"
                  >
                    <span class="more-btn me-2">
                      <DynamicIcon name="More"/>
                    </span>
                  </fct-dropdown>
                </template>
              </fct-table-column>

              <template #empty>
                <Empty
                    icon="Empty/Order"
                    has-dark
                    :text="translate('No coupons available at the moment.')"
                />
              </template>
            </fct-table>
          </div>

          <template #mobile>
            <CouponsLoaderMobile v-if="couponTable.isLoading()"/>
            <CouponsTableMobile v-if="!couponTable.isLoading()" :table="couponTable"/>
          </template>
        </TableWrapper>
      </div>
    </div>
  </UserCan>
</template>

<script setup>
import {getCurrentInstance, onMounted, ref} from "vue";
import {useRouter} from "vue-router";
import {$confirm, formatDate} from "@/Bits/common";
import useCouponTable from "@/utils/table-new/CouponTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import {formatNumber} from "@/Bits/productService";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Badge from "@/Bits/Components/Badge.vue";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";

dayjs.extend(utc);
import translate, {_x, translateNumber} from "@/utils/translator/Translator";

import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import AllCouponsLoader from "@/Modules/Coupons/AllCouponsLoader.vue";
import Clipboard from "@/utils/Clipboard";
import CouponsTableMobile from "@/Modules/Coupons/CouponsTableMobile.vue";
import CouponsLoaderMobile from "@/Modules/Coupons/CouponsLoaderMobile.vue";

const couponTable = useCouponTable();
const router = useRouter();

const show_on_checkout = ref(false);

const rowActions = (row) => [
  row.id && {command: 'edit', label: translate('Edit'), icon: 'Edit', href: router.resolve({name: 'add_or_edit_coupon', params: {coupon_id: row.id}}).href},
  {command: 'delete', label: translate('Delete'), icon: 'Delete', danger: true},
].filter(Boolean);

const handleCommand = (command, coupon) => {
  /* translators: %s - coupon title */
  let deleteMessage = translate(
      "Are you sure you want to delete this %s coupon. This action is not recoverable",
      coupon.title
  );

  if (command === "delete") {


    $confirm(deleteMessage, translate("Confirm Delete!"), {
      confirmButtonText: translate("Yes, Delete!"),
      cancelButtonText: translate("Cancel"),
      type: "warning",
    })
        .then(() => {
          Rest.delete("coupons/" + coupon.id)
              .then((response) => {
                Notify.success(response.message);
                couponTable.fetch();
                //self.fetchCoupons();
              })
              .catch((error) => {
                if (error.status_code == '422') {
                  Notify.validationErrors(error);
                } else {
                  Notify.error(error.data?.message);
                }
              })
              .finally(() => {
              });
        })
        .catch(() => {
        });
  }
};

const fetchSettings = () => {
  Rest.get("coupons/getSettings")
      .then((response) => {
        show_on_checkout.value = !!parseInt(response.show_on_checkout);
      })
      .catch((error) => {
        if (error.status_code == '422') {
          Notify.validationErrors(error);
        } else {
          Notify.error(error.data?.message);
        }
      })
      .finally(() => {
      });
};

const isExpired = (endDate) => {
  if (!endDate || endDate === '0000-00-00 00:00:00') {
    return false;
  }

  const end = dayjs.utc(endDate).local();

  if (!end.isValid()) {
    return false;
  }

  return end.isBefore(dayjs());
};

onMounted(() => {
  fetchSettings();
});
</script>
