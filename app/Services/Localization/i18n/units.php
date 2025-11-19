<?php
/**
 * Units
 *
 * Returns a multidimensional array of measurement units and their labels.
 * Unit labels should be defined in English and translated native through localization files.
 *
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

return array(
	'weight'     => array(
		'kg'  => __( 'kg', 'fluent-cart' ),
		'g'   => __( 'g', 'fluent-cart' ),
		'lbs' => __( 'lbs', 'fluent-cart' ),
		'oz'  => __( 'oz', 'fluent-cart' ),
	),
	'dimensions' => array(
		'm'  => __( 'm', 'fluent-cart' ),
		'cm' => __( 'cm', 'fluent-cart' ),
		'mm' => __( 'mm', 'fluent-cart' ),
		'in' => __( 'in', 'fluent-cart' ),
		'yd' => __( 'yd', 'fluent-cart' ),
	),
);
