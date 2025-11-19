<script setup>
import Card from "@/Bits/Components/Card/Card.vue";
import CardHeader from "@/Bits/Components/Card/CardHeader.vue";
import CardBody from "@/Bits/Components/Card/CardBody.vue";
import {computed, getCurrentInstance, onMounted, ref} from "vue";
import { useRoute, useRouter } from "vue-router";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import ValidationError from "@/Bits/Components/Form/Error/ValidationError.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { useSaveShortcut } from "@/mixin/saveButtonShortcutMixin.js";
import { Back } from "@element-plus/icons-vue";
import Rest from "@/utils/http/Rest";

const selfRef = getCurrentInstance().ctx;
const wpEditor = ref();
const loading = ref(true);
const notification = ref({});
const shortCodes = ref({});
const emailShortCodes = ref([
  { label: "Admin Email", value: "{wp.admin_email}" },
  { label: "Customer Email", value: "{order.customer.email}" },
  { label: "User Email", value: "{user.user_email}" },
]);

const fromEmailShortCodes = ref([
  { label: "Admin Email", value: "{wp.admin_email}" },
]);

const buttons = ref({});
const templates = ref({});
const router = useRouter();
const route = useRoute();
const availableEvents = ref([
  // Define event options
  { label: "After Order Created", value: "fluent_cart/order_created" },
  { label: "After Order Updated", value: "fluent_cart/order_updated" },
  { label: "After Payment Paid", value: "fluent_cart/order_paid" },
  { label: "After User Created", value: "fluent_cart/user_created" },
  { label: "After Refund made", value: "fluent_cart/order_refunded" },
]);

const validationErrors = ref({});
const isModalVisible = ref(false);
const selectedToEmail = ref([]);
const selectedFromEmail = ref([]);

const selectedFromEmailShortcode = ref('{wp.admin_email}');
const maxVisibleTags = ref(3);
const showHiddenTags = ref(false);

const htmlContent = ref();

let editor = null;

const buttonVal = ref("");
const templateVal = ref("");

const saveShortcut = useSaveShortcut();
saveShortcut.onSave(() => {
  updateNotification(event);
});

const clearValidationError = (fieldKey) => {
  if (validationErrors.value && validationErrors.value.hasOwnProperty(fieldKey)) {
    delete validationErrors.value[fieldKey];
  }
}

const getTemplate = (selectedTemplate) => {
  const template = selectedTemplate;
  Rest.post("email-notification/get-template", { template })
    .then((response) => {
      notification.value.contents = response.data.content;
      window.tinymce?.activeEditor?.setContent(notification.value.contents);
      //will change later
      // wpEditor.value?.setContent(notification.value.content);
    })
    .catch((error) => {})
    .finally(() => {
      loading.value = false;
    });
};

const addButton = (html) => {
  wpEditor.value?.setContent(html);
};

const getNotification = () => {
  loading.value = true;
  Rest.get("email-notification/" + route.params.name)
    .then((response) => {
      templates.value = response.data.email_templates;
      notification.value = response.data.notification;

      const template = notification.value.template;
      notification.value.contents = template.value;

      shortCodes.value = response.data.shortcodes;
      buttons.value = response.data.buttons;
      buttons.value = Object.keys(response.data.buttons).map((key) => ({
        [key]: response.data.buttons[key],
      }));

      selectedToEmail.value = notification.value.to;
      window.tinymce?.activeEditor?.setContent(notification.value.contents);
    })
    .catch((error) => {})
    .finally(() => {
      loading.value = false;
    });
};

const updateNotification = (event) => {
  event.preventDefault();
  validationErrors.value = {};
  loading.value = true;
  Rest.put("email-notification/" + route.params.name, notification.value)
    .then((response) => {
      selfRef.handleSuccess(response);
    })
    .catch((error) => {
      validationErrors.value = error;
      selfRef.handleError(error);
    })
    .finally(() => {
      loading.value = false;
    });
};

const showPreviewModal = ref(false);
let previewContent = "";

const setPreviewContent = () => {
  if (hasWpEditor.value) {
    previewContent = wp.editor.getContent(props.editor_id);
    return;
  }
  previewContent = plainContent.value;
};

onMounted(() => {
  getNotification();
});

const onEditorReady = (editorInstance) => {
  editor = editorInstance;
};

const selectToEmail = () => {
  notification.value.to = selectedToEmail.value;
};

const selectFromEmail = () => {
  notification.value.from = selectedFromEmail.value;
};

const showPreview = () => {
  isModalVisible.value = true;
  htmlContent.value = notification.value.contents;
};
const handleFromEmailShortcode = (command) => {
  notification.value.from = command.data.value;
  selectedFromEmailShortcode.value = command.data.value;
}
const handleToEmailShortcode = (command) => {
  const emailToAdd = command.data;
  const emailIndex = selectedToEmail.value.indexOf(emailToAdd.value);

  if (emailIndex === -1) {
    // Email not in the list, so add it
    selectedToEmail.value.push(emailToAdd.value);
  } else {
    // Email already in the list, so remove it
    selectedToEmail.value.splice(emailIndex, 1);
  }

  // Update the notification with the values from selectedToEmail
  notification.value.to = selectedToEmail.value;
}
const addTag = (event) => {
  // Prevent the default form submission or button click behavior
  event.preventDefault();

  // Get the input field element
  const inputField = selfRef.$refs.inputField;

  // Get the trimmed value from the input field
  const newTag = inputField.value.trim();

  // Check if the input field is not empty and the tag is not already in the list
  if (newTag && !selectedToEmail.value.includes(newTag)) {
    // Add the new tag to the `selectedToEmail` array
    selectedToEmail.value.push(newTag);

    // Clear the input field after adding the tag
    inputField.value = '';

    // Adjust the input field width to fit the new content
    adjustInputWidth();

    // Update the notification with the updated list of selected tags
    notification.value.to = selectedToEmail.value;
  }
}

const removeSelectedEmail = (index) => {
  // Remove the email at the specified index
  selectedToEmail.value.splice(index, 1);

  // Update the notification with the current selection
  notification.value.to = selectedToEmail.value;
}

const removeTag = (event) => {
  // Get the input field element
  const inputField = selfRef.$refs.inputField;

  // Check if the key pressed is either Backspace or Delete
  if (event.key === 'Backspace' || event.key === 'Delete') {
    // If the input field is empty and there are tags in the list
    if (inputField.value === '' && selectedToEmail.value.length > 0) {
      // Get the index of the last tag in the list
      const lastIndex = selectedToEmail.value.length - 1;

      // Remove the last tag using the removeSelectedEmail method
      removeSelectedEmail(lastIndex);

      // Show hidden tags if there are any
      showHiddenTags.value = true;

      // Adjust the input width to fit its content
      adjustInputWidth();

      // Prevent the default behavior of the Backspace or Delete key
      event.preventDefault();
    }
  }
}

const isActiveEmail = (email) => {
  // Check if the given email is included in the `selectedToEmail` array
  return selectedToEmail.value.includes(email);
};

const focusInput = ()  =>{
  // Check if the click event target is inside a tag element
  const isInsideTag = event.target.closest('.tag');

  // Check if the click event target is inside a button element
  const isInsideButton = event.target.closest('button');

  // If the click is not inside a tag or button, focus the input field
  if (!isInsideTag && !isInsideButton) {
    selfRef.$refs.inputField.focus();
  }
}
const adjustInputWidth = () => {
  // Get the input field element
  const inputField = selfRef.$refs.inputField;

  if (inputField) {
    // Create a temporary span element to measure the text width
    const span = document.createElement('span');

    // Set styles to ensure accurate measurement
    span.style.visibility = 'hidden'; // Hide the span element
    span.style.whiteSpace = 'pre'; // Preserve whitespace formatting
    span.style.font = getComputedStyle(inputField).font; // Match the font style of the input field

    // Set the text content of the span to the current input value or placeholder
    span.textContent = inputField.value || inputField.placeholder;

    // Append the span to the body for measurement
    document.body.appendChild(span);

    // Adjust the input field width based on the span's width
    inputField.style.width = `${span.offsetWidth + 4}px`; // Add 4 extra space for padding/border

    // Remove the temporary span from the body
    document.body.removeChild(span);
  }
}

const visibleTags = computed(() => {
  // Get tags that are within the visible limit.
  return selectedToEmail.value.slice(0, maxVisibleTags.value);
});

const hiddenTags = computed(() => {
  // Get tags that exceed the visible limit.
  return selectedToEmail.value.slice(maxVisibleTags.value);
});

const hiddenTagsCount = computed(() => {
  // Count the number of hidden tags.
  return hiddenTags.value.length;
});

const toggleShowHiddenTags = () => {
  // Toggle visibility of hidden tags.
  showHiddenTags.value = !showHiddenTags.value;
};
</script>

<template>
  <Card>
    <CardHeader
      title_size="small"
      border_bottom
    >
      <template #title>
        <h2 class="fct-card-header-title is-small">
          <el-button size="small" plain @click="$router.push({name: 'email_notifications'})">
            <el-icon><Back /></el-icon>
            Back
          </el-button>
          {{ notification.title ? notification.title : 'Edit Notification' }}
        </h2>
      </template>

      <template #action>
        <el-switch
          active-value="yes"
          inactive-value="no"
          v-model="notification.enabled"
          :active-text="
            notification.enabled == 'yes' ? $t('Enabled') : $t('Enable')
          "
        >
        </el-switch>
      </template>
    </CardHeader>
    <CardBody>
      <el-dialog v-model="isModalVisible" :title="$t('Preview')">
        <div v-html="htmlContent"></div>
      </el-dialog>
      <el-form label-position="top" require-asterisk-position="right">
        <el-form-item :label="$t('Notification Name')" required>
          <el-input
            v-model="notification.title"
            @focus="clearValidationError('title')"
          />
          <validation-error
            :validation-errors="validationErrors?.title?.required"
          />
        </el-form-item>

        <el-form-item :label="$t('Select Notification Event')" required>
          <el-select
            :multiple="true"
            v-model="notification.events"
            :placeholder="$t('Select Events')"
            @focus="clearValidationError('events')"
            clearable
          >
            <el-option
              v-for="option in availableEvents"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            ></el-option>
          </el-select>
          <validation-error
            :validation-errors="validationErrors?.events?.required"
          />
        </el-form-item>

        <el-row :gutter="15">
          <el-col :lg="12">
            <el-form-item :label="$t('Mail From:')" required>
              <div class="fct-input-group w-full">
                <el-dropdown trigger="click" @command="handleFromEmailShortcode" :popper-class="`fct-dropdown ${fromEmailShortCodes.length > 1 ? 'fct-dropdown-size-md' : ''}`">
                  <el-button type="info" plain>
                    {{$t('Select')}}
                    <DynamicIcon name="ChevronDown" />
                  </el-button>
                  <template #dropdown>
                    <el-dropdown-menu>
                      <template v-for="option in fromEmailShortCodes">
                        <el-dropdown-item
                            :command="{
                              data: option
                            }"
                            :class="{
                              active: fromEmailShortCodes.length > 1 && selectedFromEmailShortcode === option.value,
                            }"
                        >
                          {{option.label}}
                        </el-dropdown-item>
                      </template>
                    </el-dropdown-menu>
                  </template>
                </el-dropdown>

                <el-input v-model="notification.from" @focus="clearValidationError('from')" clearable />
              </div><!-- .fct-input-group -->

<!--              <el-input-->
<!--                v-model="notification.from"-->
<!--                @focus="clearValidationError('from')"-->
<!--              >-->
<!--                <template #append>-->
<!--                  <el-select-->
<!--                    v-model="selectedFromEmail"-->
<!--                    @change="selectFromEmail()"-->
<!--                    clearable-->
<!--                  >-->
<!--                    <el-option-->
<!--                      v-for="option in fromEmailShortCodes"-->
<!--                      :key="option.value"-->
<!--                      :label="option.label"-->
<!--                      :value="option.value"-->
<!--                    />-->
<!--                  </el-select>-->
<!--                </template>-->
<!--              </el-input>-->
              <validation-error
                :validation-errors="validationErrors?.from?.required"
              />
            </el-form-item>
          </el-col>
          <el-col :lg="12">
            <el-form-item :label="$t('Send Mail To:')" required>
              <div class="fct-input-group w-full">
                <el-dropdown trigger="click" @command="handleToEmailShortcode" :popper-class="`fct-dropdown ${emailShortCodes.length > 1 ? 'fct-dropdown-size-md' : ''}`">
                  <el-button type="info" plain>
                    {{$t('Select')}}
                    <DynamicIcon name="ChevronDown" />
                  </el-button>
                  <template #dropdown>
                    <el-dropdown-menu>
                      <template v-for="option in emailShortCodes">
                        <el-dropdown-item
                            :command="{
                              data: option
                            }"
                            :class="{ 'active': isActiveEmail(option.value) }"
                        >
                          {{option.label}}
                        </el-dropdown-item>
                      </template>
                    </el-dropdown-menu>
                  </template>
                </el-dropdown>

                <div class="selected-tags" @click="focusInput">
                  <span v-for="(selectedEmail, index) in visibleTags" :key="index" class="tag">
                    {{ selectedEmail }}
                    <button @click.prevent="removeSelectedEmail(index)">
                      <DynamicIcon name="Cross"/>
                    </button>
                  </span>

                  <!-- Display hidden tags if toggled -->
                  <template v-if="showHiddenTags">
                    <span v-for="(email, index) in hiddenTags" :key="index" class="tag">
                      {{ email }}
                      <button @click.prevent="removeSelectedEmail(index + maxVisibleTags)">
                        <DynamicIcon name="Cross"/>
                      </button>
                    </span>
                  </template>

                  <input
                      ref="inputField"
                      type="text"
                      @input="adjustInputWidth"
                      @keydown.enter="addTag"
                      @keydown.delete="removeTag"
                  />

                  <!-- Display hidden tags count or "more" message -->
                  <span v-if="hiddenTagsCount > 0" @click="toggleShowHiddenTags" class="cursor-pointer">
                    {{ showHiddenTags ? 'less' : `+${hiddenTagsCount} more...` }}
                  </span>
                </div>
              </div>


<!--              <el-select-->
<!--                v-model="selectedToEmail"-->
<!--                @change="selectToEmail()"-->
<!--                multiple-->
<!--                filterable-->
<!--                allow-create-->
<!--                placeholder="Choose tags for your article"-->
<!--                :reserve-keyword="false"-->
<!--              >-->
<!--                <el-option-->
<!--                  v-for="option in emailShortCodes"-->
<!--                  :key="option.value"-->
<!--                  :label="option.label"-->
<!--                  :value="option.value"-->
<!--                />-->
<!--              </el-select>-->

              <validation-error
                :validation-errors="validationErrors?.to?.required"
              />
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item :label="$t('Cc:')">
          <el-input v-model="notification.cc" />
        </el-form-item>

        <el-form-item :label="$t('Mail Subject:')" required>
          <el-input
            v-model="notification.subject"
            @focus="clearValidationError('subject')"
          />
          <validation-error
            :validation-errors="validationErrors?.subject?.required"
          />
        </el-form-item>

        <div>
          <wp-editor
            ref="wpEditor"
            :short-codes="shortCodes"
            :emailTemplateEditor="true"
            @onEditorReady="onEditorReady"
            @update="
              (value) => {
                notification.contents = value;
              }
            "
            v-model="notification.contents"
          >
            <template v-slot:action>
              <div class="fct-select-button-wrap">
                <el-select
                  v-model="buttonVal"
                  @change="
                    (html) => {
                      addButton(html);
                    }
                  "
                  :placeholder="$t('Select button')"
                  size="small"
                >
                  <el-option
                    v-for="(button, index) in buttons"
                    :key="index"
                    :label="Object.keys(button)[0]"
                    :value="Object.values(button)[0]"
                  ></el-option>
                </el-select>
              </div><!-- .fct-select-button-wrap -->
              <div class="fct-select-template-wrap">
                <el-select
                  v-model="templateVal"
                  @change="
                    (template) => {
                      getTemplate(template);
                    }
                  "
                  :placeholder="$t('Select template')"
                  size="small"
                >
                  <el-option
                    v-for="(template, index) in templates"
                    :key="index"
                    :label="template.label"
                    :value="template.path"
                  ></el-option>
                </el-select>
              </div><!-- .fct-select-template-wrap -->
            </template>

            <template v-slot:reset_template>
              <span>
                <el-tooltip
                  effect="dark"
                  :content="$t('Reset Template')"
                  placement="top"
                  popper-class="fct-tooltip"
                >
                    <IconButton @click.prevent="getTemplate(notification.path)" tag="button" size="small">
                      <DynamicIcon name="Refresh" />
                    </IconButton>
                </el-tooltip
              ></span>
            </template>
          </wp-editor>
        </div>
      </el-form>
    </CardBody>
  </Card>
  <div class="setting-save-action">
    <el-button
        @click="updateNotification"
        type="primary"
        :disabled="loading"
        :loading="loading"
    >
      {{ loading ? $t('Updating') : $t("Update") }}
    </el-button>
  </div>
</template>
