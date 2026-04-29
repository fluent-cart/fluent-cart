<template>
  <IconButton
    tag="button"
    @click="captureScreenshot"
    :size="size"
    :title="$t('Screenshot')"
  >
    <DynamicIcon name="Screenshot" />
  </IconButton>
</template>

<script setup>
import html2canvas from "html2canvas";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";

const captureScreenshot = () => {
  const targetElement = props.targetRef;

  if (targetElement) {
    html2canvas(props.targetRef, {
      useCORS: true,
      scale: 2,
      backgroundColor: null,
      scrollY: -window.scrollY,
    }).then((canvas) => {
      const link = document.createElement("a");
      link.download = "chart-screenshot.png";
      link.href = canvas.toDataURL();
      link.click();
    });
  } else {
    console.error("Target element for screenshot not found.");
  }
};

const props = defineProps({
  targetRef: {
    type: [Object, null],
    required: false,
  },
  size: {
    type: String,
    default: "small",
  },
});
</script>
