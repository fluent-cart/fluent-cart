<?php

namespace FluentCart\App\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\Framework\Support\Arr;

class FrontendTheme {

	public static function applyTheme() {
		add_action( 'wp_enqueue_scripts', function () {
			if ( ! is_admin() ) {
				( new static() )->apply();
			}
		} );
	}

	protected function apply() {

		$themeData = $this->get();
		$style = $this->prepareInlineStyle($themeData);

		if (!empty($style)){
			wp_register_style( 'fluent-cart-inline-style', false, [], FLUENTCART_VERSION );
			wp_enqueue_style( 'fluent-cart-inline-style' );
			wp_add_inline_style( 'fluent-cart-inline-style', "body{ {$style}}" );
		}
	}

	protected function get(): array {
		return ( new StoreSettings() )->get( 'frontend_theme', [] );
	}

	protected function prepareInlineStyle(array $data): string {
		$style = "";
		foreach ($data as $name => $value) {
			if(!empty($value)) {
				$style .= $name . ':' . $value . ';';
			}
		}
		return $style;
	}



}