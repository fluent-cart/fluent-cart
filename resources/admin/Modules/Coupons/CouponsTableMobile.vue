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
            <Badge :status="row.status" kind="coupon">
              {{isExpired(row.end_date) ? _x('Expired', 'Coupon status') : row.status }}
            </Badge>

            <fct-tooltip
                v-if="row.status === 'scheduled'"
                placement="top"
                popper-class="fct-label-hint-popover"
                :show-after="400"
                :hide-after="0"
            >
              <template #content>
                {{ translate('Available On: %s', formatDate(row.start_date, true)) }}
              </template>
              <DynamicIcon name="InformationFill" class="w-4 h-4 flex-none"/>
            </fct-tooltip>
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
          <fct-dropdown
              trigger="click"
              class="fct-more-option-wrap"
              :items="rowActions(row)"
              @command="(command) => handleCommand(command, row)"
              placement="bottom-end"
          >
            <span class="more-btn pr-0">
              <DynamicIcon name="More"/>
            </span>
          </fct-dropdown>
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
import translate, {_x} from "@/utils/translator/Translator";
import {$confirm, formatDate} from "@/Bits/common";
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import Rest from "@/utils/http/Rest";

dayjs.extend(utc);
import Notify from "@/utils/Notify";
import Clipboard from "@/utils/Clipboard";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import {formatNumber} from "@/Bits/productService";
import {useRouter} from "vue-router";

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

const router = useRouter();

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
    }).then(() => {
          Rest.delete("coupons/" + coupon.id)
              .then((response) => {
                Notify.success(response.message);
                props.table.fetch();
              })
              .catch((error) => {
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

