<template>
  <div class="fct-integration-setting-container fct-layout-width">
    <div class="single-page-header">
        <el-breadcrumb :separator-icon="ArrowRight">
          <el-breadcrumb-item  :to="{ name: 'integrations' }">
            {{ $t('Integrations')}}
          </el-breadcrumb-item>
          <el-breadcrumb-item>
            {{ Str.capitalize($route.params.integration_name) }}
          </el-breadcrumb-item>
        </el-breadcrumb>
      </div><!-- .single-page-header -->
      <div class="single-page-body">
         <Card.Container>
          <Card.Body>
              <div class="fct-integration-setting-wrap">
                <GeneralIntegrationSettings :settings_key="$route.params.integration_name" />
              </div>
            </Card.Body>
        </Card.Container>
      </div><!-- .single-page-body -->
  </div>
</template>

<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import GeneralIntegrationSettings from './GeneralIntegrationSettings.vue';
import Str from "@/utils/support/Str.js";
import {ArrowRight} from '@element-plus/icons-vue';
</script>

<script>
export default {
  name: 'IntegrationEditor'
}
</script>
