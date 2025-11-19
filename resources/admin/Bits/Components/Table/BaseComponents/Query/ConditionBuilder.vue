<template>
  <div class="relative" :class="isFirst === false ? 'bg-gray-25 rounded-tr rounded-br dark:bg-dark-500' : ''">
    <div v-if="!isFirst && item.conditions?.length !== 0" class="fct-item-type">
      <DynamicIcon name="Condition"/>
      {{ $t('Type:') }} {{ item.type }}
    </div>

    <div class="relative" ref="dragTarget">

      <VueDraggableNext
          class="condition-container"
          :class="{
            'h-[50px]': item.conditions?.length === 0
          }"
          tag="ul"
          :list="item['conditions']"
          :group="{
            name: 'conditions',
            pull: true,
            put: put
          }"
          :handle="!disableHandle ? null : 'handle'"
          @drop="onDropped"
      >

        <template v-if="item.conditions?.length > 0"
                  v-for="(conditionItem, index) in item.conditions"
                  :key="index"
        >
          <li
              class="arc"
              :class="{
                'is-single': item.conditions?.length === 1,
                'arc-straight': isMiddleIndex(index),
                'arc-down': index < item.conditions?.length/ 2 && (!isMiddleIndex(index)),
                'arc-up': index >= item.conditions?.length/2 && (!isMiddleIndex(index)),
                'is-first': index === 0,
                'is-last':index+1 === item.conditions?.length,
                'is-two': item.conditions?.length === 2,
              }"
          >
            <div class="fct-condition-builder-options-container">
              <Handle v-if="!disableHandle"/>
              <div class="fct-condition-builder-options">
                <div class="w-full">
                  <ConditionBuilder
                      v-if="conditionItem.isCondition === true"
                      :item="conditionItem"
                      :columns="columns"
                      :queryState="queryState"
                  />
                  <OperatorBuilder
                      v-if="conditionItem.isOperator === true"
                      :columns="columns"
                      :condition="conditionItem"
                      :queryState="queryState"
                  />
                  <RelationBuilder
                      v-if="conditionItem.isRelation === true"
                      :relation="conditionItem"
                      :queryState="queryState"
                  />
                </div>

              </div>
              <IconButton v-if="conditionItem.isOperator === true" class="delete-icon" tag="button" @click="evt => {
                item.conditions.splice(index, 1);
                onDropped();
              }">
                <DynamicIcon name="Delete"/>
              </IconButton>
            </div>
          </li>
        </template>


      </VueDraggableNext>

      <div v-if="item.conditions?.length > 0" class="logic-container" style="transform: translateY(-50%)">
        <el-select popper-class="logic-container-popover" class="w-full el-select--x-small" v-model="item.type">
          <el-option key="and"
                     label="And"
                     value="and"
          />
          <el-option key="or"
                     label="Or"
                     value="or"
          />
        </el-select>
      </div>
    </div>

    <div class="fct-add-condition">
      <div class="fct-add-condition-button" @click="addDummyCondition">
        <DynamicIcon name="Plus"/>
        {{ $t('Add Condition') }}
      </div>
    </div>

    <div v-if="item.conditions?.length === 0" class="fct-drop-here-area pointer-events-none">
      Drop Here
    </div>
  </div>
</template>

<script setup>
import {VueDraggableNext} from "vue-draggable-next";
import Handle from "@/Bits/Components/Table/BaseComponents/Query/Handle.vue";
import OperatorBuilder from "@/Bits/Components/Table/BaseComponents/Query/OperatorBuilder.vue";
import RelationBuilder from "@/Bits/Components/Table/BaseComponents/Query/RelationBuilder.vue";
import {useTemplateRef} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";


const props = defineProps({
  isFirst: {
    type: Boolean,
    default: false
  },
  item: {
    type: Object,
    required: true
  },
  hideTitle: {
    type: Boolean,
    default: false
  },
  disableHandle: {
    type: Boolean,
    default: false
  },
  columns: {
    type: Object,
    required: true
  },
  queryState: {
    type: Object,
    required: true
  },
  put: {
    type: Array,
    default: [
      'operators',
      'condition',
      'relations'
    ]
  }
});

const dragTarget = useTemplateRef('dragTarget');
const onDropped = () => {
  const droppedTo = jQuery(dragTarget.value)
  const container = jQuery(droppedTo.find('> ul'));


  setTimeout(() => {

    let conditionList = jQuery(container.find('> li'));
    let conditionCount = conditionList.length;

    if (conditionCount > 1) {
      conditionList.each((index, element) => {
        let afterHeight = jQuery(element).outerHeight(true);
        if (index === 0 || index === conditionCount - 1) {
          afterHeight /= 2;
        }
        afterHeight += 12;
        jQuery(element).css('--after-height', `${afterHeight}px`);

      });
    }

  }, 100);


  //console.log('I am called', conditionList);
}

const isMiddleIndex = (index) => {
  if (props.item.conditions.length % 2 === 0) {
    return false;
  }
  return Math.floor(props.item.conditions.length / 2) === index;
}


const addDummyCondition = () => {
  props.item.conditions.push({
    title: 'Where',
    operator: 'where',
    column: '',
    value: '',
    isOperator: true
  });
  onDropped();
}

</script>
