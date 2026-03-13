<template>
  <div style="width: 100%;">
    <div style="display: flex;justify-content: center;align-items: center;column-gap: 10px;" v-if="editorFailed">
      <p>{{ $t('Problem with loading the editor. Please reload the page.') }}</p>
    </div>
    <div class="editor-container" style="position: relative;">
      <!-- Editor Loading Skeleton (overlays iframe until content is ready) -->
      <transition name="fct-skeleton-fade">
      <div v-if="!editorContentReady" class="fct-editor-skeleton">
        <!-- Menubar -->
        <div class="fct-skel-menubar">
          <div class="fct-skel fct-skel-logo"></div>
          <div class="fct-skel-slash"></div>
          <div class="fct-skel fct-skel-name"></div>
          <div class="fct-skel-spacer"></div>
          <div class="fct-skel fct-skel-btn-wide"></div>
          <div class="fct-skel-dot"></div>
          <div class="fct-skel-dot"></div>
        </div>
        <!-- Toolbar -->
        <div class="fct-skel-toolbar">
          <div class="fct-skel fct-skel-tb-btn"></div>
          <div class="fct-skel fct-skel-tb-btn"></div>
          <div class="fct-skel fct-skel-tb-btn"></div>
          <div class="fct-skel-sep"></div>
          <div class="fct-skel fct-skel-tb-btn"></div>
          <div class="fct-skel fct-skel-tb-btn"></div>
          <div class="fct-skel-sep"></div>
          <div class="fct-skel fct-skel-tb-wide"></div>
          <div class="fct-skel fct-skel-tb-wide"></div>
          <div class="fct-skel-sep"></div>
          <div class="fct-skel fct-skel-tb-btn"></div>
        </div>
        <!-- Content area -->
        <EmailBodySkeleton :show-loading-badge="true" />
      </div>
      </transition>
      <div ref="iframeContainer" class="iframe-container"></div>
    </div>
  </div>
</template>

<script>
import EmailBodySkeleton from './EmailBodySkeleton.vue';

export default {
  name: 'NewEditorFrame',
  components: { EmailBodySkeleton },
  emits: ['update:modelValue', 'titleUpdated', 'contentUpdated', 'editorFullscreenToggle', 'featuredMediaUpdated', 'previewRequest', 'autosaveState'],
  props: {
    // Path to editor - can be relative or absolute
    editorPath: {
      type: String,
      default: '/editor'
    },
    modelValue: {
      type: String,
      default: ''
    },
    editorParams: {
      type: Object,
      default: () => ({})
    },
    frameHeight: {
      type: String,
      default: '500px'
    },
    documentTitle: {
      type: String,
      default: ''
    }
  },
  data() {
    return {
      editorData: {
        content: ''
      },
      editorFrame: null,
      editorOrigin: null,
      dataSent: false,
      isFailing: false,
      iframeReady: false,
      pendingDataSend: false,
      sendRetries: 0,
      editorReadyReceived: false,
      editorContentReady: false,
      isFullscreen: false,
      _timers: []
    };
  },
  computed: {
    editorFailed() {
      return !this.dataSent && this.isFailing && !this.editorReadyReceived;
    }
  },
  mounted() {
    // Show fallback after 30 seconds
    this._setTimeout(() => {
      if (!this.editorReadyReceived) {
        this.isFailing = true;
      }
    }, 30000);

    this.editorData.content = this.modelValue;
    this.editorData.title = this.documentTitle;

    // this.editorData.featured_image_id = this.featured_image_id;
    // Create the iframe programmatically
    this.createEditorIframe();

    // Listen for messages from the iframe
    window.addEventListener('message', this.handleEditorMessage);
  },
  beforeUnmount() {
    // Clear all pending timers
    this._timers.forEach(id => clearTimeout(id));
    this._timers = [];

    // Clean up event listener when component is destroyed
    window.removeEventListener('message', this.handleEditorMessage);

    // Exit fullscreen if active
    if (this.isFullscreen) {
      this.toggleFullScreen(false);
    }

    // Remove the iframe and make sure to clean up to prevent memory leaks
    if (this.editorFrame) {
      this.editorFrame.onload = null; // Remove the onload event
      this.$refs.iframeContainer.removeChild(this.editorFrame);
      this.editorFrame = null;
    }
  },
  methods: {
    _setTimeout(fn, delay) {
      const id = setTimeout(fn, delay);
      this._timers.push(id);
      return id;
    },
    createEditorIframe() {
      // Create iframe element
      this.editorFrame = document.createElement('iframe');

      let FrameUrl = this.appVars.fct_editor_frame;

      if (this.editorParams) {
        // Append parameters to the URL
        const params = new URLSearchParams(this.editorParams);
        FrameUrl += '&' + params.toString();
      }


      // Ensure origin check matches the actual iframe URL
      try {
        this.editorOrigin = new URL(FrameUrl, window.location.origin).origin;
      } catch (e) {
        // Fallback to current origin if URL parsing fails
        this.editorOrigin = window.location.origin;
      }

      this.editorFrame.src = FrameUrl;
      this.editorFrame.style.width = '100%';
      this.editorFrame.style.height = this.frameHeight;

      // Wait for iframe to load before marking it as ready
      this.editorFrame.onload = () => {
        this.iframeReady = true;

        // If there's a pending data send, send it now
        if (this.pendingDataSend) {
          this.pendingDataSend = false;
          // Wait a bit for the editor to initialize
          this._setTimeout(() => {
            this.sendDataToEditor();
          }, 1000);
        }

        // Fallback: if EDITOR_READY not received within 10 seconds, try sending data anyway
        this._setTimeout(() => {
          if (!this.editorReadyReceived && this.iframeReady) {
            this.sendDataToEditor();
          }
        }, 10000);
      };

      // Append iframe to the container
      this.$refs.iframeContainer.appendChild(this.editorFrame);
    },
    sendDataToEditor() {
      // Check if iframe is ready
      if (!this.iframeReady || !this.editorFrame || !this.editorFrame.contentWindow) {
        this.pendingDataSend = true;
        return;
      }

      try {
        const safeData = JSON.parse(JSON.stringify(this.editorData));

        this.editorFrame.contentWindow.postMessage({
          action: 'UPDATE_EDITOR',
          data: safeData
        }, this.editorOrigin);

        this.dataSent = true;
        this.sendRetries = 0;

        // Fallback: hide skeleton after 5s even if EDITOR_UPDATED never fires
        this._setTimeout(() => {
          if (!this.editorContentReady) {
            this.editorContentReady = true;
          }
        }, 5000);
      } catch (error) {
        console.error('Error sending data to editor:', error);
        this.sendRetries++;
        if (this.sendRetries <= 3) {
          // Retry after a short delay
          this._setTimeout(() => {
            this.sendDataToEditor();
          }, 500);
        }
      }
    },
    handleEditorMessage(event) {
      // Verify the message origin matches the iframe origin
      if (event && event.origin && this.editorOrigin && event.origin !== this.editorOrigin) {
          return;
      }

      const {action, content} = event.data;
      if (action === 'EDITOR_UPDATED') {
        if (this.dataSent) {
          if (!this.editorContentReady) {
            // Small delay so the browser can paint the editor chrome before we fade the skeleton
            this._setTimeout(() => {
              this.editorContentReady = true;
            }, 1500);
          }
          this.editorData.content = content;
          this.$emit('update:modelValue', content);
          this.$emit('contentUpdated', content);
        }
      } else if (action === 'TITLE_UPDATED') {
        this.editorData.title = content;
        this.$emit('titleUpdated', content);
      } else if (action === 'FEATURED_MEDIA_UPDATED') {
        this.$emit('featuredMediaUpdated', content);
      } else if (action === 'EDITOR_FULLSCREEN_TOGGLE') {
        this.toggleFullScreen(content);
      } else if (action === 'EMAIL_PREVIEW_REQUEST') {
        this.$emit('previewRequest');
      } else if (action === 'AUTOSAVE_STATE') {
        this.$emit('autosaveState', content);
      } else if (action === 'EDITOR_READY') {
        this.editorReadyReceived = true;
        this.isFailing = false;
        this.sendDataToEditor();
      }
    },
    forceAutosave() {
      if (this.editorFrame && this.editorFrame.contentWindow) {
        this.editorFrame.contentWindow.postMessage({
          action: 'FORCE_SAVE'
        }, this.editorOrigin);
      }
    },
    toggleFullScreen(enter) {
      this.isFullscreen = enter;
      const iframe = this.editorFrame;

      if (enter) {
        document.documentElement.classList.add('fct-editor-fullscreen');
        document.body.style.overflow = 'hidden';

        if (iframe) {
          iframe.classList.add('fct-fullscreen-iframe');

          // Walk up ancestors and neutralize stacking contexts so position:fixed works
          this._fullscreenAncestors = [];
          let el = iframe.parentElement;
          while (el && el !== document.documentElement) {
            el.classList.add('fct-fullscreen-ancestor');
            this._fullscreenAncestors.push(el);
            el = el.parentElement;
          }
        }
      } else {
        document.documentElement.classList.remove('fct-editor-fullscreen');
        document.body.style.overflow = '';

        if (iframe) {
          iframe.classList.remove('fct-fullscreen-iframe');
        }

        // Remove ancestor classes
        if (this._fullscreenAncestors) {
          this._fullscreenAncestors.forEach(function (el) {
            el.classList.remove('fct-fullscreen-ancestor');
          });
          this._fullscreenAncestors = null;
        }
      }

      this.$emit('editorFullscreenToggle', enter);
    }
  }
}
</script>

<style scoped>
.editor-container {
  display: flex;
  flex-direction: column;
  gap: 0;
  margin: 0;
}

.iframe-container {
  min-height: 500px;
}

/* ── Editor Loading Skeleton ── */
@keyframes fct-shimmer {
  0%   { background-position: -600px 0; }
  100% { background-position:  600px 0; }
}

@keyframes fct-loadpulse {
  0%, 80%, 100% { opacity: .25; transform: scale(.8); }
  40%            { opacity: 1;   transform: scale(1);  }
}

.fct-skel {
  background: linear-gradient(90deg, #EAECF0 25%, #F5F6F7 50%, #EAECF0 75%);
  background-size: 600px 100%;
  animation: fct-shimmer 1.6s ease-in-out infinite;
  border-radius: 4px;
}

.fct-editor-skeleton {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 10;
  border: 1px solid #D6DAE1;
  border-radius: 8px;
  overflow: hidden;
  background: #fff;
  width: 100%;
  display: flex;
  flex-direction: column;
}

/* Fade out transition */
.fct-skeleton-fade-leave-active {
  transition: opacity 0.3s ease;
}
.fct-skeleton-fade-leave-to {
  opacity: 0;
}

/* Menubar */
.fct-skel-menubar {
  height: 40px;
  background: #F9FAFB;
  border-bottom: 1px solid #EAECF0;
  display: flex;
  align-items: center;
  padding: 0 12px;
  gap: 8px;
}
.fct-skel-logo { width: 24px; height: 24px; border-radius: 4px; flex-shrink: 0; }
.fct-skel-slash { width: 1px; height: 20px; background: #D6DAE1; margin: 0 4px; flex-shrink: 0; }
.fct-skel-name { width: 120px; height: 10px; border-radius: 5px; }
.fct-skel-spacer { flex: 1; }
.fct-skel-dot { width: 6px; height: 6px; border-radius: 50%; background: #D6DAE1; flex-shrink: 0; }
.fct-skel-btn-wide { width: 52px; height: 24px; border-radius: 4px; }

/* Toolbar */
.fct-skel-toolbar {
  height: 44px;
  background: #fff;
  border-bottom: 1px solid #EAECF0;
  display: flex;
  align-items: center;
  padding: 0 12px;
  gap: 6px;
}
.fct-skel-sep { width: 1px; height: 20px; background: #D6DAE1; margin: 0 2px; flex-shrink: 0; }
.fct-skel-tb-btn { width: 28px; height: 28px; border-radius: 4px; flex-shrink: 0; }
.fct-skel-tb-wide { width: 80px; height: 28px; border-radius: 4px; flex-shrink: 0; }

/* Content area - uses shared EmailBodySkeleton component, needs flex:1 to fill space */
.fct-editor-skeleton .fct-email-body-skeleton {
  flex: 1;
  border: none;
  border-radius: 0;
}
</style>

<style>
/* Fullscreen mode: hide everything except the editor iframe */
html.fct-editor-fullscreen #wpadminbar,
html.fct-editor-fullscreen #adminmenuwrap,
html.fct-editor-fullscreen #adminmenuback,
html.fct-editor-fullscreen #adminmenumain {
  display: none !important;
}

html.fct-editor-fullscreen {
  margin-top: 0 !important;
}

html.fct-editor-fullscreen #wpcontent,
html.fct-editor-fullscreen #wpbody,
html.fct-editor-fullscreen #wpbody-content {
  margin-left: 0 !important;
  padding-left: 0 !important;
}

/* Hide FluentCart app header/sidebar in fullscreen */
html.fct-editor-fullscreen #fct_admin_menu_holder {
  display: none !important;
}

/* Neutralize stacking contexts on all ancestors so position:fixed on iframe works */
html.fct-editor-fullscreen .fct-fullscreen-ancestor {
  position: static !important;
  transform: none !important;
  will-change: auto !important;
  filter: none !important;
  contain: none !important;
  perspective: none !important;
  backdrop-filter: none !important;
  isolation: auto !important;
  clip-path: none !important;
  overflow: visible !important;
  z-index: auto !important;
}

/* The iframe itself in fullscreen */
html.fct-editor-fullscreen .fct-fullscreen-iframe {
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  width: 100vw !important;
  height: 100vh !important;
  z-index: 999999 !important;
  background: #fff !important;
  border: none !important;
}
html.fct-editor-fullscreen .el-overlay {
  z-index: 1999999 !important;
}
</style>
