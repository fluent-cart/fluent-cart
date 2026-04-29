import Arr from "@/utils/support/Arr";

class ElementorSupport {

    controlsView = null;
    model = null;
    saver = null;

    constructor() {
        setTimeout(() => {
            this.controlsView = elementor.getPanelView()?.getCurrentPageView();
            this.model = this.controlsView?.model;
            this.saver = elementor?.saver;
        }, 100)

    }

    setSettings(key, value) {
        this.controlsView = elementor.getPanelView()?.getCurrentPageView();
        this.model = this.controlsView?.model;
        if (this.model) {
            this.model.setSetting(key, value);
            this.triggerSave()
        }
    };

    getSettings(key = null, defaultValue = null) {
        this.controlsView = elementor.getPanelView()?.getCurrentPageView();
        this.model = this.controlsView?.model;
        const settings = this.model?.attributes?.settings?.attributes;
        if (settings) {
            if (key) {
                return Arr.get(settings, key, defaultValue);
            }
            return settings;
        }

        return defaultValue;
    }

    triggerSave() {
        if (!this.saver) return;
        this.saver.setFlagEditorChange(true);
    }
}

export default function useElementor() {
    return new ElementorSupport();
}
