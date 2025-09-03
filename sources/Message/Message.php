<?php namespace IPS\vssupport;

use IPS\Db;
use IPS\Patterns\ActiveRecord;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

/**
 * @property int $id
 * @property int $ticket
 * @property string $text
 * @property string $text_searchable
 * @property int $flags
 */
class Message extends ActiveRecord
{
	public static ?string $databaseTable = 'vssupport_messages';

	public static function load(int|string|null $id, string $idField = null, mixed $extraWhereClause = null) : ActiveRecord|static
	{
		$row = self::db()->select('m.id, m.ticket, m.text, m.text_searchable, m.flags, HEX(t.hash) AS hash', [self::$databaseTable, 'm'], 'm.id = '.intval($id))
			->join(['vssupport_tickets', 't'], 't.id = m.ticket')
			->first();

		$obj = new self();
		$obj->_new = false;
		$obj->_data['id']             = $row['id'];
		$obj->_data['ticket']         = $row['ticket'];
		$obj->_data['ticketHash']     = $row['hash'];
		$obj->_data['text']           = $row['text'];
		$obj->_data['textSearchable'] = $row['text_searchable'];
		$obj->_data['flags']          = $row['flags'];

		return $obj;
	}

	public static function insert(Db $db, int $ticketId, string $text, int $flags) : Message
	{
		$textSearchable = strip_tags($text);
		$messageId = $db->insert(self::$databaseTable, [
			'ticket'          => $ticketId,
			'text'            => $text,
			'text_searchable' => $textSearchable,
			'flags'           => $flags,
		]);

		$obj = new self();
		$obj->_new = false;
		$obj->_data['id']             = $messageId;
		$obj->_data['ticket']         = $ticketId;
		$obj->_data['text']           = $text;
		$obj->_data['textSearchable'] = $textSearchable;
		$obj->_data['flags']          = $flags;

		return $obj;
	}
}