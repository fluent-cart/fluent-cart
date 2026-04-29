<script setup>


import * as VueInstance from 'vue';
import {defineComponent} from 'vue';
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Animation from "@/Bits/Components/Animation.vue";
import StepIndicator from "@/Bits/Components/StepIndicator.vue";
import LoadingButton from "@/Bits/Components/Buttons/LoadingButton.vue";

const props = defineProps({
  widget: {
    type: Object,
    required: true
  },
  data: {
    required: false
  }
});

const SafeVue = VueInstance.readonly(VueInstance);
const SafeRest = Object.freeze({
  get: (...args) => Rest.get(...args),
  post: (...args) => Rest.post(...args),
  put: (...args) => Rest.put(...args),
  delete: (...args) => Rest.delete(...args),
  patch: (...args) => Rest.patch(...args),
  upload: (...args) => Rest.upload(...args),
  getNonce: () => Rest.getNonce()
});
const SafeNotify = Object.freeze({
  success: (...args) => Notify.success(...args),
  error: (...args) => Notify.error(...args),
  info: (...args) => Notify.info(...args),
  validationErrors: (...args) => Notify.validationErrors(...args)
});
const SafeNavigator = Object.freeze({
  clipboard: window?.navigator?.clipboard,
  userAgent: window?.navigator?.userAgent,
  language: window?.navigator?.language,
  languages: window?.navigator?.languages,
  onLine: window?.navigator?.onLine,
  platform: window?.navigator?.platform
});

const BLOCKED_SCRIPT_PATTERNS = [
  {pattern: /\balert\s*\(/, label: 'alert()'},
  {pattern: /\bconfirm\s*\(/, label: 'confirm()'},
  {pattern: /\bprompt\s*\(/, label: 'prompt()'},
  {pattern: /\bsetTimeout\s*\(/, label: 'setTimeout()'},
  {pattern: /\bsetInterval\s*\(/, label: 'setInterval()'},
  {pattern: /\brequestAnimationFrame\s*\(/, label: 'requestAnimationFrame()'},
  {pattern: /\bcancelAnimationFrame\s*\(/, label: 'cancelAnimationFrame()'},
  {pattern: /\bqueueMicrotask\s*\(/, label: 'queueMicrotask()'},
  {pattern: /\bfetch\s*\(/, label: 'fetch()'},
  {pattern: /\bXMLHttpRequest\b/, label: 'XMLHttpRequest'},
  {pattern: /\bWebSocket\b/, label: 'WebSocket'},
  {pattern: /\bWorker\b/, label: 'Worker'},
  {pattern: /\bSharedWorker\b/, label: 'SharedWorker'},
  {pattern: /\bEventSource\b/, label: 'EventSource'},
  {pattern: /\bMessageChannel\b/, label: 'MessageChannel'},
  {pattern: /\bBroadcastChannel\b/, label: 'BroadcastChannel'},
  {pattern: /\bwindow\b/, label: 'window'},
  {pattern: /\bdocument\b/, label: 'document'},
  {pattern: /\bnavigator\.(?:serviceWorker|sendBeacon|geolocation|mediaDevices|bluetooth|usb|serial|hid|credentials|locks|wakeLock)\b/, label: 'navigator privileged API'},
  {pattern: /\bglobalThis\b/, label: 'globalThis'},
  {pattern: /\bself\b/, label: 'self'},
  {pattern: /\bparent\b/, label: 'parent'},
  {pattern: /\btop\b/, label: 'top'},
  {pattern: /\bframes\b/, label: 'frames'},
  {pattern: /\blocalStorage\b/, label: 'localStorage'},
  {pattern: /\bsessionStorage\b/, label: 'sessionStorage'},
  {pattern: /\bindexedDB\b/, label: 'indexedDB'},
  {pattern: /\bpostMessage\s*\(/, label: 'postMessage()'},
  {pattern: /\bopen\s*\(/, label: 'open()'},
  {pattern: /\bFileReader\b/, label: 'FileReader'},
  {pattern: /\bBlob\b/, label: 'Blob'},
  {pattern: /\bURL\.createObjectURL\b/, label: 'URL.createObjectURL'},
  {pattern: /\bMutationObserver\b/, label: 'MutationObserver'},
  {pattern: /\bResizeObserver\b/, label: 'ResizeObserver'},
  {pattern: /\bIntersectionObserver\b/, label: 'IntersectionObserver'},
  {pattern: /\bhistory\.(?:pushState|replaceState|go|back|forward)\s*\(/, label: 'history navigation'},
  {pattern: /\blocation\.(?:assign|replace|reload)\s*\(/, label: 'location navigation'},
  {pattern: /\blocation\.href\s*=/, label: 'location.href assignment'},
  {pattern: /\bFunction\b/, label: 'Function'},
  {pattern: /\beval\s*\(/, label: 'eval()'},
  {pattern: /\bnew\s+Function\b/, label: 'new Function'},
  {pattern: /\brequire\s*\(/, label: 'require()'},
  {pattern: /\bimport\s*\(/, label: 'import()'},
  {pattern: /\bReflect\b/, label: 'Reflect'},
  {pattern: /\bProxy\b/, label: 'Proxy'},
  {pattern: /\bprocess\b/, label: 'process'},
  {pattern: /\bmodule\b/, label: 'module'},
  {pattern: /\bexports\b/, label: 'exports'},
  {pattern: /\b__proto__\b/, label: '__proto__'},
  {pattern: /\b__defineGetter__\b/, label: '__defineGetter__'},
  {pattern: /\b__defineSetter__\b/, label: '__defineSetter__'}
];

const validateDynamicScript = (rawScript) => {
  for (const rule of BLOCKED_SCRIPT_PATTERNS) {
    if (rule.pattern.test(rawScript)) {
      console.error(`Dynamic component script rejected due to blocked token: ${rule.label}`);
      return false;
    }
  }

  return true;
};

const parseStringComponent = (componentString) => {
  try {
    if (typeof componentString !== 'string') {
      return null;
    }

    /** ----------------------------
     *  Extract template - Match the outermost template tag only
     * ---------------------------- */
        // First, find the opening <template> tag (without attributes like #reference)
    const templateStartMatch = componentString.match(/<template(?:\s[^>]*)?>|<template>/i);

    if (!templateStartMatch) {
      console.error('Dynamic component missing <template>');
      return null;
    }

    const templateStartIndex = templateStartMatch.index + templateStartMatch[0].length;

    // Find the matching closing </template> tag by counting nested templates
    let templateDepth = 1;
    let currentIndex = templateStartIndex;
    let templateEndIndex = -1;

    while (currentIndex < componentString.length && templateDepth > 0) {
      const nextOpenTag = componentString.indexOf('<template', currentIndex);
      const nextCloseTag = componentString.indexOf('</template>', currentIndex);

      if (nextCloseTag === -1) {
        console.error('Unclosed <template> tag');
        return null;
      }

      if (nextOpenTag !== -1 && nextOpenTag < nextCloseTag) {
        templateDepth++;
        currentIndex = nextOpenTag + 9; // length of '<template'
      } else {
        templateDepth--;
        if (templateDepth === 0) {
          templateEndIndex = nextCloseTag;
        }
        currentIndex = nextCloseTag + 11; // length of '</template>'
      }
    }

    if (templateEndIndex === -1) {
      console.error('Could not find matching closing </template> tag');
      return null;
    }

    const template = componentString.substring(templateStartIndex, templateEndIndex).trim();

    /** ----------------------------
     *  Extract script (optional)
     * ---------------------------- */
    const scriptMatch = componentString.match(
        /<script>([\s\S]*?)<\/script>/i
    );

    let componentOptions = {};

    if (scriptMatch) {
      const rawScript = scriptMatch[1].trim();

      // Must export default
      if (!/export\s+default/.test(rawScript)) {
        console.error('Dynamic component script must export default');
        return null;
      }

      if (!validateDynamicScript(rawScript)) {
        return null;
      }

      // Strip "export default"
      const cleanScript = rawScript.replace(
          /export\s+default/,
          'return'
      );

      // This is a constrained runtime, not a true sandbox. Keep the surface narrow.
      const factory = new Function(
          'Vue',
          'Rest',
          'Notify',
          'translate',
          'Animation',
          'StepIndicator',
          'LoadingButton',
          'ElMessage',
          'ElMessageBox',
          'alert',
          'confirm',
          'prompt',
          'setTimeout',
          'setInterval',
          'requestAnimationFrame',
          'cancelAnimationFrame',
          'queueMicrotask',
          'fetch',
          'XMLHttpRequest',
          'WebSocket',
          'Worker',
          'SharedWorker',
          'EventSource',
          'MessageChannel',
          'BroadcastChannel',
          'window',
          'document',
          'navigator',
          'globalThis',
          'self',
          'parent',
          'top',
          'frames',
          'localStorage',
          'sessionStorage',
          'indexedDB',
          'postMessage',
          'open',
          'FileReader',
          'Blob',
          'URL',
          'MutationObserver',
          'ResizeObserver',
          'IntersectionObserver',
          'history',
          'location',
          'Function',
          'Reflect',
          'Proxy',
          'process',
          'module',
          'exports',
          'require',
          `"use strict";\n${cleanScript}`
      );

      componentOptions = factory(
          SafeVue,
          SafeRest,
          SafeNotify,
          translate,
          Animation,
          StepIndicator,
          LoadingButton,
          window?.ELEMENT?.ElMessage,
          window?.ELEMENT?.ElMessageBox,
          void 0, // alert
          void 0, // confirm
          void 0, // prompt
          void 0, // setTimeout
          void 0, // setInterval
          void 0, // requestAnimationFrame
          void 0, // cancelAnimationFrame
          void 0, // queueMicrotask
          void 0, // fetch
          void 0, // XMLHttpRequest
          void 0, // WebSocket
          void 0, // Worker
          void 0, // SharedWorker
          void 0, // EventSource
          void 0, // MessageChannel
          void 0, // BroadcastChannel
          void 0, // window
          void 0, // document
          SafeNavigator,
          void 0, // globalThis
          void 0, // self
          void 0, // parent
          void 0, // top
          void 0, // frames
          void 0, // localStorage
          void 0, // sessionStorage
          void 0, // indexedDB
          void 0, // postMessage
          void 0, // open
          void 0, // FileReader
          void 0, // Blob
          void 0, // URL
          void 0, // MutationObserver
          void 0, // ResizeObserver
          void 0, // IntersectionObserver
          void 0, // history
          void 0, // location
          void 0, // Function
          void 0, // Reflect
          void 0, // Proxy
          void 0, // process
          void 0, // module
          void 0, // exports
          void 0 // require
      ) || {};
    }

    return defineComponent({
      ...componentOptions,
      template
    });

  } catch (e) {
    console.error('Failed to parse dynamic Vue component:', e);
    return null;
  }
};


const getDynamicComponent = (component) => {
  // If it's a string, parse it first
  if (typeof component === 'string') {
    const parsed = parseStringComponent(component);
    return parsed ? defineComponent(parsed) : null;
  }

  // If it's already an object, use it directly
  return defineComponent(component);
};


</script>

<template>
  <component
      ref="componentRefs"
      :is="getDynamicComponent(widget.component)"
      :data="data"
      v-bind="{
        ...widget
      }"
  />
</template>

<style scoped>

</style>
