<template>
  <div class="fct-table-mobile-wrap fct-coupons-table-mobile-wrap">
    <div
        v-for="(row, rowIndex) in table.getTableData()"
        :key="row.id"
        class="fct-table-mobile-row"
    >
      <div class="fct-table-mobile-header">
        <div class="fct-table-mobile-header-left">
          <div class="fct-table-id-col">
            <router-link :to="{
                      name: 'add_or_edit_coupon',
                      params: { coupon_id: row.id },
                    }" class="id">
              #{{ row.id }}
            </router-link>
          </div><!-- fct-table-id-col -->

          <div class="fct-table-status-col">
            <Badge :status="row.status">
              {{isExpired(row.end_date) ? translate("Expired") : row.status }}
            </Badge>

            <el-tooltip
                v-if="row.status === 'scheduled'"
                effect="dark"
                placement="top"
                popper-class="fct-label-hint-popover fct-tooltip"
                :show-after="400"
                :hide-after="0"
            >
              <template #content>
                {{ translate('Available On: %s', formatDate(row.start_date, true)) }}
              </template>
              <DynamicIcon name="InformationFill" class="w-4 h-4 flex-none"/>
            </el-tooltip>
          </div><!-- fct-table-status-col -->

          <div class="fct-table-usage-col" @click="$router.push({ name: 'add_or_edit_coupon', params: { coupon_id: row.id } })">
            <div class="title">{{translate('Usage:')}}</div>
            <div class="value">
              {{ row.use_count ?? 0 }}
              <span>/</span>
              {{row.conditions.max_uses > 0 ? row.conditions.max_uses : translate("Unlimited") }}
            </div>
          </div><!-- fct-table-usage-col -->
        </div>

        <div class="fct-table-action-col" v-if="table.isColumnVisible('actions')">
          <el-dropdown
              trigger="click"
              class="fct-more-option-wrap"
              popper-class="fct-dropdown"
              @command="handleCommand"
              placement="bottom-end"
          >
            <span class="more-btn pr-0">
              <DynamicIcon name="More"/>
            </span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item class="item-link" v-if="row.id">
                  <router-link
                      :to="{
                        name: 'add_or_edit_coupon',
                        params: {
                          coupon_id: row.id,
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
                      coupon: row,
                    }"
                    class="item-destructive"
                >
                  <DynamicIcon name="Delete"/>
                  {{ translate("Delete") }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>


      </div><!-- fct-table-mobile-header -->

      <div class="fct-table-mobile-body">
        <div class="fct-table-coupon-code-wrapper-col" @click="$router.push({ name: 'add_or_edit_coupon', params: { coupon_id: row.id } })">
          <div class="fct-table-coupon-title-col" v-if="table.isColumnVisible('title')">
            {{ row.title }}
          </div><!-- fct-table-title-col -->

          <div class="fct-table-coupon-code-col">
            <code class="copyable-content" @click="Clipboard.copy(row.code);">
              {{ row.code }}
            </code>
          </div>
        </div>
      </div><!-- fct-table-mobile-body -->

      <div class="fct-table-mobile-footer">
        <div class="fct-table-mobile-footer-row items-start" @click="$router.push({ name: 'add_or_edit_coupon', params: { coupon_id: row.id } })">
          <div class="fct-table-date-col grid">
            <div class="title">
              {{ translate('Expiry Date') }}
            </div>

            <div class="value">
              <ConvertedTime
                  :date-time="row.end_date"
                  v-if="!isNaN(Date.parse(row.end_date))"
                  :with-time="true"
              />
              <template v-else>n/a</template>
            </div>
          </div><!-- fct-table-date-col -->
          <div class="fct-table-stackable-col" v-if="table.isColumnVisible('stackable')">
            <div class="title">
              {{ translate('Stackable') }}
            </div>
            <div class="value">
              {{ row.stackable }}
            </div>
          </div><!-- fct-table-stackable-col -->

          <div class="fct-table-amount-col">
            <div class="title">
              {{ translate('Amount') }}
            </div>
            <div class="value">
              {{  row.type !== "percentage" ? formatNumber(row.amount, true) : row.amount + "%" }}
            </div>
          </div><!-- fct-table-amount-col -->
        </div>
      </div><!-- fct-table-mobile-footer -->

    </div>
  </div>
</template>

<script setup>
import translate from "@/utils/translator/Translator";
import {$confirm, formatDate} from "@/Bits/common";
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import dayjs from "dayjs";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import Clipboard from "@/utils/Clipboard";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import {formatNumber} from "@/Bits/productService";

const props = defineProps({
  table: {
    type: Object,
    required: true,
  },
  emptyText: {
    type: String,
    default: translate('No customers found.')
  }
});


const isExpired = (endDate) => {
  const currentDate = dayjs().format("YYYY-MM-DD");
  const endDateFormatted = dayjs(endDate).format("YYYY-MM-DD");

  if (endDateFormatted === "Invalid date") {
    return false;
  }

  return endDateFormatted < currentDate;
};

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
    }).then(() => {
          Rest.delete("coupons/" + command.coupon.id)
              .then((response) => {
                Notify.success(response.message);
                props.table.fetch();
              })
              .catch((error) => {
                console.log(error, 'err')
                Notify.error(error);
              })
              .finally(() => {
              });
        })
        .catch(() => {
        });
  }
};

</script>

