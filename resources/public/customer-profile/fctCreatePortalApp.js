import {createApp} from 'vue';
import App from './Vue/App.vue';

// Root must be the compiled App: a root without a render function makes Vue compile
// the mount point's HTML with `new Function`, which a CSP without 'unsafe-eval' blocks.
export default function fctCreatePortalApp() {
    return createApp(App);
}
