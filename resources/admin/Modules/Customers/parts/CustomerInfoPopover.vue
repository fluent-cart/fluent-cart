<template>
  <div class="fct-popover-box">
    <el-popover
        placement="bottom-start"
        :width="280"
        trigger="click"
    >
      <div class="fct-popover-content fct-customer-info-popover">
        <ul class="fct-customer-info-list">
          <li>
              <span class="title">
                {{ customer?.full_name || translate('No Name') }}
              </span>
          </li>
          <li>
            <div class="dot-separator">
                <span>
                  <template v-if="customer.purchase_count > 0">
                    {{ translateNumber(customer.purchase_count) }}
                  </template>
                  {{ $n('Order', 'Orders', customer.purchase_count ?? 0, 'No Order') }}
                </span>

              <span>{{ customer.country_name }}</span>
            </div>
          </li>

          <li><div class="separator"></div></li>

          <li>
            <div class="text">
              <router-link :to="{ name: 'view_customer', params: { customer_id: customer?.id } }">
                {{ customer?.email }}
              </router-link>
            </div>
          </li>

          <li>
            <div class="text">
              {{ customer?.primary_billing_address?.phone }}
            </div>
          </li>
        </ul>

        <el-button
            size="small"
            tag="router-link"
            :to="{ name: 'view_customer', params: { customer_id: customer?.id } }"
        >
          {{ $t('View Customer') }}
        </el-button>
      </div>

      <template #reference>
        <div class="fct-customer-card fct-popover-box-action">
          <div class="fct-customer-name mr-4 truncate">
            {{ customer?.full_name || translate('No Name') }}
          </div>
          <div class="fct-popover-box-action-icon">
            <DynamicIcon name="ChevronDown"/>
          </div>
        </div>
      </template>
    </el-popover>

    <RouteCell
        class="hover:no-underline"
        :to="toRoute"
    >
      <span>&nbsp;</span>
    </RouteCell>
  </div>
</template>

<script setup>
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import translate, {translateNumber} from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

defineProps({
  customer: {
    type: Object,
    required: true
  },
  toRoute: {
    type: Object,
    required: true
  }
})

</script>
