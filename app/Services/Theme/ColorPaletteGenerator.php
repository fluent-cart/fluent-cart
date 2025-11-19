<?php

namespace FluentCart\App\Services\Theme;

class ColorPaletteGenerator {

	private function hexToRgb($hex) {
		$hex = str_replace("#", "", $hex);

		if (strlen($hex) == 3) {
			$r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
			$g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
			$b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
		} else {
			$r = hexdec(substr($hex, 0, 2));
			$g = hexdec(substr($hex, 2, 2));
			$b = hexdec(substr($hex, 4, 2));
		}

		return array('r' => $r, 'g' => $g, 'b' => $b);
	}

	private function rgbToHex($r, $g, $b) {
		return sprintf("#%02x%02x%02x", $r, $g, $b);
	}

	private function rgbToHsl($r, $g, $b) {
		$r /= 255;
		$g /= 255;
		$b /= 255;

		$max = max($r, $g, $b);
		$min = min($r, $g, $b);

		$h = 0;
		$s = 0;
		$l = ($max + $min) / 2;
		$d = $max - $min;

		if ($d != 0) {
			$s = $d / (1 - abs(2 * $l - 1));

			switch ($max) {
				case $r:
					$h = 60 * fmod((($g - $b) / $d), 6);
					if ($b > $g) {
						$h += 360;
					}
					break;

				case $g:
					$h = 60 * (($b - $r) / $d + 2);
					break;

				case $b:
					$h = 60 * (($r - $g) / $d + 4);
					break;
			}
		}

		return array(round($h), round($s * 100), round($l * 100));
	}

	private function hslToRgb($h, $s, $l) {
		$h /= 360;
		$s /= 100;
		$l /= 100;

		$r = 0;
		$g = 0;
		$b = 0;

		$c = (1 - abs(2 * $l - 1)) * $s;
		$x = $c * (1 - abs(fmod(($h * 6), 2) - 1));
		$m = $l - ($c / 2);

		if (0 <= $h && $h < 1/6) {
			$r = $c;
			$g = $x;
			$b = 0;
		} else if (1/6 <= $h && $h < 1/3) {
			$r = $x;
			$g = $c;
			$b = 0;
		} else if (1/3 <= $h && $h < 1/2) {
			$r = 0;
			$g = $c;
			$b = $x;
		} else if (1/2 <= $h && $h < 2/3) {
			$r = 0;
			$g = $x;
			$b = $c;
		} else if (2/3 <= $h && $h < 5/6) {
			$r = $x;
			$g = 0;
			$b = $c;
		} else if (5/6 <= $h && $h < 1) {
			$r = $c;
			$g = 0;
			$b = $x;
		} else {
			$r = 0;
			$g = 0;
			$b = 0;
		}

		$r = ($r + $m) * 255;
		$g = ($g + $m) * 255;
		$b = ($b + $m) * 255;

		return array(round($r), round($g), round($b));
	}

	private function contrastColor($color): array {
		$d = 0;

		// Counting the perceptive luminance - human eye favors green color...
		$luminance = (0.299 * $color['r'] + 0.587 * $color['g'] + 0.114 * $color['b']) / 255;

		if ($luminance > 0.5)
			$d = 0; // bright colors - black font
		else
			$d = 255; // dark colors - white font

		return array('r' => $d, 'g' => $d, 'b' => $d);
	}

	public function generateColorPalette($hex) {
		$rgb = $this->hexToRgb($hex);
		$textColor = $this->contrastColor($rgb);

		$palette = array();

		// Title color (darker)
		$titleRgb = $this->contrastColor($rgb);
		$palette['title'] = $this->rgbToHex($titleRgb['r'], $titleRgb['g'], $titleRgb['b']);

		// Subtitle color (lighter)
		$subtitleRgb = $this->contrastColor($rgb);
		$palette['subtitle'] = $this->rgbToHex($subtitleRgb['r'], $subtitleRgb['g'], $subtitleRgb['b']);

		// Button color (more saturated and slightly lighter)
		$buttonRgb = $this->contrastColor($rgb);
		$palette['button'] = $this->rgbToHex($buttonRgb['r'], $buttonRgb['g'], $buttonRgb['b']);

		return $palette;
	}

}
