<script setup>
import {VueDraggableNext} from "vue-draggable-next";

const props = defineProps({
  list: {
    type: Array,
    required: true
  },
  parent: {
    type: String,
    default: ''
  },
  cloneItem: {
    type: Function,
    required: true
  }
})
</script>

<template>
  <div>
    <VueDraggableNext
        class="fct-query-builder-draggable-list"
        tag="ul"
        :list="list"
        :sort="false"
        :group="{ name: parent, pull: 'clone', put: false }"
        :clone="(item)=>{
              let itemToCopy = {...item};
              //delete itemToCopy['columns'];
              delete itemToCopy['relations'];
              return cloneItem(itemToCopy,'isRelation')
            }"
        item-key="operator"
    >
      <li v-for="(relation, index) in list" :key="relation.title" class="item">
        {{ relation.title }}
        <div class="pl-2" v-if="relation.relations">
          <RelationList :clone-item="cloneItem" :list="relation.relations" :parent="parent+relation.name"/>
        </div>
      </li>
    </VueDraggableNext>
  </div>
</template>

<style scoped>

</style>
