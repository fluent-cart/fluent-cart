<template>
  <div class="fct-webhook-multiple-fields">
    <table>
      <thead>
      <tr>
        <th>
          {{$t('Field Name')}}
        </th>
        <th>
          {{$t('Field Value')}}
        </th>
      </tr>
      </thead>
      <tbody>
      <tr v-for="(field, index) in settings.selected_field_values" :key="index">
        <td width="50%">
          <el-input v-model="field.field_name" placeholder="Field Name"/>
        </td>
        <td width="45%">
          <el-select v-model="field.value" placeholder="Field Value" clearable class="w-full">
            <el-option-group
                v-for="group in selected_field_options"
                :key="group.label"
                :label="group.label"
            >
              <el-option
                  v-for="item in group.options"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value"
              />
            </el-option-group>
          </el-select>
        </td>
        <td width="5%">
          <div class="action-btns">
            <IconButton size="small" tag="button" @click.prevent="addField">
              <DynamicIcon name="Plus"/>
            </IconButton>
            <IconButton v-if="settings.selected_field_values.length > 1" size="small" tag="button" @click.prevent="removeField(index)">
              <DynamicIcon name="Minus"/>
            </IconButton>
          </div>
        </td>
      </tr>
      </tbody>
    </table>
  </div>
</template>

<script>
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

export default {
  components: {DynamicIcon, IconButton},
  props: ['settings', 'selected_field_options'],
  methods: {
    addField() {
      this.settings.selected_field_values.push({
        field_name: '',
        field_value: '',
      });
    },
    removeField(index) {
      this.settings.selected_field_values.splice(index, 1);
    }
  }
}
</script>
