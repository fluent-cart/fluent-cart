<template>
  <div class="space-y-6">
    <div v-if="fetching" class="fct-card fct-card-border overflow-hidden mb-0">
      <div class="fct-card-body">
        <el-skeleton animated :rows="6" />
      </div>
    </div>

    <template v-else>
      <div v-if="showDashboard" class="space-y-6">
        <div class="fct-storage-activated-card">
          <div class="activated-card-header">
            <div class="activated-card-content">
                <img
                    v-if="payload.logo"
                    :class="{ 'dark:hidden': payload.dark_logo }"
                    :src="payload.logo"
                    :alt="payload.driver_label"
                />
                <img
                    v-if="payload.dark_logo"
                    :src="payload.dark_logo"
                    :alt="payload.driver_label"
                    class="hidden dark:block"
                />

                <div
                    v-if="!payload.logo && !payload.dark_logo"
                    class="bucket-name-logo"
                >
                    S3
                </div>

                <div class="activated-card-content-inner">
                    <div class="activated-card-bucket-name">
                        <h2 class="title">
                            {{ payload.driver_label }}
                        </h2>

                        <span :class="['badge small', form.is_active === 'yes' ? 'success' : 'info']">
                        {{ form.is_active === 'yes' ? translate('Active') : translate('Inactive') }}
                        </span>

                        <span class="badge small info">
                        {{ connectionMethodLabel }}
                        </span>
                    </div>

                    <p class="activated-card-bucket-desc">
                        {{ payload.description }}
                    </p>

                    <!-- <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-system-mid dark:text-gray-300 hidden">
                        <a
                        v-if="bucketConsoleUrl"
                        :href="bucketConsoleUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-medium text-primary-500 no-underline hover:underline dark:text-gray-50"
                        >
                        {{ form.bucket }}
                        </a>
                        <span v-else class="font-medium text-system-light dark:text-gray-400">
                        {{ translate('No bucket selected') }}
                        </span>
                        <span v-if="form.region" class="text-system-light dark:text-gray-400">/</span>
                        <span v-if="form.region">{{ formattedRegion }}</span>
                    </div> -->

                    <!-- btn group -->
                    <div class="fct-btn-group mt-3">
                        <LoadingButton size="small" :loading="checking_connection" @click="checkConnection">
                            {{ translate('Check again') }}
                        </LoadingButton>

                        <el-button size="small" @click="enableEditMode">
                            {{ translate('Edit Config') }}
                        </el-button>

                        <el-popconfirm
                            :title="translate('Are you sure you want to reset all settings? This cannot be undone.')"
                            :confirm-button-text="translate('Yes, Reset')"
                            :cancel-button-text="translate('No')"
                            confirm-button-type="danger"
                            @confirm="resetSettings"
                        >
                            <template #reference>
                                <el-button size="small" plain type="danger">
                                    {{ translate('Reset') }}
                                </el-button>
                            </template>
                        </el-popconfirm>
                    </div>
                </div>
            </div>

            <div class="shrink-0">
                <el-switch
                v-model="form.is_active"
                active-value="yes"
                inactive-value="no"
                :loading="saving"
                @change="handleSwitchChange"
                />
            </div>
          </div>

          <div class="activated-card-body">
            <ul class="fct-activated-bucket-list-info">
                <li>
                    <div class="info-title">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M4.66659 4.66667H11.1666C12.571 4.66667 13.2733 4.66667 13.7777 5.00373C13.9961 5.14964 14.1836 5.33715 14.3295 5.55553C14.6666 6.05997 14.6666 6.7622 14.6666 8.16667C14.6666 10.5074 14.6666 11.6778 14.1048 12.5186C13.8616 12.8825 13.5491 13.195 13.1852 13.4382C12.3444 14 11.174 14 8.83325 14H7.99992C4.85722 14 3.28587 14 2.30956 13.0237C1.33325 12.0474 1.33325 10.476 1.33325 7.33333V5.29618C1.33325 4.0852 1.33325 3.47971 1.5868 3.02538C1.76753 2.70151 2.03476 2.43428 2.35863 2.25354C2.81296 2 3.41845 2 4.62943 2C5.40527 2 5.79318 2 6.13276 2.12734C6.90807 2.41808 7.22778 3.12238 7.57763 3.82208L7.99992 4.66667" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        {{ translate('Bucket') }}
                    </div>
                    <div class="info-value">
                        <a
                            v-if="bucketConsoleUrl"
                            :href="bucketConsoleUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ form.bucket }}
                        </a>
                        <span v-else>
                            {{ translate('No bucket selected') }}
                        </span>
                    </div>
                </li>
                
                <li>
                    <div class="info-title">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <g clip-path="url(#clip0_22443_64899)">
                            <circle cx="7.99992" cy="7.9987" r="6.66667" stroke="currentColor" stroke-width="1.5"/>
                            <ellipse cx="7.99992" cy="7.9987" rx="2.66667" ry="6.66667" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M1.33325 8H14.6666" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </g>
                            <defs>
                            <clipPath id="clip0_22443_64899">
                            <rect width="16" height="16" fill="white"/>
                            </clipPath>
                            </defs>
                        </svg>
                        {{ translate('Region') }}
                    </div>
                    <div class="info-value">
                        {{ form.region ? formattedRegion : translate('Not selected') }}
                    </div>
                </li>
                
                <li>
                    <div class="info-title">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <g clip-path="url(#clip0_22443_64906)">
                                <path d="M4.66659 1.33203C2.82564 1.33203 1.33325 2.82442 1.33325 4.66536C1.33325 5.89917 2.00358 6.97641 2.99992 7.55276V11.8941C2.99992 12.4391 2.99992 12.7116 3.10141 12.9567C3.20291 13.2017 3.39559 13.3944 3.78097 13.7797L4.66659 14.6654L6.07205 13.2599C6.13687 13.1951 6.1693 13.1627 6.19618 13.1275C6.26679 13.0351 6.31203 12.9259 6.3274 12.8107C6.33325 12.7668 6.33325 12.721 6.33325 12.6293C6.33325 12.5551 6.33325 12.518 6.32932 12.482C6.31901 12.3874 6.28861 12.2962 6.24015 12.2144C6.22167 12.1833 6.19941 12.1536 6.15489 12.0942L5.33325 10.9987L5.79992 10.3765C6.06424 10.024 6.19641 9.84782 6.26483 9.64256C6.33325 9.43729 6.33325 9.21702 6.33325 8.77648V7.55276C7.32959 6.97641 7.99992 5.89917 7.99992 4.66536C7.99992 2.82442 6.50753 1.33203 4.66659 1.33203Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                                <path d="M4.66675 4.66797H4.67274" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M8.66675 9.33203H12.6667C13.288 9.33203 13.5986 9.33203 13.8437 9.43353C14.1704 9.56885 14.4299 9.82842 14.5653 10.1551C14.6667 10.4001 14.6667 10.7108 14.6667 11.332C14.6667 11.9533 14.6667 12.2639 14.5653 12.5089C14.4299 12.8356 14.1704 13.0952 13.8437 13.2305C13.5986 13.332 13.288 13.332 12.6667 13.332H8.66675" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M10 3.33203H12.6667C13.2879 3.33203 13.5985 3.33203 13.8436 3.43353C14.1703 3.56885 14.4298 3.82842 14.5652 4.15512C14.6667 4.40015 14.6667 4.71078 14.6667 5.33203C14.6667 5.95329 14.6667 6.26391 14.5652 6.50894C14.4298 6.83565 14.1703 7.09521 13.8436 7.23054C13.5985 7.33203 13.2879 7.33203 12.6667 7.33203H10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                </g>
                                <defs>
                                <clipPath id="clip0_22443_64906">
                                <rect width="16" height="16" fill="white"/>
                                </clipPath>
                                </defs></svg>
                        {{ translate('Authentication') }}
                    </div>
                    <div class="info-value">
                        {{ connectionMethodLabel }}
                    </div>
                </li>
                
                <li>
                    <div class="info-title">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <g clip-path="url(#clip0_22443_64941)">
                        <path d="M7.99888 1.33203C5.9937 1.33203 4.69354 2.67803 3.15589 3.16863C2.53067 3.36812 2.21806 3.46786 2.09155 3.60846C1.96504 3.74907 1.92799 3.95453 1.8539 4.36545C1.06104 8.76269 2.79401 12.828 6.92694 14.4103C7.371 14.5804 7.59303 14.6654 8.00107 14.6654C8.40911 14.6654 8.63112 14.5804 9.07515 14.4103C13.2078 12.828 14.9392 8.76268 14.1461 4.36545C14.0719 3.95446 14.0349 3.74896 13.9083 3.60836C13.7818 3.46775 13.4692 3.36806 12.844 3.16869C11.3058 2.67813 10.0041 1.33203 7.99888 1.33203Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M6 8.66667C6 8.66667 6.66667 8.66667 7.33333 10C7.33333 10 9.45098 6.66667 11.3333 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </g>
                        <defs>
                        <clipPath id="clip0_22443_64941">
                        <rect width="16" height="16" fill="white"/>
                        </clipPath>
                        </defs></svg>
                        {{ translate('Security posture') }}
                    </div>
                    <div class="info-value">
                        {{ form.block_public_access === 'yes' ? translate('Public access blocked') : translate('Public access open') }},
                        {{ form.object_ownership === 'yes' ? translate('ownership enforced') : translate('ownership not enforced') }}

                        <span :class="['badge small', securityRecommendationLabel === translate('Recommended') ? 'success' : 'warning']">
                            {{ securityRecommendationLabel }}
                        </span>
                    </div>
                </li>
            </ul>
          </div>

          
        </div>
      </div>

      <div v-else class="space-y-6">
        <el-alert
          v-if="showMissingDefineCredentialsWarning"
          :title="missingDefineCredentialsMessage"
          type="warning"
          show-icon
          :closable="false"
        />

        <div class="fct-card">
          <div class="fct-card-header border-bottom">
            <StepIndicator
              :steps="visibleSteps"
              :current-step="stepperActive + 1"
              @step-click="handleStepClick"
            />
          </div>

          <div class="fct-card-body">
            <div v-show="currentStep === 1" class="fct-step-content-box space-y-4">
                <h4 class="fct-step-content-box-heading">
                    {{ translate('Verify Access Keys') }}
                </h4>
              <ul class="fct-coverage-selector">
                <li
                  :class="{ active: form.auth_method === 'define' }"
                  @click="!form.defined_in_wp_config && (form.auth_method = 'define')"
                >
                  <div class="w-full">
                    <div class="fct-coverage-selector-content-wrap">
                      <div class="fct-coverage-selector-content">
                        <span class="fct-coverage-selector-title">
                            {{ translate('Define access keys in wp-config.php') }}
                            <span class="opacity-50">{{ translate('(Recommended)') }}</span>
                        </span>
                        <span class="fct-coverage-selector-desc">
                          {{ form.defined_in_wp_config
                            ? translate('You have already defined your credentials in wp-config.php.')
                            : translate('Recommended. Keeps your credentials secure by defining them in your configuration file.') }}
                        </span>
                      </div>
                      <div class="fct-coverage-selector-dot-wrap shrink-0">
                        <span class="fct-coverage-selector-dot"></span>
                      </div>
                    </div>

                    <Animation :visible="form.auth_method === 'define'" accordion :duration="220">
                      <div class="mt-4">
                        <div class="space-y-4">
                          <div class="config-snippet">
                            <pre class="m-0 overflow-x-auto text-xs text-system-dark dark:text-gray-50">{{ wpConfigSnippet }}</pre>
                          </div>

                          <div class="flex justify-start">
                            <el-button @click.stop="copyCode">{{ translate('Copy Snippet') }}</el-button>
                          </div>
                        </div>
                      </div>
                    </Animation>
                  </div>
                </li>

                <li
                  :class="{ active: form.auth_method === 'db', 'opacity-60 cursor-not-allowed': form.defined_in_wp_config }"
                  @click="!form.defined_in_wp_config && (form.auth_method = 'db')"
                >
                  <div class="w-full">
                    <div class="fct-coverage-selector-content-wrap">
                      <div class="fct-coverage-selector-content">
                        <span class="fct-coverage-selector-title">{{ translate('Store credentials in the database') }}</span>
                        <span class="fct-coverage-selector-desc">
                          {{ translate('Store credentials in the database. Less secure than other methods.') }}
                        </span>
                        <span v-if="form.defined_in_wp_config" class="mt-2 block text-sm text-system-mid dark:text-gray-300">
                          {{ translate('The database credential option is unavailable while your access keys are defined in wp-config.php.') }}
                        </span>
                      </div>
                      <div class="fct-coverage-selector-dot-wrap shrink-0">
                        <span class="fct-coverage-selector-dot"></span>
                      </div>
                    </div>

                    <Animation :visible="form.auth_method === 'db'" accordion :duration="220">
                      <div class="mt-4">
                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                          <div>
                            <label class="mb-2 block text-sm font-medium text-system-dark dark:text-gray-50">{{ translate('Access Key') }}</label>
                            <el-input v-model="form.access_key" :placeholder="translate('Enter access key')" @click.stop />
                          </div>

                          <div>
                            <label class="mb-2 block text-sm font-medium text-system-dark dark:text-gray-50">{{ translate('Secret Key') }}</label>
                            <el-input v-model="form.secret_key" show-password :placeholder="translate('Enter secret key')" @click.stop />
                          </div>
                        </div>
                      </div>
                    </Animation>
                  </div>
                </li>
              </ul>
            </div>

            <div v-show="currentStep === 2" class="fct-step-content-box space-y-4">
                <h4 class="fct-step-content-box-heading">
                    {{ translate('Choose where files live') }}
                </h4>

              <ul class="fct-coverage-selector">
                <li :class="{ active: bucketAction === 'existing' }" @click="bucketAction = 'existing'">
                  <div class="w-full">
                    <div class="fct-coverage-selector-content-wrap">
                      <div class="fct-coverage-selector-content">
                        <span class="fct-coverage-selector-title">{{ translate('Connect an existing bucket') }}</span>
                        <span class="fct-coverage-selector-desc">{{ translate('Choose a bucket you already manage in this AWS account.') }}</span>
                      </div>
                      <div class="fct-coverage-selector-dot-wrap shrink-0">
                        <span class="fct-coverage-selector-dot"></span>
                      </div>
                    </div>

                    <Animation :visible="bucketAction === 'existing'" accordion :duration="220">
                      <div class="mt-4">
                        <div class="space-y-4">
                          <div class="space-y-2">
                            <span class="block text-sm font-medium text-system-dark dark:text-gray-50">{{ translate('Bucket name') }}</span>
                            <div class="fct-coverage-bucket-name-selectors">
                              <label @click.stop>
                                <el-radio v-model="bucketInputMode" value="enter" @click.stop />
                                <span>{{ translate('Enter bucket name') }}</span>
                              </label>

                              <label @click.stop>
                                <el-radio v-model="bucketInputMode" value="select" @click.stop />
                                <span>{{ translate('Select from existing') }}</span>
                              </label>

                            </div>
                          </div>

                          <div v-if="bucketInputMode === 'enter'">
                            <el-input v-model="form.bucket" :placeholder="translate('Enter bucket name')" @click.stop />
                          </div>

                          <div v-else class="fct-bucket-list-wrap">
                            <div class="fct-bucket-list-search">
                              <el-input
                                v-model="bucketSearchQuery"
                                clearable
                                :placeholder="translate('Search buckets')"
                                @input="handleBucketSearch"
                                @clear="handleBucketSearch"
                                @click.stop
                              />
                            </div>

                            <div class="fct-bucket-list-header">
                              <span class="selected-bucket-count">
                                {{ bucketOptions.length }} {{ translate(bucketOptions.length === 1 ? 'bucket' : 'buckets') }}
                              </span>
                              <el-button text class="px-0" :loading="bucketLoading" @click.stop="refreshBucketList">
                                {{ translate('Reload bucket list') }}
                              </el-button>
                            </div>

                            <div v-if="bucketLoading" class="px-4 py-5 text-sm text-system-mid dark:text-gray-300">
                              {{ translate('Loading buckets...') }}
                            </div>

                            <div v-else-if="!bucketOptions.length" class="px-4 py-5 text-sm text-system-mid dark:text-gray-300">
                              {{ translate('No buckets found or credentials are invalid.') }}
                            </div>

                            <div v-else-if="!filteredBucketOptions.length" class="px-4 py-5 text-sm text-system-mid dark:text-gray-300">
                              {{ translate('No buckets match your search.') }}
                            </div>

                            <div v-else :class="bucketListClasses" class="fct-bucket-list">
                              <button
                                v-for="item in filteredBucketOptions"
                                :key="item.value"
                                type="button"
                                :class="form.bucket === item.value ? 'is-bucket-selected' : ''"
                                @click.stop="selectBucket(item)"
                              >
                                <span class="min-w-0 flex-1">
                                    <span class="bucket-name">
                                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M4.75 10.375C4.75 10.6097 5.09575 11.0185 5.8975 11.4197C6.9355 11.9387 8.40775 12.25 10 12.25C11.5923 12.25 13.0645 11.9387 14.1025 11.4197C14.9043 11.0185 15.25 10.6097 15.25 10.375V8.74675C14.0125 9.51175 12.1203 10 10 10C7.87975 10 5.9875 9.511 4.75 8.74675V10.375ZM15.25 12.4968C14.0125 13.2618 12.1203 13.75 10 13.75C7.87975 13.75 5.9875 13.261 4.75 12.4968V14.125C4.75 14.3597 5.09575 14.7685 5.8975 15.1697C6.9355 15.6887 8.40775 16 10 16C11.5923 16 13.0645 15.6887 14.1025 15.1697C14.9043 14.7685 15.25 14.3597 15.25 14.125V12.4968ZM3.25 14.125V6.625C3.25 4.76125 6.2725 3.25 10 3.25C13.7275 3.25 16.75 4.76125 16.75 6.625V14.125C16.75 15.9887 13.7275 17.5 10 17.5C6.2725 17.5 3.25 15.9887 3.25 14.125ZM10 8.5C11.5923 8.5 13.0645 8.18875 14.1025 7.66975C14.9043 7.2685 15.25 6.85975 15.25 6.625C15.25 6.39025 14.9043 5.9815 14.1025 5.58025C13.0645 5.06125 11.5923 4.75 10 4.75C8.40775 4.75 6.9355 5.06125 5.8975 5.58025C5.09575 5.9815 4.75 6.39025 4.75 6.625C4.75 6.85975 5.09575 7.2685 5.8975 7.66975C6.9355 8.18875 8.40775 8.5 10 8.5Z" fill="currentColor"/>
                                        </svg>

                                        {{ item.label }}
                                    </span>

                                  <span v-if="item.region" class="bucket-region">
                                        {{ item.region }}
                                    </span>
                                </span>

                                <span v-if="form.bucket === item.value" class="selected-icon">
                                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M7 14C3.1339 14 0 10.8661 0 7C0 3.1339 3.1339 0 7 0C10.8661 0 14 3.1339 14 7C14 10.8661 10.8661 14 7 14ZM6.3021 9.8L11.2511 4.8503L10.2613 3.8605L6.3021 7.8204L4.3218 5.8401L3.332 6.8299L6.3021 9.8Z" fill="currentColor"/>
                                    </svg>
                                </span>
                              </button>
                            </div>
                          </div>
                        </div>
                      </div>
                    </Animation>
                  </div>
                </li>

                <li :class="{ active: bucketAction === 'new' }" @click="bucketAction = 'new'">
                  <div class="w-full">
                    <div class="fct-coverage-selector-content-wrap">
                      <div class="fct-coverage-selector-content">
                        <span class="fct-coverage-selector-title">{{ translate('Create a new bucket') }}</span>
                        <span class="fct-coverage-selector-desc">{{ translate('Create and select a fresh bucket using the current credentials.') }}</span>
                      </div>
                      <div class="fct-coverage-selector-dot-wrap shrink-0">
                        <span class="fct-coverage-selector-dot"></span>
                      </div>
                    </div>

                    <Animation :visible="bucketAction === 'new'" accordion :duration="220">
                      <div class="mt-4">
                        <div class="space-y-4">
                          <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <div class="lg:col-span-2">
                              <label class="mb-2 block text-sm font-medium text-system-dark dark:text-gray-50">{{ translate('Bucket Name') }}</label>
                              <el-input v-model="newBucketName" :placeholder="translate('Enter bucket name')" @click.stop />
                              <p v-if="createBucketAttempted && !newBucketName" class="mt-1 text-xs text-red-500">
                                {{ translate('Bucket name not entered.') }}
                              </p>
                            </div>

                            <div>
                              <label class="mb-2 block text-sm font-medium text-system-dark dark:text-gray-50">{{ translate('Region') }}</label>
                              <el-select v-model="newBucketRegion" class="w-full" :placeholder="translate('Select Region')" @click.stop>
                                <el-option v-for="region in awsRegions" :key="region.value" :label="region.label" :value="region.value" />
                              </el-select>
                              <p v-if="bucketStepAttempted && !hasSelectedRegion(newBucketRegion)" class="mt-1 text-xs text-red-500">
                                {{ translate('Region is required.') }}
                              </p>
                            </div>
                          </div>
                        </div>
                      </div>
                    </Animation>
                  </div>
                </li>
              </ul>
            </div>

            <div v-show="currentStep === 3" class="fct-step-content-box space-y-4">
                <h4 class="fct-step-content-box-heading">
                    {{ translate('Review protection settings') }}
                </h4>
              <div class="space-y-4">
                <div class="config-snippet config-snippet-white">
                  <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium text-system-dark dark:text-gray-50">{{ translate('Block All Public Access') }}</span>
                        <span :class="['badge small', form.block_public_access === 'yes' ? 'success' : 'warning']">
                          {{ form.block_public_access === 'yes' ? translate('Recommended') : translate('Review') }}
                        </span>
                      </div>
                      <p class="m-0 mt-2 text-sm text-system-mid dark:text-gray-300">
                        {{ translate('Recommended for private asset delivery.') }}
                      </p>
                    </div>

                    <el-switch v-model="form.block_public_access" active-value="yes" inactive-value="no" />
                  </div>
                </div>

                <div class="config-snippet config-snippet-white">
                  <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium text-system-dark dark:text-gray-50">{{ translate('Object Ownership') }}</span>
                        <span :class="['badge small', form.object_ownership === 'yes' ? 'success' : 'warning']">
                          {{ form.object_ownership === 'yes' ? translate('Recommended') : translate('Review') }}
                        </span>
                      </div>
                      <p class="m-0 mt-2 text-sm text-system-mid dark:text-gray-300">
                        {{ translate('Ensure uploaded files stay under the expected ownership model.') }}
                      </p>
                    </div>

                    <el-switch v-model="form.object_ownership" active-value="yes" inactive-value="no" />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <div class="fct-btn-group">
                <el-button v-if="editMode" @click="cancelEdit">
                    {{ translate('Cancel') }}
                </el-button>

                <el-button v-if="!editMode && currentStep === 2" @click="currentStep = 1">
                    {{ translate('Back') }}
                </el-button>

                <el-button v-if="currentStep === 3" @click="currentStep = 2">
                    {{ translate('Back') }}
                </el-button>

                <el-button v-if="currentStep === 1" type="primary" :loading="saving" @click="saveAndContinue">
                    {{ translate('Save & Continue') }}
                </el-button>

                <el-button
                    v-if="currentStep === 2"
                    type="primary"
                    :loading="saving"
                    @click="advanceFromBucketStep"
                >
                    {{ bucketAction === 'new' ? translate('Create Bucket & Continue') : translate('Next') }}
                </el-button>

                <el-button v-if="currentStep === 3" type="primary" :loading="saving" @click="saveSecuritySettings">
                    {{ translate('Save Settings') }}
                </el-button>
            </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script>
export default {
  components: {
    Animation,
    StepIndicator,
    LoadingButton
  },
  props: {
    payload: {
      type: Object,
      required: true
    }
  },
  data() {
    return {
      saving: false,
      fetching: false,
      checking_connection: false,
      bucketLoading: false,
      editMode: false,
      wizardMode: true,
      currentStep: 1,
      bucketAction: 'existing',
      bucketInputMode: 'enter',
      bucketSearchQuery: '',
      newBucketName: '',
      newBucketRegion: 'us-east-1',
      bucketStepAttempted: false,
      createBucketAttempted: false,
      bucketOptions: [],
      connectionError: null,
      form: this.makeForm(this.payload.settings || {}),
      awsRegions: [
        { label: 'US East (N. Virginia)', value: 'us-east-1' },
        { label: 'US East (Ohio)', value: 'us-east-2' },
        { label: 'US West (N. California)', value: 'us-west-1' },
        { label: 'US West (Oregon)', value: 'us-west-2' },
        { label: 'Canada (Central)', value: 'ca-central-1' },
        { label: 'Canada West (Calgary)', value: 'ca-west-1' },
        { label: 'Africa (Cape Town)', value: 'af-south-1' },
        { label: 'Asia Pacific (Hong Kong)', value: 'ap-east-1' },
        { label: 'Asia Pacific (Mumbai)', value: 'ap-south-1' },
        { label: 'Asia Pacific (Hyderabad)', value: 'ap-south-2' },
        { label: 'Asia Pacific (Tokyo)', value: 'ap-northeast-1' },
        { label: 'Asia Pacific (Seoul)', value: 'ap-northeast-2' },
        { label: 'Asia Pacific (Osaka)', value: 'ap-northeast-3' },
        { label: 'Asia Pacific (Singapore)', value: 'ap-southeast-1' },
        { label: 'Asia Pacific (Sydney)', value: 'ap-southeast-2' },
        { label: 'Asia Pacific (Jakarta)', value: 'ap-southeast-3' },
        { label: 'Asia Pacific (Melbourne)', value: 'ap-southeast-4' },
        { label: 'Asia Pacific (Malaysia)', value: 'ap-southeast-5' },
        { label: 'Asia Pacific (Thailand)', value: 'ap-southeast-7' },
        { label: 'China (Beijing)', value: 'cn-north-1' },
        { label: 'China (Ningxia)', value: 'cn-northwest-1' },
        { label: 'EU (Frankfurt)', value: 'eu-central-1' },
        { label: 'EU (Zurich)', value: 'eu-central-2' },
        { label: 'EU (Ireland)', value: 'eu-west-1' },
        { label: 'EU (London)', value: 'eu-west-2' },
        { label: 'EU (Paris)', value: 'eu-west-3' },
        { label: 'EU (Milan)', value: 'eu-south-1' },
        { label: 'EU (Spain)', value: 'eu-south-2' },
        { label: 'EU (Stockholm)', value: 'eu-north-1' },
        { label: 'Israel (Tel Aviv)', value: 'il-central-1' },
        { label: 'Middle East (Bahrain)', value: 'me-south-1' },
        { label: 'Middle East (UAE)', value: 'me-central-1' },
        { label: 'Mexico (Central)', value: 'mx-central-1' },
        { label: 'South America (São Paulo)', value: 'sa-east-1' }
      ]
    };
  },
  computed: {
    showDashboard() {
      return !this.wizardMode && !this.editMode;
    },
    stepperActive() {
      const currentIndex = this.visibleSteps.findIndex((step) => step.number === this.currentStep);
      return currentIndex >= 0 ? currentIndex : 0;
    },
    showMissingDefineCredentialsWarning() {
      return this.currentStep === 1 && this.form.define_credentials_missing;
    },
    missingDefineCredentialsMessage() {
      return this.form.define_credentials_missing_message || this.translate('S3 was configured previously, but the wp-config.php credentials are missing now. Add them again to continue.');
    },
    formattedRegion() {
      const region = this.awsRegions.find((item) => item.value === this.form.region);
      return region ? region.label : this.form.region;
    },
    visibleSteps() {
      const steps = [
        {
          number: 1,
          label: this.translate('Credential'),
          description: this.translate('Verify access keys')
        },
        {
          number: 2,
          label: this.translate('Bucket'),
          description: this.translate('Choose where files live')
        },
        {
          number: 3,
          label: this.translate('Security'),
          description: this.translate('Review protection settings')
        }
      ];

      return this.editMode ? steps.filter((step) => step.number > 1) : steps;
    },
    connectionMethodLabel() {
      if (this.form.auth_method === 'define') {
        return this.translate('Defined mode');
      }

      return this.translate('Database mode');
    },
    securityRecommendationLabel() {
      return this.form.block_public_access === 'yes' && this.form.object_ownership === 'yes'
        ? this.translate('Recommended')
        : this.translate('Needs review');
    },
    bucketConsoleUrl() {
      if (!this.form.bucket) {
        return '';
      }

      return `https://console.aws.amazon.com/s3/buckets/${this.form.bucket}/?region=${this.form.region || 'us-east-1'}`;
    },
    filteredBucketOptions() {
      const query = (this.bucketSearchQuery || '').trim().toLowerCase();

      if (!query) {
        return this.bucketOptions;
      }

      return this.bucketOptions.filter((item) => {
        return [item.label, item.value, item.region].some((value) => {
          return String(value || '').toLowerCase().includes(query);
        });
      });
    },
    bucketListClasses() {
      const classes = [];

      if (this.filteredBucketOptions.length > 5) {
        classes.push('max-h-[16rem]', 'overflow-y-auto');
      }

      return classes;
    },
    wpConfigSnippet() {
      return `define( 'FCT_S3_ACCESS_KEY', '********************' );
define( 'FCT_S3_SECRET_KEY', '****************************************' );`;
    }
  },
  watch: {
    payload: {
      deep: true,
      handler(newPayload) {
        this.applyServerSettings(newPayload.settings || {});
      }
    },
    newBucketName(val) {
      const formatted = (val || '').toLowerCase().replace(/[^a-z0-9.-]/g, '-').replace(/-+/g, '-');
      if (formatted !== val) {
        this.newBucketName = formatted;
      }
    },
    bucketAction(newValue) {
      if (newValue === 'existing') {
        this.bucketSearchQuery = '';
        this.ensureBucketOptionsLoaded();
      }
    },
    bucketInputMode() {
      this.form.bucket = '';
    },
    currentStep(newValue) {
      if (newValue === 2 && this.bucketAction === 'existing') {
        this.ensureBucketOptionsLoaded();
      }
    }
  },
  mounted() {
    this.applyServerSettings(this.payload.settings || {});
  },
  methods: {
    translate,
    isStepAvailable(step) {
      return this.editMode || step < this.currentStep || (this.form.bucket && step <= 3);
    },
    defaults() {
      return {
        is_active: 'no',
        auth_method: 'define',
        access_key: '',
        secret_key: '',
        bucket: '',
        region: 'us-east-1',
        buckets: [],
        defined_in_wp_config: false,
        define_credentials_missing: false,
        define_credentials_missing_message: '',
        block_public_access: 'yes',
        object_ownership: 'yes',
        connection_error: ''
      };
    },
    normalizeSettings(settings = {}) {
      const normalized = {
        ...this.defaults(),
        ...settings
      };

      normalized.defined_in_wp_config = !!normalized.defined_in_wp_config;
      normalized.define_credentials_missing = !!normalized.define_credentials_missing;
      normalized.block_public_access = normalized.block_public_access === true || normalized.block_public_access === 'yes' ? 'yes' : 'no';
      normalized.object_ownership = normalized.object_ownership === true || normalized.object_ownership === 'yes' ? 'yes' : 'no';

      return normalized;
    },
    makeForm(settings) {
      return this.normalizeSettings(settings);
    },
    applyServerSettings(settings = {}, options = {}) {
      const { preserveWizardState = false } = options;
      const normalized = this.normalizeSettings(settings);

      if (!Object.prototype.hasOwnProperty.call(settings, 'access_key') && this.form?.access_key) {
        normalized.access_key = this.form.access_key;
      }

      if (!Object.prototype.hasOwnProperty.call(settings, 'secret_key') && this.form?.secret_key) {
        normalized.secret_key = this.form.secret_key;
      }

      this.form = {
        ...this.form,
        ...normalized
      };

      if (this.form.define_credentials_missing) {
        this.connectionError = null;
        this.bucketOptions = [];
        this.wizardMode = true;
        this.currentStep = 1;
        return;
      }

      this.connectionError = settings.connection_error || null;

      if (preserveWizardState) {
        return;
      }

      this.wizardMode = !this.form.bucket;
      this.currentStep = this.form.bucket ? 2 : 1;
    },
    mergeResponseSettings(response, options = {}) {
      const serverSettings = response?.data?.data || response?.data || response?.settings || {};
      this.applyServerSettings(serverSettings, options);
      return serverSettings;
    },
    copyCode() {
      if (!navigator?.clipboard?.writeText) {
        Notify.error(this.translate('Clipboard API is not available.'));
        return;
      }

      navigator.clipboard.writeText(this.wpConfigSnippet).then(() => {
        Notify.success(this.translate('Code copied to clipboard!'));
      }).catch(() => {
        Notify.error(this.translate('Failed to copy code.'));
      });
    },
    getDriver() {
      return this.payload.driver || 's3';
    },
    getSaveEndpoint() {
      return this.payload.save_endpoint || 'settings/storage-drivers';
    },
    getCreateBucketEndpoint() {
      return this.payload.create_bucket_endpoint || 'settings/storage-drivers/create-bucket';
    },
    getBucketListEndpoint() {
      return this.payload.bucket_list_endpoint || 'settings/storage-drivers/bucket-list';
    },
    getVerifyEndpoint() {
      return this.payload.connection_verify_endpoint || 'settings/storage-drivers/verify-info';
    },
    getResetEndpoint() {
      return this.payload.reset_endpoint || 'settings/storage-drivers/reset';
    },
    enableEditMode() {
      this.editMode = true;
      this.bucketAction = 'existing';
      this.bucketInputMode = 'enter';
      this.currentStep = this.form.bucket ? 2 : 1;
      this.bucketSearchQuery = '';
      this.connectionError = null;
    },
    cancelEdit() {
      this.editMode = false;
      this.refreshSettings();
    },
    goToStep(step) {
      if (this.editMode || step < this.currentStep || (this.form.bucket && step <= 3)) {
        this.currentStep = step;
      }
    },
    handleStepClick(localIndex) {
      const step = this.visibleSteps[localIndex - 1];
      if (step) {
        this.goToStep(step.number);
      }
    },
    refreshSettings(showLoading = false) {
      if (showLoading) {
        this.fetching = true;
      }

      return Rest.get('settings/storage-drivers/' + this.getDriver()).then((response) => {
        this.applyServerSettings(response.settings || {});
      }).finally(() => {
        if (showLoading) {
          this.fetching = false;
        }
      });
    },
    checkConnection() {
      this.checking_connection = true;

      Rest.post(this.getVerifyEndpoint(), {
        driver: this.getDriver(),
        settings: this.buildSettingsPayload()
      }).then((response) => {
        this.connectionError = null;
        Notify.success(response?.message || this.translate('Connection verified successfully.'));
      }).catch((errors) => {
        const message = errors?.data?.message || this.translate('Connection failed. Please check settings.');
        this.connectionError = message;
        Notify.error(message);
      }).finally(() => {
        this.checking_connection = false;
      });
    },
    handleSwitchChange() {
      if (this.form.is_active === 'yes') {
        this.activateDriver().catch(() => {
          this.form.is_active = 'no';
        });
      } else {
        this.deactivateDriver().catch(() => {
          this.form.is_active = 'yes';
        });
      }
    },
    activateDriver() {
      this.saving = true;
      return Rest.post(this.getSaveEndpoint(), {
        driver: this.getDriver(),
        settings: {
          ...this.form,
          is_active: 'yes'
        }
      }).then((response) => {
        this.mergeResponseSettings(response);
        Notify.success(this.translate('S3 activated successfully.'));
      }).catch((errors) => {
        const message = errors?.data?.message || this.translate('Failed to activate driver.');
        Notify.error(message);
        throw errors;
      }).finally(() => {
        this.saving = false;
      });
    },
    deactivateDriver() {
      this.saving = true;
      return Rest.post(this.getSaveEndpoint(), {
        driver: this.getDriver(),
        settings: {
          ...this.form,
          is_active: 'no',
          preserve_settings: true
        }
      }).then(() => {
        Notify.success(this.translate('S3 deactivated successfully.'));
      }).catch((errors) => {
        const message = errors?.data?.message || this.translate('Failed to deactivate driver.');
        Notify.error(message);
        throw errors;
      }).finally(() => {
        this.saving = false;
      });
    },
    resetSettings() {
      this.saving = true;
      Rest.post(this.getResetEndpoint(), {
        driver: this.getDriver()
      }).then((response) => {
        this.applyServerSettings(response?.data?.data || {});
        this.form = this.makeForm(response?.data?.data || {});
        this.currentStep = 1;
        this.editMode = false;
        this.wizardMode = true;
        this.bucketOptions = [];
        Notify.success(this.translate('S3 settings reset successfully.'));
      }).catch((errors) => {
        const message = errors?.data?.message || this.translate('Failed to reset settings.');
        Notify.error(message);
      }).finally(() => {
        this.saving = false;
      });
    },
    fetchBuckets(query) {
      this.bucketLoading = true;
      Rest.post(this.getBucketListEndpoint(), {
        driver: this.getDriver(),
        query: query,
        settings: this.form
      }).then((response) => {
        this.bucketOptions = response.options || [];
      }).catch((errors) => {
        this.bucketOptions = [];

        const message = errors?.data?.message || this.translate('Failed to load buckets.');
        Notify.error(message);
      }).finally(() => {
        this.bucketLoading = false;
      });
    },
    ensureBucketOptionsLoaded() {
      if (!this.bucketOptions.length && !this.bucketLoading) {
        this.fetchBuckets('');
      }
    },
    refreshBucketList() {
      this.fetchBuckets('');
    },
    handleBucketSearch() {
      this.bucketSearchQuery = (this.bucketSearchQuery || '').trim();
    },
    hasSelectedRegion(region) {
      return !!String(region || '').trim();
    },
    resetCreateBucketState(options = {}) {
      const { clearName = false } = options;

      this.bucketStepAttempted = false;
      this.createBucketAttempted = false;

      if (clearName) {
        this.newBucketName = '';
      }
    },
    buildSettingsPayload(extra = {}) {
      const settings = {
        ...this.form,
        ...extra
      };

      delete settings.create_new_bucket;
      delete settings.new_bucket_name;
      delete settings.new_bucket_region;

      return settings;
    },
    selectBucket(item) {
      this.form.bucket = item.value;
      if (item.region) {
        this.form.region = item.region;
      }
    },
    advanceFromBucketStep() {
      this.bucketStepAttempted = true;

      if (this.bucketAction === 'new') {
        if (!this.hasSelectedRegion(this.newBucketRegion)) {
          Notify.error(this.translate('Please select a region before continuing.'));
          return;
        }
        this.createNewBucket();
        return;
      }

      this.saveBucketSettings();
    },
    saveAndContinue() {
      this.saving = true;
      Rest.post(this.getSaveEndpoint(), {
        driver: this.getDriver(),
        settings: this.buildSettingsPayload({
          is_active: 'no',
          preserve_settings: true,
          verify_credentials: true,
          verify_only_credentials: true
        })
      }).then((response) => {
        this.mergeResponseSettings(response);
        this.currentStep = 2;
        this.bucketOptions = [];
        this.bucketSearchQuery = '';
        this.ensureBucketOptionsLoaded();
        Notify.success(this.translate('Credentials saved. Proceeding to bucket selection.'));
      }).catch((errors) => {
        const message = errors?.data?.message || this.translate('Failed to save credentials.');
        if (errors.status_code === '422' && Notify.validationErrors) {
          Notify.validationErrors(errors);
        } else {
          Notify.error(message);
        }
      }).finally(() => {
        this.saving = false;
      });
    },
    createNewBucket() {
      this.createBucketAttempted = true;

      if (!this.newBucketName) {
        return;
      }

      this.saving = true;
      Rest.post(this.getCreateBucketEndpoint(), {
        driver: this.getDriver(),
        settings: {
          ...this.buildSettingsPayload(),
          create_new_bucket: 'yes',
          new_bucket_name: this.newBucketName,
          new_bucket_region: this.newBucketRegion
        }
      }).then((response) => {
        const bucketData = response?.data?.data || response?.data || {};
        this.form.bucket = bucketData.bucket || this.newBucketName;
        this.form.region = bucketData.region || this.newBucketRegion;
        this.form.connection_error = '';

        if (bucketData.block_public_access) {
          this.form.block_public_access = bucketData.block_public_access;
        }

        if (bucketData.object_ownership) {
          this.form.object_ownership = bucketData.object_ownership;
        }

        this.bucketAction = 'existing';
        this.bucketSearchQuery = '';
        this.resetCreateBucketState({
          clearName: true
        });
        this.currentStep = 3;
        Notify.success(this.translate('Bucket created and selected successfully.'));
      }).catch((errors) => {
        const message = errors?.data?.message || this.translate('Failed to create bucket.');
        if (errors.status_code === '422' && Notify.validationErrors) {
          Notify.validationErrors(errors);
        } else {
          Notify.error(message);
        }
      }).finally(() => {
        this.saving = false;
      });
    },
    saveBucketSettings() {
      this.form.bucket = (this.form.bucket || '').trim();

      if (!this.form.bucket) {
        Notify.error(this.translate('Please select or enter a bucket before continuing.'));
        return;
      }

      this.saving = true;
      Rest.post(this.getSaveEndpoint(), {
        driver: this.getDriver(),
        settings: this.buildSettingsPayload({
          is_active: 'no',
          preserve_settings: true,
          check_bucket_exists: 'yes'
        })
      }).then((response) => {
        this.mergeResponseSettings(response, {
          preserveWizardState: true
        });
        this.resetCreateBucketState();
        this.currentStep = 3;
        Notify.success(this.translate('Bucket settings saved.'));
      }).catch((errors) => {
        const message = errors?.data?.message || this.translate('Failed to save bucket settings.');
        if (errors.status_code === '422' && Notify.validationErrors) {
          Notify.validationErrors(errors);
        } else {
          Notify.error(message);
        }
      }).finally(() => {
        this.saving = false;
      });
    },
    saveSecuritySettings() {
      this.saving = true;
      Rest.post(this.getSaveEndpoint(), {
        driver: this.getDriver(),
        settings: this.buildSettingsPayload({
          is_active: 'yes'
        })
      }).then((response) => {
        this.mergeResponseSettings(response);
        this.wizardMode = false;
        this.editMode = false;
        this.resetCreateBucketState({
          clearName: true
        });
        Notify.success(this.translate('S3 settings saved successfully.'));
      }).catch((errors) => {
        const message = errors?.data?.message || this.translate('Failed to save security settings.');
        Notify.error(message);
      }).finally(() => {
        this.saving = false;
      });
    }
  }
};
</script>
