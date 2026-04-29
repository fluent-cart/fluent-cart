<template>
  <div class="fct-webhook-multiple-fields">
    <table>
      <thead>
      <tr>
        <th>
          {{$t('Header Name')}}
        </th>
        <th>
          {{$t('Header Value')}}
        </th>
      </tr>
      </thead>
      <tbody>
      <tr v-for="(header, index) in settings.custom_header_values" :key="index">
        <td>
          <el-input v-model="header.header_name" placeholder="Header Key"/>
        </td>
        <td>
          <el-input v-model="header.header_value" placeholder="Enter Value"/>
        </td>
        <td>
          <div class="action-btns">
            <IconButton size="small" tag="button" @click.prevent="addHeader">
              <DynamicIcon name="Plus"/>
            </IconButton>
            <IconButton v-if="settings.custom_header_values.length > 1" size="small" tag="button" @click.prevent="removeHeader(index)">
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
  props: ['settings'],
  methods: {
    addHeader() {
      this.settings.custom_header_values.push({
        header_name: '',
        header_value: ''
      });
    },
    removeHeader(index) {
      this.settings.custom_header_values.splice(index, 1);
    }
  }
}
</script>
