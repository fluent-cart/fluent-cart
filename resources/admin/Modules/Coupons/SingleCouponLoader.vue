<script setup>

import * as Card from "@/Bits/Components/Card/Card.js";
import {ArrowRight} from "@element-plus/icons-vue";
import translate from "@/utils/translator/Translator";
</script>

<template>
  <div class="fct-add-edit-coupon-wrapper fct-layout-width">
    <div class="single-page-header">
      <el-breadcrumb :separator-icon="ArrowRight">
        <el-breadcrumb-item>
          <fct-skeleton :animated="true">
            <fct-skeleton variant="p" class="w-[90px] h-4"/>
          </fct-skeleton>
        </el-breadcrumb-item>
        <el-breadcrumb-item>
          <fct-skeleton :animated="true">
            <fct-skeleton variant="p" class="w-[90px] h-4"/>
          </fct-skeleton>
        </el-breadcrumb-item>
        <el-breadcrumb-item>
          <fct-skeleton :animated="true">
            <fct-skeleton variant="p" class="w-[60px] h-4"/>
          </fct-skeleton>
        </el-breadcrumb-item>
      </el-breadcrumb>
    </div>


    <div class="single-page-body">
      <div class="fct-add-edit-coupon">
        <div class="fct-add-edit-coupon-main">

          <Card.Container>
            <Card.Header
                :title="translate('Basic Information')"
                border_bottom
                title_size="small"
            />

            <Card.Body>
              <fct-skeleton :animated="true" class="flex flex-col gap-3">
                <fct-skeleton variant="p" class="w-full h-4"/>
                <fct-skeleton variant="p" class="w-2/3 h-4"/>
                <fct-skeleton variant="p" class="w-1/3 h-4"/>
                <fct-skeleton variant="p" class="max-w-[400px] h-4"/>
                <fct-skeleton variant="p" class="w-full h-4"/>
                <fct-skeleton variant="p" class="w-2/3 h-4"/>
                <fct-skeleton variant="p" class="w-1/3 h-4"/>
              </fct-skeleton>
            </Card.Body>
          </Card.Container>

          <Card.Container>
            <Card.Header
                :title="translate('Discount Value')"
                border_bottom
                title_size="small"
            />
            <Card.Body>
              <fct-skeleton :animated="true" class="flex flex-col gap-3">
                <fct-skeleton variant="p" class="w-full h-4"/>
                <fct-skeleton variant="p" class="w-2/3 h-4"/>
                <fct-skeleton variant="p" class="w-1/3 h-4"/>
                <fct-skeleton variant="p" class="max-w-[400px] h-4"/>
                <fct-skeleton variant="p" class="w-full h-4"/>
                <fct-skeleton variant="p" class="w-2/3 h-4"/>
                <fct-skeleton variant="p" class="w-1/3 h-4"/>
              </fct-skeleton>
            </Card.Body>
          </Card.Container>

          <Card.Container>
            <Card.Header
                :title="translate('Schedule')"
                border_bottom
                title_size="small"
            />
            <Card.Body>
              <fct-skeleton :animated="true" class="flex flex-col gap-3">
                <fct-skeleton variant="p" class="w-full h-4"/>
                <fct-skeleton variant="p" class="w-2/3 h-4"/>
                <fct-skeleton variant="p" class="w-1/3 h-4"/>
                <fct-skeleton variant="p" class="max-w-[400px] h-4"/>
                <fct-skeleton variant="p" class="w-full h-4"/>
                <fct-skeleton variant="p" class="w-2/3 h-4"/>
                <fct-skeleton variant="p" class="w-1/3 h-4"/>
              </fct-skeleton>
            </Card.Body>
          </Card.Container>

          <Card.Container>
            <Card.Header
                :title="translate('Maximum Discount Uses')"
                border_bottom
                title_size="small"
            />
            <Card.Body>
              <fct-skeleton :animated="true" class="flex flex-col gap-3">
                <fct-skeleton variant="p" class="w-full h-4"/>
                <fct-skeleton variant="p" class="w-2/3 h-4"/>
                <fct-skeleton variant="p" class="w-1/3 h-4"/>
              </fct-skeleton>
            </Card.Body>
          </Card.Container>

          <Card.Container>
            <Card.Header
                :title="translate('Additional Information')"
                border_bottom
                title_size="small"
            />
            <Card.Body>
              <fct-skeleton :animated="true" class="flex flex-col gap-3">
                <fct-skeleton variant="p" class="w-full h-4"/>
                <fct-skeleton variant="p" class="w-2/3 h-4"/>
                <fct-skeleton variant="p" class="w-1/3 h-4"/>
                <fct-skeleton variant="p" class="max-w-[400px] h-4"/>
                <fct-skeleton variant="p" class="w-full h-4"/>
                <fct-skeleton variant="p" class="w-2/3 h-4"/>
                <fct-skeleton variant="p" class="w-1/3 h-4"/>
                <fct-skeleton variant="p" class="max-w-[400px] h-4"/>
                <fct-skeleton variant="p" class="w-full h-4"/>
                <fct-skeleton variant="p" class="w-2/3 h-4"/>
                <fct-skeleton variant="p" class="w-1/3 h-4"/>
              </fct-skeleton>
            </Card.Body>
          </Card.Container>
        </div>

        <div class="fct-add-edit-coupon-aside">
          <div class="fct-admin-sidebar">
            <Card.Container>
              <Card.Header
                  :title="translate('Summary')"
                  border_bottom
                  title_size="small"
              />
              <Card.Body>
                <fct-skeleton :animated="true" class="flex flex-col gap-3">
                  <fct-skeleton variant="p" class="w-full h-4"/>
                  <fct-skeleton variant="p" class="w-2/3 h-4"/>
                  <fct-skeleton variant="p" class="w-1/3 h-4"/>
                </fct-skeleton>
              </Card.Body>
            </Card.Container>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

