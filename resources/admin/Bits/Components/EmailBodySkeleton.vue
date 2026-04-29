<template>
  <div class="fct-email-body-skeleton">
    <div class="fct-email-body-skeleton__page">
      <div class="fct-ebs__skel fct-ebs__header"></div>
      <div style="height: 8px;"></div>
      <div class="fct-ebs__row">
        <div class="fct-ebs__skel" style="width: 45%; height: 12px;"></div>
        <div style="flex:1"></div>
        <div class="fct-ebs__skel" style="width: 30%; height: 12px;"></div>
      </div>
      <div class="fct-ebs__divider"></div>
      <div class="fct-ebs__grid">
        <div class="fct-ebs__skel"></div>
        <div class="fct-ebs__skel"></div>
        <div class="fct-ebs__skel"></div>
        <div class="fct-ebs__skel"></div>
      </div>
      <div class="fct-ebs__row" style="margin-top: 2px;">
        <div class="fct-ebs__skel" style="width: 30%; height: 12px;"></div>
        <div class="fct-ebs__skel" style="width: 20%; height: 12px;"></div>
        <div class="fct-ebs__skel" style="width: 30%; height: 12px;"></div>
        <div class="fct-ebs__skel" style="width: 20%; height: 12px;"></div>
      </div>
      <div class="fct-ebs__divider"></div>
      <div class="fct-ebs__row">
        <div class="fct-ebs__skel" style="flex: 1; height: 12px;"></div>
      </div>
      <div class="fct-ebs__row">
        <div class="fct-ebs__skel" style="width: 45%; height: 12px;"></div>
      </div>
      <div v-if="showLoadingBadge" class="fct-ebs__badge">
        <span class="fct-ebs__dot"></span>
        <span class="fct-ebs__dot"></span>
        <span class="fct-ebs__dot"></span>
        <span class="fct-ebs__badge-text">{{ $t('Loading block editor…') }}</span>
      </div>
    </div>
  </div>
</template>

<script>
export default {
    name: 'EmailBodySkeleton',
    props: {
        showLoadingBadge: {
            type: Boolean,
            default: false
        }
    }
}
</script>

<style>
@keyframes fct-ebs-shimmer {
  0%   { background-position: -600px 0; }
  100% { background-position:  600px 0; }
}

@keyframes fct-ebs-pulse {
  0%, 80%, 100% { opacity: .25; transform: scale(.8); }
  40%            { opacity: 1;   transform: scale(1);  }
}

.fct-email-body-skeleton {
  background: #F9FAFB;
  display: flex;
  justify-content: center;
  padding: 24px 20px;
  border: 1px solid #EAECF0;
  border-radius: 8px;
  min-height: 420px;
}

.fct-email-body-skeleton__page {
  background: #fff;
  width: 100%;
  max-width: 580px;
  border-radius: 6px;
  border: 1px solid #EAECF0;
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 14px;
  box-shadow: 0 1px 4px rgba(47,52,72,.06);
}

.fct-ebs__skel {
  background: linear-gradient(90deg, #EAECF0 25%, #F5F6F7 50%, #EAECF0 75%);
  background-size: 600px 100%;
  animation: fct-ebs-shimmer 1.6s ease-in-out infinite;
  border-radius: 4px;
}

.fct-ebs__header { height: 48px; border-radius: 6px; width: 100%; }
.fct-ebs__row { display: flex; gap: 10px; align-items: center; }
.fct-ebs__divider { height: 1px; background: #EAECF0; margin: 4px 0; }
.fct-ebs__grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px; }
.fct-ebs__grid .fct-ebs__skel { height: 11px; border-radius: 5px; }

.fct-ebs__badge {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  background: #F5F6F7;
  border: 1px solid #EAECF0;
  border-radius: 20px;
  width: fit-content;
  margin: 4px auto 0;
}

.fct-ebs__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #4F66F5;
  animation: fct-ebs-pulse 1.4s ease-in-out infinite;
}
.fct-ebs__dot:nth-child(2) { animation-delay: 0.2s; }
.fct-ebs__dot:nth-child(3) { animation-delay: 0.4s; }

.fct-ebs__badge-text {
  font-size: 12px;
  font-weight: 500;
  color: #9D9FAC;
  letter-spacing: -0.1px;
}
</style>
