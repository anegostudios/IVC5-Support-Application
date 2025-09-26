<?php namespace IPS\vssupport;

use IPS\Db;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class Ticket {
	public const HASH_BYTE_LEN = 16;

	//NOTE(Rennorb): In theory, IVC has the ReadMarkers Trait, but that is so deep into the MVC trench that I cannot be bothered trying to make it work without implementing 50 things i don't care about.
	// So we just do it ourself.

	static function markRead(int $id, int $memberId)
	{
		$time = time();

		Db::i()->replace('core_item_markers', [
			'item_app' => 'vssupport',
			'item_key' => md5(serialize(['item_app_key_1' => $id])),
			'item_member_id' => $memberId,
			'item_global_reset' => 0,
			'item_app_key_1' => $id,
			//NOTE(Rennorb): These are just 32 bit columns. If we want this to last any appreciable amount of time, we need to use both for the timestamp.
			// We use the columns and don't do some wired json encoding stuff so we can join back on them without having to fetch rows individually.
			// key_2 = hi, key_3 = lo   `((mark.item_app_key_2 << 32) | mark.item_app_key_3)`
			// :ReadMarkerTimestamps
			'item_app_key_2' => $time >> 32,
			'item_app_key_3' => $time & 0xffffffff,
		]);
	}
}