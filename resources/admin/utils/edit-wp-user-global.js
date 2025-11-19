

const FluentCartViewProfile = {
    init() {
        if (window.fluentcart_edit_user_global_bar_vars.edit_user_vars && window.fluentcart_edit_user_global_bar_vars.edit_user_vars.fct_profile_url) {
            this.maybeUserProfile(window.fluentcart_edit_user_global_bar_vars.edit_user_vars);
        }
    },
    maybeUserProfile(profileVars) {
        const $targetElement = window.jQuery('#profile-page > .wp-header-end');
        if (!$targetElement.length) {
            console.warn('FluentCart: Target element "#profile-page > .wp-header-end" not found');
            return;
        }

        try {
            const $link = window.jQuery(`<a 
            style="background: #00009f;color: white;border-color: #00009f;"
            target="_blank"
            class="page-title-action" 
            href="${profileVars.fct_profile_url}">View FluentCart Profile</a>`);
            $link.insertBefore($targetElement);
        } catch (error) {
            console.error('FluentCart: Error inserting profile link:', error);
        }
    }
}
FluentCartViewProfile.init();