<script setup>
import {onMounted} from "vue";

const props = defineProps({
  field: {
    type: Object,
  },
  value: {
    type: Object,
  },
  fieldKey: {
    type: String
  }
})

const generateRandomCouponCode = () => {
  const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
  let couponCode = "";
  for (let i = 0; i < 20; i++) {
    couponCode += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  props.value[props.fieldKey] = couponCode;
}

onMounted(() => {

})
</script>

<template>
  <el-form-item>
    <div class="flex gap-2 items-center justify-between w-full mb-2.5 -mt-2">
      <label class="setting-label !mb-0">{{$t('Code')}}</label>
        <span
            class="text-system-dark text-xs cursor-pointer hover:underline dark:text-gray-50"
            @click="generateRandomCouponCode"
        >
            {{ $t("Generate Random Code") }}
        </span>
    </div>
    <el-input
        v-model="value[fieldKey]"
        :placeholder="field.placeholder"
        maxlength="20"
        @input="value[fieldKey] = value[fieldKey].toUpperCase()"
    ></el-input>
    <div class="form-note">
      <p>
        {{
          $t("Customers must enter this code at checkout.")
        }}
      </p>
    </div>
  </el-form-item>
</template>

<style scoped>

</style>