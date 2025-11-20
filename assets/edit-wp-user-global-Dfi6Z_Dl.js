const i={init(){window.fluentcart_edit_user_global_bar_vars.edit_user_vars&&window.fluentcart_edit_user_global_bar_vars.edit_user_vars.fct_profile_url&&this.maybeUserProfile(window.fluentcart_edit_user_global_bar_vars.edit_user_vars)},maybeUserProfile(t){const e=window.jQuery("#profile-page > .wp-header-end");if(!e.length){console.warn('FluentCart: Target element "#profile-page > .wp-header-end" not found');return}try{window.jQuery(`<a 
            style="background: #00009f;color: white;border-color: #00009f;"
            target="_blank"
            class="page-title-action" 
            href="${t.fct_profile_url}">View FluentCart Profile</a>`).insertBefore(e)}catch(r){console.error("FluentCart: Error inserting profile link:",r)}}};i.init();
