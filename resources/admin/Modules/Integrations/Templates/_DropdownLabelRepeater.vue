<template>
    <div class="dropdown_label_repeater">
        <table v-if="!loading" class="fluent_cart-table">
            <thead>
            <tr>
                <th>{{field.field_label || 'Field Label'}}</th>
                <th>{{field.value_label || 'Field Value'}}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="(item, itemIndex) in settings[field.key]" :key="'item_'+itemIndex">
                <td>
                    <el-input :placeholder="field.field_label || 'Field Label'" v-model="item.label"></el-input>
                </td>
                <td>
                    <field-general
                        :editorShortcodes="editorShortcodes"
                        v-model="item.item_value"
                    ></field-general>
                </td>
                <td>
                    <el-button-group>
                        <el-button type="success" @click="addItemAfter(itemIndex)">+</el-button>
                        <el-button type="danger" :disabled="settings[field.key].length == 1" @click="removeItem(itemIndex)">-</el-button>
                    </el-button-group>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
</template>

<script type="text/babel">
    import FieldGeneral from './_FieldGeneral.vue';

    export default {
        name: 'DropdownLabelRepeater',
        props: ['settings', 'field', 'inputs', 'errors', 'editorShortcodes'],
        components: {
            FieldGeneral
        },
        data() {
          return {
              loading: false
          }
        },
        methods: {
            addItemAfter(index) {
                this.loading = true;
                this.settings[this.field.key].splice( index + 1, 0, {
                    item_value: '',
                    label: ''
                });
                this.$nextTick(() => {
                    this.loading = false;
                });
            },
            removeItem(index) {
                this.loading = true;
                this.settings[this.field.key].splice(index, 1);
                this.$nextTick(() => {
                    this.loading = false;
                });
            }
        }
    }
</script>