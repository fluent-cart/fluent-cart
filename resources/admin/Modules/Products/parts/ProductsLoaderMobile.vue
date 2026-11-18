<template>
  <fct-skeleton animated>
        <div class="px-4 py-3">
          <div class="flex items-center justify-between mb-3">
            <fct-skeleton variant="p" class="w-1/2"/>
            <fct-skeleton variant="p" class="w-[100px]"/>
          </div>

          <div class="grid grid-cols-3 gap-5 mt-5">
            <div v-for="index in 3" :key="index">
              <fct-skeleton variant="p" class="w-1/2"/>
              <fct-skeleton variant="p" class="w-[100px]"/>
            </div>
          </div>
        </div>
  </fct-skeleton>
</template>
