<?php

namespace FluentCart\App\Services\Translations;

use FluentCart\App\Services\DateTime\DateFormatter;

class TransStrings
{
    public static function getStrings(): array
    {
        $translations = require 'admin-translation.php';
        return apply_filters("fluent_cart/admin_translations", $translations, []);
    }

    public static function blockStrings(): array
    {
        $translations = require 'block-editor-translation.php';
        return apply_filters("fluent_cart/blocks_translations", $translations, []);
    }


    public static function getShopAppBlockEditorString(): array
    {

        return [
            'Also search in Content'            => _x('Also search in Content', 'Shop App Block Editor', 'fluent-cart'),
            'Apply Filter'                      => _x('Apply Filter', 'Shop App Block Editor', 'fluent-cart'),
            // 'Add to Cart'                       => _x('Add to Cart', 'Shop App Block Editor', 'fluent-cart'),
            'Default'                           => _x('Default', 'Shop App Block Editor', 'fluent-cart'),
            'Display Name For Filter'           => _x('Display Name For Filter', 'Shop App Block Editor', 'fluent-cart'),
            'Enable Default Filtering'          => _x('Enable Default Filtering', 'Shop App Block Editor', 'fluent-cart'),
            'Enable Filtering'                  => _x('Enable Filtering', 'Shop App Block Editor', 'fluent-cart'),
            'Enable'                            => _x('Enable', 'Shop App Block Editor', 'fluent-cart'),
            'Enabled?'                          => _x('Enabled?', 'Shop App Block Editor', 'fluent-cart'),
            'Filter Option'                     => _x('Filter Option', 'Shop App Block Editor', 'fluent-cart'),
            'Grid'                              => _x('Grid', 'Shop App Block Editor', 'fluent-cart'),
            'List'                              => _x('List', 'Shop App Block Editor', 'fluent-cart'),
            'Numbers'                           => _x('Numbers', 'Shop App Block Editor', 'fluent-cart'),
            'Option'                            => _x('Option', 'Shop App Block Editor', 'fluent-cart'),
            'Paginator'                         => _x('Paginator', 'Shop App Block Editor', 'fluent-cart'),
            'Per Page'                          => _x('Per Page', 'Shop App Block Editor', 'fluent-cart'),
            'Product Box Grid Size'             => _x('Product Box Grid Size', 'Shop App Block Editor', 'fluent-cart'),
            'Product Categories'                => _x('Product Categories', 'Shop App Block Editor', 'fluent-cart'),
            'Product Grid Size'                 => _x('Product Grid Size', 'Shop App Block Editor', 'fluent-cart'),
            'Product Types'                     => _x('Product Types', 'Shop App Block Editor', 'fluent-cart'),
            'Product'                           => _x('Product', 'Shop App Block Editor', 'fluent-cart'),
            'Range Filter Only works in pages.' => _x('Range Filter Only works in pages.', 'Shop App Block Editor', 'fluent-cart'),
            'Scroll'                            => _x('Scroll', 'Shop App Block Editor', 'fluent-cart'),
            'Search Grid Size'                  => _x('Search Grid Size', 'Shop App Block Editor', 'fluent-cart'),
            'Search'                            => _x('Search', 'Shop App Block Editor', 'fluent-cart'),
            'View mode'                         => _x('View mode', 'Shop App Block Editor', 'fluent-cart'),
            'Wildcard Filter'                   => _x('Wildcard Filter', 'Shop App Block Editor', 'fluent-cart'),

            'Primary'                => _x('Primary', 'Shop App Block Editor', 'fluent-cart'),
            'Product Heading'        => _x('Product Heading', 'Shop App Block Editor', 'fluent-cart'),
            'Text'                   => _x('Text', 'Shop App Block Editor', 'fluent-cart'),
            'Border'                 => _x('Border', 'Shop App Block Editor', 'fluent-cart'),
            'Badge Count Background' => _x('Badge Count Background', 'Shop App Block Editor', 'fluent-cart'),
            'Badge Count'            => _x('Badge Count', 'Shop App Block Editor', 'fluent-cart'),
            'Badge Count Border'     => _x('Badge Count Border', 'Shop App Block Editor', 'fluent-cart'),


            'Background'                => _x('Background', 'Shop App Block Editor', 'fluent-cart'),
            'Input Border'              => _x('Input Border', 'Shop App Block Editor', 'fluent-cart'),
            'Input Focus Border'        => _x('Input Focus Border', 'Shop App Block Editor', 'fluent-cart'),
            'Heading'                   => _x('Heading', 'Shop App Block Editor', 'fluent-cart'),
            'Label'                     => _x('Label', 'Shop App Block Editor', 'fluent-cart'),
            'Item Border'               => _x('Item Border', 'Shop App Block Editor', 'fluent-cart'),
            'Reset Button Bg'           => _x('Reset Button Bg', 'Shop App Block Editor', 'fluent-cart'),
            'Reset Button'              => _x('Reset Button', 'Shop App Block Editor', 'fluent-cart'),
            'Reset Button Border'       => _x('Reset Button Border', 'Shop App Block Editor', 'fluent-cart'),
            'Reset Button Hover Bg'     => _x('Reset Button Hover Bg', 'Shop App Block Editor', 'fluent-cart'),
            'Reset Button Hover'        => _x('Reset Button Hover', 'Shop App Block Editor', 'fluent-cart'),
            'Reset Button Hover Border' => _x('Reset Button Hover Border', 'Shop App Block Editor', 'fluent-cart'),
            'Checkbox'                  => _x('Checkbox', 'Shop App Block Editor', 'fluent-cart'),
            'Checkbox Active'           => _x('Checkbox Active', 'Shop App Block Editor', 'fluent-cart'),
            'Checkmark Bg'              => _x('Checkmark Bg', 'Shop App Block Editor', 'fluent-cart'),
            'Checkmark Border'          => _x('Checkmark Border', 'Shop App Block Editor', 'fluent-cart'),
            'Checkmark Active Bg'       => _x('Checkmark Active Bg', 'Shop App Block Editor', 'fluent-cart'),
            'Checkmark Active Border'   => _x('Checkmark Active Border', 'Shop App Block Editor', 'fluent-cart'),
            'Checkmark After Border'    => _x('Checkmark After Border', 'Shop App Block Editor', 'fluent-cart'),
            'Range Slider Connect Bg'   => _x('Range Slider Connect Bg', 'Shop App Block Editor', 'fluent-cart'),

        ];
    }

    public static function getCustomerProfileString(): array
    {
        $translations = require 'customer-profile-translation.php';
        return apply_filters("fluent_cart/customer_profile_translations", $translations, []);
    }

    public static function singleProductPageString(): array
    {
        return [
            'In Stock'        => _x('In Stock', 'Single Product Page', 'fluent-cart'),
            'Out Of Stock'    => _x('Out Of Stock', 'Single Product Page', 'fluent-cart'),
            'Package'         => _x('Package', 'Single Product Page', 'fluent-cart'),
            'Dimensions'      => _x('Dimensions', 'Single Product Page', 'fluent-cart'),
            'Weight'          => _x('Weight', 'Single Product Page', 'fluent-cart'),
            'Shipping Weight' => _x('Shipping Weight', 'Single Product Page', 'fluent-cart'),
        ];
    }

    public static function checkoutPageString()
    {
        $translations = require 'checkout-translation.php';
        return apply_filters("fluent_cart/checkout_translations", $translations, []);
    }

    public static function paymentsString()
    {
        $translations = require 'payments-translation.php';
        return apply_filters("fluent_cart/payments_translations", $translations, []);
    }

    public static function elStrings(): array
    {
        return [
            'name' => get_locale(),
            'el'   => [
                'breadcrumb'  => [
                    'label' => __('Breadcrumb', 'fluent-cart'),
                ],
                'colorpicker' => [
                    'confirm'              => __('OK', 'fluent-cart'),
                    'clear'                => __('Clear', 'fluent-cart'),
                    'defaultLabel'         => __('color picker', 'fluent-cart'),
                    'description'          => __('current color is {color}. press enter to select a new color.', 'fluent-cart'),
                    'alphaLabel'           => __('pick alpha value', 'fluent-cart'),
                    'alphaDescription'     => __('alpha {alpha}, current color is {color}', 'fluent-cart'),
                    'hueLabel'             => __('pick hue value', 'fluent-cart'),
                    'hueDescription'       => __('hue {hue}, current color is {color}', 'fluent-cart'),
                    'svLabel'              => __('pick saturation and brightness value', 'fluent-cart'),
                    'svDescription'        => __('saturation {saturation}, brightness {brightness}, current color is {color}', 'fluent-cart'),
                    'predefineDescription' => __('select {value} as the color', 'fluent-cart'),
                ],
                'datepicker'  => [
                    'now'              => __('Now', 'fluent-cart'),
                    'today'            => __('Today', 'fluent-cart'),
                    'cancel'           => __('Cancel', 'fluent-cart'),
                    'clear'            => __('Clear', 'fluent-cart'),
                    'confirm'          => __('OK', 'fluent-cart'),
                    'dateTablePrompt'  => __('Use the arrow keys and enter to select the day of the month', 'fluent-cart'),
                    'monthTablePrompt' => __('Use the arrow keys and enter to select the month', 'fluent-cart'),
                    'yearTablePrompt'  => __('Use the arrow keys and enter to select the year', 'fluent-cart'),
                    'selectedDate'     => __('Selected date', 'fluent-cart'),
                    'selectDate'       => __('Select date', 'fluent-cart'),
                    'selectTime'       => __('Select time', 'fluent-cart'),
                    'startDate'        => __('Start Date', 'fluent-cart'),
                    'startTime'        => __('Start Time', 'fluent-cart'),
                    'endDate'          => __('End Date', 'fluent-cart'),
                    'endTime'          => __('End Time', 'fluent-cart'),
                    'prevYear'         => __('Previous Year', 'fluent-cart'),
                    'nextYear'         => __('Next Year', 'fluent-cart'),
                    'prevMonth'        => __('Previous Month', 'fluent-cart'),
                    'nextMonth'        => __('Next Month', 'fluent-cart'),
                    'year'             => __('', 'fluent-cart'),
                    'month1'           => __('January', 'fluent-cart'),
                    'month2'           => __('February', 'fluent-cart'),
                    'month3'           => __('March', 'fluent-cart'),
                    'month4'           => __('April', 'fluent-cart'),
                    'month5'           => __('May', 'fluent-cart'),
                    'month6'           => __('June', 'fluent-cart'),
                    'month7'           => __('July', 'fluent-cart'),
                    'month8'           => __('August', 'fluent-cart'),
                    'month9'           => __('September', 'fluent-cart'),
                    'month10'          => __('October', 'fluent-cart'),
                    'month11'          => __('November', 'fluent-cart'),
                    'month12'          => __('December', 'fluent-cart'),
                    'weeks'            => [
                        'sun' => __('Sun', 'fluent-cart'),
                        'mon' => __('Mon', 'fluent-cart'),
                        'tue' => __('Tue', 'fluent-cart'),
                        'wed' => __('Wed', 'fluent-cart'),
                        'thu' => __('Thu', 'fluent-cart'),
                        'fri' => __('Fri', 'fluent-cart'),
                        'sat' => __('Sat', 'fluent-cart'),
                    ],
                    'weeksFull'        => [
                        'sun' => __('Sunday', 'fluent-cart'),
                        'mon' => __('Monday', 'fluent-cart'),
                        'tue' => __('Tuesday', 'fluent-cart'),
                        'wed' => __('Wednesday', 'fluent-cart'),
                        'thu' => __('Thursday', 'fluent-cart'),
                        'fri' => __('Friday', 'fluent-cart'),
                        'sat' => __('Saturday', 'fluent-cart'),
                    ],
                    'months'           => [
                        'jan' => __('Jan', 'fluent-cart'),
                        'feb' => __('Feb', 'fluent-cart'),
                        'mar' => __('Mar', 'fluent-cart'),
                        'apr' => __('Apr', 'fluent-cart'),
                        'may' => __('May', 'fluent-cart'),
                        'jun' => __('Jun', 'fluent-cart'),
                        'jul' => __('Jul', 'fluent-cart'),
                        'aug' => __('Aug', 'fluent-cart'),
                        'sep' => __('Sep', 'fluent-cart'),
                        'oct' => __('Oct', 'fluent-cart'),
                        'nov' => __('Nov', 'fluent-cart'),
                        'dec' => __('Dec', 'fluent-cart'),
                    ],
                ],
                'inputNumber' => [
                    'decrease' => __('decrease number', 'fluent-cart'),
                    'increase' => __('increase number', 'fluent-cart'),
                ],
                'select'      => [
                    'loading'     => __('Loading', 'fluent-cart'),
                    'noMatch'     => __('No matching data', 'fluent-cart'),
                    'noData'      => __('No data', 'fluent-cart'),
                    'placeholder' => __('Select', 'fluent-cart'),
                ],
                'mention'     => [
                    'loading' => __('Loading', 'fluent-cart'),
                ],
                'dropdown'    => [
                    'toggleDropdown' => __('Toggle Dropdown', 'fluent-cart'),
                ],
                'cascader'    => [
                    'noMatch'     => __('No matching data', 'fluent-cart'),
                    'loading'     => __('Loading', 'fluent-cart'),
                    'placeholder' => __('Select', 'fluent-cart'),
                    'noData'      => __('No data', 'fluent-cart'),
                ],
                'pagination'  => [
                    'goto'           => __('Go to', 'fluent-cart'),
                    'pagesize'       => __('/page', 'fluent-cart'),
                    'total'          => __('Total {total}', 'fluent-cart'),
                    'pageClassifier' => __('', 'fluent-cart'),
                    'page'           => __('Page', 'fluent-cart'),
                    'prev'           => __('Go to previous page', 'fluent-cart'),
                    'next'           => __('Go to next page', 'fluent-cart'),
                    'currentPage'    => __('page {pager}', 'fluent-cart'),
                    'prevPages'      => __('Previous {pager} pages', 'fluent-cart'),
                    'nextPages'      => __('Next {pager} pages', 'fluent-cart'),
                    //'deprecationWarning' => __('Deprecated usages detected, please refer to the el-pagination documentation for more details', 'fluent-cart'),
                ],
                'dialog'      => [
                    'close' => __('Close this dialog', 'fluent-cart'),
                ],
                'drawer'      => [
                    'close' => __('Close this drawer', 'fluent-cart'),
                ],
                'messagebox'  => [
                    'title'   => __('Message', 'fluent-cart'),
                    'confirm' => __('OK', 'fluent-cart'),
                    'cancel'  => __('Cancel', 'fluent-cart'),
                    'error'   => __('Illegal input', 'fluent-cart'),
                    'close'   => __('Close this dialog', 'fluent-cart'),
                ],
                'upload'      => [
                    'deleteTip' => __('press delete to remove', 'fluent-cart'),
                    'delete'    => __('Delete', 'fluent-cart'),
                    'preview'   => __('Preview', 'fluent-cart'),
                    'continue'  => __('Continue', 'fluent-cart'),
                ],
                'slider'      => [
                    'defaultLabel'           => __('slider between {min} and {max}', 'fluent-cart'),
                    'defaultRangeStartLabel' => __('pick start value', 'fluent-cart'),
                    'defaultRangeEndLabel'   => __('pick end value', 'fluent-cart'),
                ],
                'table'       => [
                    'emptyText'     => __('No Data', 'fluent-cart'),
                    'confirmFilter' => __('Confirm', 'fluent-cart'),
                    'resetFilter'   => __('Reset', 'fluent-cart'),
                    'clearFilter'   => __('All', 'fluent-cart'),
                    'sumText'       => __('Sum', 'fluent-cart'),
                ],
                'tour'        => [
                    'next'     => __('Next', 'fluent-cart'),
                    'previous' => __('Previous', 'fluent-cart'),
                    'finish'   => __('Finish', 'fluent-cart'),
                    'close'    => __('Close this dialog', 'fluent-cart'),
                ],
                'tree'        => [
                    'emptyText' => __('No Data', 'fluent-cart'),
                ],
                'transfer'    => [
                    'noMatch'           => __('No matching data', 'fluent-cart'),
                    'noData'            => __('No data', 'fluent-cart'),
                    'titles'            => [__('List 1', 'fluent-cart'), __('List 2', 'fluent-cart')],
                    'filterPlaceholder' => __('Enter keyword', 'fluent-cart'),
                    'noCheckedFormat'   => __('{total} items', 'fluent-cart'),
                    'hasCheckedFormat'  => __('{checked}/{total} checked', 'fluent-cart'),
                ],
                'image'       => [
                    'error' => __('FAILED', 'fluent-cart'),
                ],
                'pageHeader'  => [
                    'title' => __('Back', 'fluent-cart'),
                ],
                'popconfirm'  => [
                    'confirmButtonText' => __('Yes', 'fluent-cart'),
                    'cancelButtonText'  => __('No', 'fluent-cart'),
                ],
                'carousel'    => [
                    'leftArrow'  => __('Carousel arrow left', 'fluent-cart'),
                    'rightArrow' => __('Carousel arrow right', 'fluent-cart'),
                    'indicator'  => __('Carousel switch to index {index}', 'fluent-cart'),
                ],
            ],
        ];
    }

    /**
     * Names and formats for every date FluentCart renders, PHP and JS alike.
     *
     * One filter covers the whole map: the month/weekday names, and the
     * formats derived from the store's WordPress date/time settings. Formats
     * are PHP date() strings -- the dialect Settings > General shows and
     * get_option() returns; the SPA payload converts them to Day.js tokens.
     *
     * The NAMES follow the same source setting as the formats. On 'wordpress'
     * they come from $wp_locale -- the exact arrays wp_date() renders with --
     * so the PHP side and the SPA cannot disagree about what August is called.
     * They used to come from FluentCart's own text domain while PHP rendered
     * through wp_date(), so one locale produced two answers:
     *
     *     PHP  wp_date('M')                       ->  'Aout'   (WP core pack)
     *     JS   _x('Aug', 'monthsShort', ...)      ->  'Aug'    (untranslated)
     *
     * and fluent-cart ships only a .pot, so the JS side was English on any
     * store that had not sourced its own catalogue. $wp_locale also gets the
     * hard cases right for free: German abbreviations keep their trailing
     * period ('Aug.') and the four months German never abbreviates come back
     * in full ('Marz', 'Mai', 'Juni', 'Juli').
     *
     * On 'fluent_cart' the text-domain names stay, because that source exists
     * to reproduce what each side rendered before these settings landed.
     *
     * Cached per request AND per locale: DateFormatter reads this for every
     * date it renders, and rebuilding ~30 gettext lookups inside a table loop
     * is wasted work -- but the names now vary with the locale, so a request
     * that switches locale (switch_to_locale() in a per-recipient mailer, a
     * multilingual plugin) must not be served the first locale's month names.
     */
    public static function dateTimeStrings(bool $refresh = false): array
    {
        static $cached = [];

        $locale = determine_locale();

        if (!$refresh && isset($cached[$locale])) {
            return $cached[$locale];
        }

        $strings = [
            'weekdays'      => array(
                'sunday'    => _x('Sunday', 'weekdays', 'fluent-cart'),
                'monday'    => _x('Monday', 'weekdays', 'fluent-cart'),
                'tuesday'   => _x('Tuesday', 'weekdays', 'fluent-cart'),
                'wednesday' => _x('Wednesday', 'weekdays', 'fluent-cart'),
                'thursday'  => _x('Thursday', 'weekdays', 'fluent-cart'),
                'friday'    => _x('Friday', 'weekdays', 'fluent-cart'),
                'saturday'  => _x('Saturday', 'weekdays', 'fluent-cart'),
            ),
            'months'        => array(
                'January'   => _x('January', 'months', 'fluent-cart'),
                'February'  => _x('February', 'months', 'fluent-cart'),
                'March'     => _x('March', 'months', 'fluent-cart'),
                'April'     => _x('April', 'months', 'fluent-cart'),
                'May'       => _x('May', 'months', 'fluent-cart'),
                'June'      => _x('June', 'months', 'fluent-cart'),
                'July'      => _x('July', 'months', 'fluent-cart'),
                'August'    => _x('August', 'months', 'fluent-cart'),
                'September' => _x('September', 'months', 'fluent-cart'),
                'October'   => _x('October', 'months', 'fluent-cart'),
                'November'  => _x('November', 'months', 'fluent-cart'),
                'December'  => _x('December', 'months', 'fluent-cart')
            ),
            'weekdaysShort' => array(
                'sun' => _x('Sun', 'weekdaysShort', 'fluent-cart'),
                'mon' => _x('Mon', 'weekdaysShort', 'fluent-cart'),
                'tue' => _x('Tue', 'weekdaysShort', 'fluent-cart'),
                'wed' => _x('Wed', 'weekdaysShort', 'fluent-cart'),
                'thu' => _x('Thu', 'weekdaysShort', 'fluent-cart'),
                'fri' => _x('Fri', 'weekdaysShort', 'fluent-cart'),
                'sat' => _x('Sat', 'weekdaysShort', 'fluent-cart')
            ),
            'monthsShort'   => array(
                'jan' => _x('Jan', 'monthsShort', 'fluent-cart'),
                'feb' => _x('Feb', 'monthsShort', 'fluent-cart'),
                'mar' => _x('Mar', 'monthsShort', 'fluent-cart'),
                'apr' => _x('Apr', 'monthsShort', 'fluent-cart'),
                'may' => _x('May', 'monthsShort', 'fluent-cart'),
                'jun' => _x('Jun', 'monthsShort', 'fluent-cart'),
                'jul' => _x('Jul', 'monthsShort', 'fluent-cart'),
                'aug' => _x('Aug', 'monthsShort', 'fluent-cart'),
                'sep' => _x('Sep', 'monthsShort', 'fluent-cart'),
                'oct' => _x('Oct', 'monthsShort', 'fluent-cart'),
                'nov' => _x('Nov', 'monthsShort', 'fluent-cart'),
                'dec' => _x('Dec', 'monthsShort', 'fluent-cart')
            ),
            'am'            => __('AM', 'fluent-cart'),
            'pm'            => __('PM', 'fluent-cart'),
            'numericSystem' => _x('0_1_2_3_4_5_6_7_8_9', 'numeric system - Sequence must need to maintained', 'fluent-cart'),
            'formats'       => DateFormatter::defaultFormats(),
            // The bundle carries its own legacy literals, which are not the same
            // as PHP's ('h:mm A' against 'h:i A' -- unpadded against padded). On
            // the FluentCart source each side must reproduce its OWN past output,
            // so the bundle needs to know which source is active rather than just
            // reading the converted formats above.
            'formats_source' => DateFormatter::usesWordPressFormats() ? 'wordpress' : 'fluent_cart',
            // Read by the admin bundle and the customer dashboard, which pick a
            // Day.js timezone from this rather than always rendering in the
            // browser's. 'site' is only consulted when source is 'wordpress'.
            'timezone'      => array(
                'source' => DateFormatter::usesWordPressTimezone() ? 'wordpress' : 'fluent_cart',
                'site'   => wp_timezone_string(),
            ),
        ];

        if (DateFormatter::usesWordPressFormats()) {
            $strings = static::applyWordPressLocaleNames($strings);
        }

        return ($cached[$locale] = apply_filters('fluent_cart/date_time_strings', $strings));
    }

    /**
     * Swap the text-domain month/weekday names for WordPress core's own.
     *
     * $wp_locale is what wp_date() renders with, so taking the names from the
     * same object is what makes the PHP and JS sides agree by construction
     * rather than by two catalogues happening to match.
     *
     * The KEYS are left exactly as built above. The SPA reads these maps by
     * position (Object.values() against an English fallback list, see
     * localeNames() in utils/dateFormats.js), and other consumers read them by
     * key, so only the values may change here.
     *
     * Degrades to the text-domain names if $wp_locale is missing or shaped
     * unexpectedly -- this runs on every rendered date and must never fatal.
     *
     * @param array<string, mixed> $strings
     * @return array<string, mixed>
     */
    protected static function applyWordPressLocaleNames(array $strings): array
    {
        global $wp_locale;

        if (!($wp_locale instanceof \WP_Locale)) {
            return $strings;
        }

        // get_weekday() is 0-indexed from Sunday; get_month() takes '01'-'12'.
        $weekdayIndex = 0;
        foreach ($strings['weekdays'] as $key => $unused) {
            $name = $wp_locale->get_weekday($weekdayIndex);
            if ($name !== '') {
                $strings['weekdays'][$key] = $name;
                // The abbreviation is looked up BY the full name, so it has to
                // be resolved from $wp_locale's own name, not from our key.
                if (isset($strings['weekdaysShort'][substr($key, 0, 3)])) {
                    $strings['weekdaysShort'][substr($key, 0, 3)] = $wp_locale->get_weekday_abbrev($name);
                }
            }
            $weekdayIndex++;
        }

        $monthIndex = 1;
        foreach ($strings['months'] as $key => $unused) {
            $name = $wp_locale->get_month(str_pad((string)$monthIndex, 2, '0', STR_PAD_LEFT));
            if ($name !== '') {
                $strings['months'][$key] = $name;
                $shortKey = strtolower(substr($key, 0, 3));
                if (isset($strings['monthsShort'][$shortKey])) {
                    $strings['monthsShort'][$shortKey] = $wp_locale->get_month_abbrev($name);
                }
            }
            $monthIndex++;
        }

        $am = $wp_locale->get_meridiem('AM');
        $pm = $wp_locale->get_meridiem('PM');
        $strings['am'] = $am !== '' ? $am : $strings['am'];
        $strings['pm'] = $pm !== '' ? $pm : $strings['pm'];

        return $strings;
    }
}
