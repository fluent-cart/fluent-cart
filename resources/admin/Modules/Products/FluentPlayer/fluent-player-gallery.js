import translate from '@/utils/translator/Translator';
import { galleryTabComponent } from '@/Modules/Products/galleryTabComponent';
import { registerFluentPlayerGalleryTab } from './fluentPlayerGalleryTab';
import { loadMediaSummaries } from './fluentPlayerMedia';

registerFluentPlayerGalleryTab(
    window.fluent_cart_admin?.hooks,
    window.fluentCartAdminApp?.app_config?.fluentPlayer,
    galleryTabComponent(() => import('@/Modules/Products/parts/FluentPlayerVideoTab.vue')),
    translate,
    (restUrl, ids) => loadMediaSummaries(restUrl, ids, translate('Untitled video'))
);
