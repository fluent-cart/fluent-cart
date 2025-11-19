# Git convention & guidelines: 
    - branch name for individual dev is as follow : dev/dev_name ; i.e - dev/atiq . all in lowercase letter.
    - There will be a branch named 'development'
    - All dev will push their working code in 'development' branch and will take pull/merge 2/3 times a day from development branch
    - Release candidate branch name patter is as follows : rc/x.y.z ; i.e - rc/1.0.5
    - After all testing and fixing on RC branch release branch will be created from that RC branch and name as follows - release/X.Y.Z ; i.e - release/1.1.0 
    - Only release branch will be merged into master branch and from master branch Org release will be given.

# Func flows .....

## Add to cart button's func
    - parameter : 2
    - item_id : parent product id
    - var_id : the variation id

## Instant buy now button's func
    - It will add the item in the cart first and then redirect
    - Rest of same as add to cart func




### Adding fluent-cart support in theme  * todo for future

`
function yourtheme_add_fluent_cart_support() {
    add_theme_support( 'fluent-cart' );
}
add_action( 'after_setup_theme', 'yourtheme_add_fluent_cart_support' );
`

 - Theme template file location: Need to finalise it.... :P

### Testing instruction
- Create 200k orders
- Create 10k customers with different timeline
- Create 500 products


### Additional instruction
- To avoid redundancy, consider employing the Service Helper approach for repetitive code,instead of directly using the Model
- When naming Helper, Classes, Functions, Variables, and other elements, prioritize meaningful and descriptive names that accurately convey their purpose
- For implementing search functionality, make use of the provided search trait. This trait offers a standardized and efficient way to handle various types of search operations