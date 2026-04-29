import {onMounted, ref} from 'vue';

export function useFilterPopoverOutsideClickMixin() {

    function handle(element, onCloseCallback) {
        
        jQuery(document).on('click', function (event) {

            if(element === null || element === undefined) {
                return;
            }
            if (jQuery(element).has(event.target).length == 0)
            {
              const modals = jQuery(element).find('[aria-describedby]');
              let modalIds = [];
              modals.each(function() {
                const ariaDescribedById = jQuery(this).attr('aria-describedby');
                modalIds.push(ariaDescribedById);
              });
        
              const clickedElement = jQuery(event.target);
              if (modalIds.includes(clickedElement.attr('id'))) {
                return;
              }else{
                const isChildOfParentWithId = clickedElement.parents().filter(function() {
                  return modalIds.includes(this.id);
                }).length > 0;
                if (isChildOfParentWithId) {
                  return;
                } else {
                  onCloseCallback();
                }
              }
            }
        
          })
    }

    return {handle};
}