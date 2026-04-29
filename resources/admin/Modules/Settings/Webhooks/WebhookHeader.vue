<template>
  <div class="fct-feed-header mb-5">
    <div class="flex items-center justify-between">
      <el-breadcrumb :separator-icon="ArrowRight" class="mb-0">
        <el-breadcrumb-item :to="{ path: '/settings/webhooks' }">
          {{ $t("Webhooks") }}
        </el-breadcrumb-item>
        <el-breadcrumb-item>
          <span class="capitalize">{{$t('Webhook Settings')}}</span>
        </el-breadcrumb-item>
      </el-breadcrumb>

      <el-switch
          v-model="settings.enabled"
          :inactive-text="$t('Enable This Feed')"
      />
    </div>
  </div>
</template>

<script>
import {ArrowRight} from "@element-plus/icons-vue";

export default {
  props: ['settings'],
  computed: {
    ArrowRight() {
      return ArrowRight
    }
  }

}
</script>

