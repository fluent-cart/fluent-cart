<template>
    <div class="setting-wrap">
      <CardContainer>
        <CardHeader :title="$t('Storage Providers')" border_bottom/>
        <CardBody>

          <el-skeleton :loading="loading" animated :rows="6" />

          <div class="storage-setting-list fct-content-card-list">
            <div class="fct-content-card-list-item" v-for="(storage, index) in availableDrivers" :key="index">
              <div :class="`fct-content-card-list-icon ${storage.title.toLowerCase()}`">
                <img :src="getLogo(storage.logo)" :alt="storage.title"/>
              </div>

              <div class="fct-content-card-list-head">
                  <div class="title-wrap">
<!--                      <img :src="getLogo(storage.logo)" :alt="storage.title"/>-->
                      <h6>{{ storage.title }}</h6>
                  </div>
                <Badge size="small" :status="storage.status ? 'active' : 'info'" :hide-icon="true">
                    {{ $t(getBadgeTitle(storage.status)) }}
                  </Badge>
              </div>

              <div class="fct-content-card-list-content">
                <p>{{ storage.description }}</p>
              </div>

              <div class="fct-content-card-list-action">
                <el-button size="small" @click="() => $router.push({name: storage.route})">
                  {{$t('Manage')}}
                </el-button>
              </div>
            </div>
          </div><!-- .storage-setting-list -->
        </CardBody>
      </CardContainer>
    </div><!-- .setting-wrap -->
  </template>

  <script type="text/babel">
  import Badge from "@/Bits/Components/Badge.vue";
  import {Container as CardContainer, Header as CardHeader, Body as CardBody} from '@/Bits/Components/Card/Card.js';

  export default {
    name: 'StorageSettings',
    props: ['settings'],
    components: {
      Badge,
      CardBody,
      CardHeader,
      CardContainer
    },
    data() {
      return {
        loading: false,
        availableDrivers: [],
      };
    },
    methods: {
      getBadgeTitle(status){
        if(status === true){
          return 'active';
        }else{
          return 'inactive';
        }
      },
      getLogo(logo) {
        return logo;
      },
      getStorageDrivers() {
        this.loading = true;
        this.$get('settings/storage-drivers')
            .then(response => {
              this.availableDrivers = response.drivers;
            })
            .catch((e) => {
              this.handleError(e);
            })
            .finally(() => {
              this.loading = false;
            });
      }
    },
    mounted() {
      this.getStorageDrivers();
    }
  };
  </script>

