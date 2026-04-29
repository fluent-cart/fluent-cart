<script setup>
import translate from "@/utils/translator/Translator";
import * as Card from "@/Bits/Components/Card/Card.js";

</script>

<template>
  <Card.Body>

    <el-row :gutter="15">
      <el-col :lg="12">
        <el-form-item :label="translate('From Name')" label-position="top">
          <el-skeleton animated class="h-9 gap-2">
            <template #template>
              <el-skeleton-item variant="p" class="w-full h-4"/>
            </template>
          </el-skeleton>
          <br>
          <div>
            <div class="form-note">
              <p>{{ translate('Name that will be used to send emails') }}</p>
            </div>
          </div>

        </el-form-item>
      </el-col>
      <el-col :lg="12">
        <el-form-item :label="translate('From Email')" label-position="top">
          <el-skeleton animated class="h-9">
            <template #template>
              <el-skeleton-item variant="p" class="w-full h-4"/>
            </template>
          </el-skeleton>
          <div>
            <div class="form-note">
              <p>{{
                  translate(`Provide Valid Email Address email as per your domain/SMTP settings. This
email will be used to send emails like notifications`)
                }}</p>
            </div>
          </div>

        </el-form-item>
      </el-col>
    </el-row>

    <el-row :gutter="15">
      <el-col :lg="12">
        <el-form-item :label="translate('Reply to name (Optional)')" label-position="top">
          <el-skeleton animated class="h-9">
            <template #template>
              <el-skeleton-item variant="p" class="w-full h-4"/>
            </template>
          </el-skeleton>
          <div class="form-note">
            <p>{{ translate('Name that will be used for reply to attribute') }}</p>
          </div>

        </el-form-item>
      </el-col>
      <el-col :lg="12">
        <el-form-item :label="translate('Reply to email (Optional)')" label-position="top">
          <el-skeleton animated class="h-9">
            <template #template>
              <el-skeleton-item variant="p" class="w-full h-4"/>
            </template>
          </el-skeleton>
          <div>
            <div class="form-note">
              <p>{{
                  translate(`If someone replies to your notification email, this is where you would like to
receive it.`)
                }}</p>
            </div>
          </div>
        </el-form-item>
      </el-col>
    </el-row>

    <el-row :gutter="15">
      <el-col>
        <div class="el-form-item__label">
          {{ translate('Email Footer') }}
        </div>

        <div class="h-[297px] w-full relative">
          <el-skeleton animated :rows="7" />
        </div>
        <div class="mt-2 mb-2">
          <div class="form-note">
            <p>{{
                translate(`This email footer will be used for all emails sent from FluentCart, It's highly recommended to use your business name and address in the footer for
compliance.`)
              }}</p>
          </div>
        </div>

        <div
            class="fct-powered-by-email-footer-checkbox mb-4 pb-4 border-[1px] border-l-0 border-t-0 border-r-0 border-solid border-gray-outline dark:border-dark-400">
          <el-skeleton animated class="h-5">
            <template #template>
              <el-skeleton-item variant="p" class="w-full h-4"/>
            </template>
          </el-skeleton>
        </div>

      </el-col>
    </el-row>

    <el-row :gutter="15">
      <el-col>
        <el-form-item>

          <el-row :gutter="15" class="w-full">
            <el-col :lg="12">
              <div class="setting-html-wrapper">
                <span class="setting-label">{{ translate('Admin Email Address') }}</span>
                <div class="form-note"><p>
                  {{ translate('FluentCart will send admin notification to this email address.') }}</p></div>
              </div>
            </el-col>
            <el-col :lg="12">
              <div class="setting-fields-inner w-full">
                <el-skeleton animated class="h-9">
                  <template #template>
                    <el-skeleton-item variant="p" class="w-full h-4"/>
                  </template>
                </el-skeleton>
              </div>
            </el-col>
          </el-row>

        </el-form-item>
      </el-col>
    </el-row>
  </Card.Body>
</template>

<style scoped>

</style>
