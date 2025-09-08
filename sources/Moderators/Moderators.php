<?php namespace IPS\vssupport;

use IPS\Db;
use IPS\Patterns\ActiveRecord;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

// This so there is a centralized place to do the moderator selection, in case we want to go for a separate user role at some point.

class Moderators {
	/**
	 * @param string $select The select statement to be used. `m` is the name of the members table.
	 */
	public static function select(Db $db, string $select, null|array|string $where = null, ?string $order = null, null|int|array $limit = null) : Db\Select
	{
		return $db->select($select, ['core_moderators', 'mod'], $where, $order, $limit)
			->join(['core_members', 'm'], "(mod.type = 'm' AND m.member_id = mod.id) OR (mod.type = 'g' AND m.member_group_id = mod.id)", 'JOIN');
	}
}
