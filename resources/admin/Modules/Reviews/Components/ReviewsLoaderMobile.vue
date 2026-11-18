<template>
  <div class="fct-table-mobile-wrap">
    <div v-for="i in 5" :key="i" class="fct-table-mobile-row">
      <fct-skeleton animated :rows="3"/>
    </div>
  </div>
</template>
