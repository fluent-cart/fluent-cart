<script setup>
import translate from "@/utils/translator/Translator";
import * as Card from "@/Bits/Components/Card/Card.js";
import Field from "@/Bits/Components/Fct/Field.vue";

</script>

<template>
  <Card.Body>

    <div class="grid grid-cols-1 gap-x-[15px] min-[1200px]:grid-cols-2">
        <Field :label="translate('From Name')">
          <fct-skeleton animated class="h-9 gap-2">
            <fct-skeleton variant="p" class="w-full h-4"/>
          </fct-skeleton>
          <div>
            <div class="form-note">
              <p>{{ translate('Name that will be used to send emails') }}</p>
            </div>
          </div>

        </Field>
        <Field :label="translate('From Email')">
          <fct-skeleton animated class="h-9">
            <fct-skeleton variant="p" class="w-full h-4"/>
          </fct-skeleton>
          <div>
            <div class="form-note">
              <p>{{
                  translate('Settings_Provide_Valid')
                }}</p>
            </div>
          </div>

        </Field>
        <Field :label="translate('Reply to name (Optional)')">
          <fct-skeleton animated class="h-9">
            <fct-skeleton variant="p" class="w-full h-4"/>
          </fct-skeleton>
          <div class="form-note">
            <p>{{ translate('Name that will be used for reply to attribute') }}</p>
          </div>

        </Field>
        <Field :label="translate('Reply to email (Optional)')">
          <fct-skeleton animated class="h-9">
            <fct-skeleton variant="p" class="w-full h-4"/>
          </fct-skeleton>
          <div>
            <div class="form-note">
              <p>{{
                  translate('Settings_Someone_Replies')
                }}</p>
            </div>
          </div>
        </Field>
    </div>

    <div>
        <div class="el-form-item__label">
          {{ translate('Email Footer') }}
        </div>

        <div class="h-[297px] w-full relative">
          <fct-skeleton animated :rows="7" />
        </div>
        <div class="mt-2 mb-2">
          <div class="form-note">
            <p>{{
                translate('Settings_Email_Footer')
              }}</p>
          </div>
        </div>

        <div
            class="fct-powered-by-email-footer-checkbox mb-4 pb-4 border-[1px] border-l-0 border-t-0 border-r-0 border-solid border-gray-outline dark:border-dark-400">
          <fct-skeleton animated class="h-5">
            <fct-skeleton variant="p" class="w-full h-4"/>
          </fct-skeleton>
        </div>

    </div>

    <div class="mb-[18px] grid grid-cols-1 gap-x-[15px] min-[1200px]:grid-cols-2">
        <div class="setting-html-wrapper">
          <span class="setting-label">{{ translate('Admin Email Address') }}</span>
          <div class="form-note"><p>
            {{ translate('FluentCart will send admin notification to this email address.') }}</p></div>
        </div>
        <div class="setting-fields-inner w-full">
          <fct-skeleton animated class="h-9">
            <fct-skeleton variant="p" class="w-full h-4"/>
          </fct-skeleton>
        </div>
    </div>
  </Card.Body>
</template>

<style scoped>

</style>
