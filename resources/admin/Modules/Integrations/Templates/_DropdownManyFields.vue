<template>
    <div class="dropdown_label_repeater w-full">
        <table class="fluent_cart-table w-full text-left">
          <colgroup>
            <col width="25%">
            <col width="70%">
            <col width="10%">
          </colgroup>
            <thead>
            <tr>
                <th>{{rendered_labels.remote_text}}</th>
                <th>{{rendered_labels.local_text}}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="(item, itemIndex) in settings[field.key]" :key="'item_'+itemIndex">
                <td>
                    <el-select v-model="item.label">
                        <el-option
                            v-for="(optionLabel, optionValue) in field.options"
                            :key="optionValue"
                            :label="optionLabel"
                            :value="optionValue"
                        ></el-option>
                    </el-select>
                </td>
                <td>
                    <!--need to fix the defaultValue object issue-->
                    <field-general
                        :editorShortcodes="editorShortcodes"
                        :defaultValue="typeof item.item_value=== 'object' ? '' : item.item_value"
                        v-model="item.item_value"
                    ></field-general>
                </td>
                <td>
                  <div class="action-btns">
                    <IconButton
                        size="x-small"
                        tag="button"
                        @click.prevent="removeItem(itemIndex)"
                        :disabled="settings[field.key].length === 1"
                        class="border-none"
                    >
                        <DynamicIcon name="Delete" />
                    </IconButton>
                  </div>

                </td>
            </tr>
            </tbody>
        </table>

      <div class="dropdown_label_repeater_footer">
        <el-button text @click.prevent="addNewItem">
          <DynamicIcon name="Plus" />
          {{$t('Add More')}}
        </el-button>
      </div>
    </div>
</template>

<script type="text/babel">
    import FieldGeneral from './_FieldGeneral.vue';
    import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
    import IconButton from "@/Bits/Components/Buttons/IconButton.vue";

    export default {
        name: 'DropdownManyFields',
        props: [
            'settings',
            'field',
            'inputs',
            'errors',
            'editorShortcodes'
        ],
        data() {
            return {
                test: 'null',
                rendered_labels: {
                    remote_text: this.field.remote_text || 'Field Label',
                    local_text: this.field.local_text || 'Field Value'
                },
            }
        },
        components: {
            FieldGeneral,
            DynamicIcon,
            IconButton
        },
        methods: {
            addNewItem() {
              this.settings[this.field.key].push({
                item_value: '',
                label: ''
              });
            },
            removeItem(index) {
                if (this.settings[this.field.key].length === 1) {
                    return;
                }
                this.settings[this.field.key].splice(index, 1);
            }
        },
        mounted() {
            if(!this.settings[this.field.key] || !this.settings[this.field.key].length) {
                this.settings[this.field.key] = [
                    {
                        item_value: this.field,
                        label: ''
                    }
                ]
            }
        }
    }
</script>
