<template>
  <UserCan permission="coupons/view">
    <div class="fct-all-coupons-page fct-layout-width">
      <PageHeading :title="translate('Coupons')">
        <template #action>
          <el-button
              tag="router-link"
              type="primary"
              :to="{
                name: 'add_or_edit_coupon'
              }"
          >
            {{ translate("Add Coupon") }}
          </el-button>
        </template>
      </PageHeading>

      <div class="fct-all-coupons-wrap">
        <TableWrapper :table="couponTable" :has-mobile-slot="true">
          <AllCouponsLoader v-if="couponTable.isLoading()" :couponTable="couponTable"
                            :next-page-count="couponTable.nextPageCount" />
          <div v-else>
            <el-table
                :data="couponTable.getTableData()"
                class="w-full compact-table full-compact"
            >
              <el-table-column :width="100" :label="translate('ID')">
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: scope.row.id },
                    }"
                  >
                    #{{ translateNumber(scope.row.id) }}
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="couponTable.isColumnVisible('title')"
                  :label="translate('Title')"
                  :width="220"
              >
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: scope.row.id, action: 'edit' },
                    }"
                  >
                    {{ scope.row.title }}
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Code')" :width="200">
                <template #default="scope">
                  <code class="copyable-content" @click="Clipboard.copy(scope.row.code);">
                    {{ scope.row.code }}
                  </code>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Amount')" :width="120">
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: scope.row.id, action: 'edit' },
                    }"
                  >
                    {{
                      scope.row.type !== "percentage"
                          ? formatNumber(scope.row.amount, true)
                          : scope.row.amount + "%"
                    }}
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Usage')" :width="150">
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: scope.row.id, action: 'edit' },
                    }"
                  >
                    <span>
                      {{ translateNumber(scope.row.use_count ?? 0) }}
                    </span>
                    <span class="px-1">/</span>
                    <span>
                      {{
                        scope.row.conditions.max_uses > 0
                            ? scope.row.conditions.max_uses
                            : translate("Unlimited")
                      }}
                    </span>
                  </RouteCell>
                </template>
              </el-table-column>
              <el-table-column
                  v-if="couponTable.isColumnVisible('stackable')"
                  :label="translate('Stackable')"
                  :width="120"
              >
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: scope.row.id, action: 'edit' },
                    }"
                  >
                    <span class="capitalize">
                      {{ scope.row.stackable }}
                    </span>
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Status')" :width="120">
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: scope.row.id, action: 'edit' },
                    }"
                  >
                    <Badge :status="scope.row.status">
                      {{
                        isExpired(scope.row.end_date)
                            ? translate("Expired")
                            : scope.row.status
                      }}
                    </Badge>
                    <el-tooltip
                        v-if="scope.row.status === 'scheduled'"
                        effect="dark"
                        placement="top"
                        popper-class="fct-label-hint-popover fct-tooltip"
                        :show-after="400"
                        :hide-after="0"
                    >
                      <template #content>
                        {{
                          /* translators: %s - date */
                          translate('Available On: %s', formatDate(scope.row.start_date, true))
                        }}
                      </template>
                      <DynamicIcon name="InformationFill" class="w-4 h-4 flex-none"/>
                    </el-tooltip>
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Expiry Date')" :width="120">
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: scope.row.id, action: 'edit' },
                    }"
                  >
                    <span>
                      <ConvertedTime
                          :date-time="scope.row.end_date"
                          v-if="!isNaN(Date.parse(scope.row.end_date))"
                          :with-time="true"
                      />
                      <template v-else>n/a</template>
                    </span>
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="couponTable.isColumnVisible('actions')"
                  :label="translate('Actions')"
                  :width="100"
                  align="right"
                  fixed="right"
              >
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
                        <el-dropdown-item class="item-link" v-if="scope.row.id">
                          <router-link
                              :to="{
                              name: 'add_or_edit_coupon',
                              params: {
                                coupon_id: scope.row.id,
                                action: 'edit',
                              },
                            }"
                          >
                            <DynamicIcon name="Edit"/>
                            {{ translate("Edit") }}
                          </router-link>
                        </el-dropdown-item>
                        <el-dropdown-item
                            :command="{
                            action: 'delete',
                            coupon: scope.row,
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
                <Empty
                    icon="Empty/Order"
                    has-dark
                    :text="translate('No coupons available at the moment.')"
                />
              </template>
            </el-table>
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
import {$confirm, formatDate} from "@/Bits/common";
import useCouponTable from "@/utils/table-new/CouponTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import {formatNumber} from "@/Bits/productService";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Badge from "@/Bits/Components/Badge.vue";
import dayjs from "dayjs";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import translate, {translateNumber} from "@/utils/translator/Translator";

import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import AllCouponsLoader from "@/Modules/Coupons/AllCouponsLoader.vue";
import Clipboard from "@/utils/Clipboard";
import CouponsTableMobile from "@/Modules/Coupons/CouponsTableMobile.vue";
import CouponsLoaderMobile from "@/Modules/Coupons/CouponsLoaderMobile.vue";

const couponTable = useCouponTable();

const show_on_checkout = ref(false);

const handleCommand = (command) => {
  /* translators: %s - coupon title */
  let deleteMessage = translate(
      "Are you sure you want to delete this %s coupon. This action is not recoverable",
      command.coupon.title
  );

  if (command.action === "delete") {


    $confirm(deleteMessage, translate("Confirm Delete!"), {
      confirmButtonText: translate("Yes, Delete!"),
      cancelButtonText: translate("Cancel"),
      type: "warning",
    })
        .then(() => {
          Rest.delete("coupons/" + command.coupon.id)
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
  const currentDate = dayjs().format("YYYY-MM-DD");
  const endDateFormatted = dayjs(endDate).format("YYYY-MM-DD");

  if (endDateFormatted === "Invalid date") {
    return false;
  }

  return endDateFormatted < currentDate;
};

onMounted(() => {
  fetchSettings();
});
</script>
