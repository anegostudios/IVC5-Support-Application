<?php namespace IPS\vssupport;

use IPS\Data\Cache;
use IPS\Db;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class Color {
	/**
	 * rgb values must be provided in range of 0-255
	 */
	static function isLightColor(int $r, int $g, int $b) : bool { return static::rgbToOkLabGamut($r, $g, $b)['L'] > 0.71; }

	// https://bottosson.github.io/posts/gamutclipping/
	/**
	 * rgb values must be provided in range of 0-255
	 * @return array{L:float, a:float, b:float}
	 */
	static function rgbToOkLabGamut(int $r, int $g, int $b) : array
	{
		$r = static::gammaToLinear($r / 255); $g = static::gammaToLinear($g / 255); $b = static::gammaToLinear($b / 255);
		$l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
		$m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
		$s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
		$l = pow($l, 1/3); $m = pow($m, 1/3); $s = pow($s, 1/3);
		return [
			'L' => $l * +0.2104542553 + $m * +0.7936177850 + $s * -0.0040720468,
			'a' => $l * +1.9779984951 + $m * -2.4285922050 + $s * +0.4505937099,
			'b' => $l * +0.0259040371 + $m * +0.7827717662 + $s * -0.8086757660,
		];
	}
	static function gammaToLinear($c) { return $c >= 0.04045 ? pow(($c + 0.055) / 1.055, 2.4) : $c / 12.92; }


	/** Converts `#rrggbb` or `#rrggbbaa` to its numeric representation. */
	static function fromColorInputString(string $rgbColorString) : int
	{
		$rgbColorString = substr($rgbColorString, 1 /* skip initial # */);
		$color = hexdec('0x'.$rgbColorString);
		if(strlen($rgbColorString) <= 6) $color = $color << 8 | 0xff; // fill alpha if missing
		return $color;
	}

	static function toRgbaHexString(int $color) : string
	{
		return str_pad(dechex($color), 8, '0', STR_PAD_LEFT);
	}

	/** Tries to get the color css block from the cache, or generate it if required. */
	static function getLabelColorCssBlock() : string
	{
		try {
			return Cache::i()->getWithExpire('vssupport_stati_css', true);
		}
		catch(\OutOfRangeException) { }

		return static::updateLabelColorCssBlock(Db::i());
	}

	static function updateLabelColorCssBlock(Db $db) : string
	{
		$cssLight = '';
		$cssDark = '';
		$cssLabel = '';

		$q = $db->select('id, color_light_bg_rgb, color_light_fg_rgb, color_dark_bg_rgb, color_dark_fg_rgb', 'vssupport_ticket_stati', order: 'id ASC');
		foreach($q as $row) {
			$lightBg = Color::toRgbaHexString($row['color_light_bg_rgb']);
			$lightFg = Color::toRgbaHexString($row['color_light_fg_rgb']);
			$darkBg  = Color::toRgbaHexString($row['color_dark_bg_rgb']);
			$darkFg  = Color::toRgbaHexString($row['color_dark_fg_rgb']);
			$cssLight .= "--tick-status-c-{$row['id']}-bg:#$lightBg;--tick-status-c-{$row['id']}-fg:#$lightFg;";
			$cssDark .= "--tick-status-c-{$row['id']}-bg:#$darkBg;--tick-status-c-{$row['id']}-fg:#$darkFg;";
			$cssLabel .= ".status-label.status-{$row['id']}{background-color:var(--tick-status-c-{$row['id']}-bg);color:var(--tick-status-c-{$row['id']}-fg);}";
		}
		
		$css = '[data-ips-scheme="light"]{'.$cssLight.'}[data-ips-scheme="dark"]{'.$cssDark.'}'.$cssLabel;

		Cache::i()->storeWithExpire('vssupport_stati_css', $css, (new \IPS\DateTime)->add(new \DateInterval('P6M')), true);

		return $css;
	}
}