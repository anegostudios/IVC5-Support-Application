<?php
/**
 * @brief		VS Support Application Class
 * @author		Rennorb
 * @copyright	(c) 2025 Anego Studios
 * @package		Invision Community
 * @subpackage	VS Support
 * @since		18 Aug 2025
 * @version		
 */
 
namespace IPS\vssupport;

use IPS\Application as SystemApplication;
use IPS\Db;
use IPS\Db\Select;

class MessageFlags {
	public const Internal    = 1 << 0;
	public const EmailIngest = 1 << 1;
}

class TicketFlags {
	public const Locked = 1 << 0;
}

/**
 * @return mixed Returns null in case of no result.
 */
function query_one(Select $query) : mixed
{
	$query->rewind();
	return $query->valid() ? $query->current() : null;
}

function query_all(Select $query) : array
{
	$data = [];
	for($query->rewind(); $query->valid(); $query->next()) {
		$data[] = $query->current();
	}
	return $data;
}

/**
 * Returns an array keyed by the first column with values from the second column.
 */
function query_all_assoc(Select $query) : array
{
	$data = [];
	for($query->rewind(); $query->valid(); $query->next()) {
		$r = $query->current();
		$data[reset($r)] = next($r);
	}
	return $data;
}

function query_first_column(Select $query) : array
{
	$data = [];
	for($query->rewind(); $query->valid(); $query->next()) {
		$r = $query->current();
		$data[] = reset($r);
	}
	return $data;
}

class ActionKind {
	public const Message          =  1;
	public const StatusChange     =  2;
	public const PriorityChange   =  3;
	public const CategoryChange   =  4;
	public const Assigned         =  5;
	public const LockedChange     =  6;
	public const UserDeleted      =  7;
	public const UserEmailChanged =  8;
	public const UserMerged       =  9;
	public const UserValidated    = 10;
	public const UserNameChanged  = 11;
}

function log_ticket_action(Db $db, int $ticketId, int $actionKind, int $initiatorMemberId, int $referenceId = null) : void
{
	$db->insert('vssupport_ticket_action_history', [
		'ticket'       => $ticketId,
		'kind'         => $actionKind,
		'reference_id' => $referenceId,
		'initiator'    => $initiatorMemberId,
	]);
}

class UrlReflection {
	static ?\ReflectionMethod $reconstructUrlFromData = null;
	static function reconstructUrlFromData(\IPS\Http\Url $url)
	{
		if(!static::$reconstructUrlFromData) {
			static::$reconstructUrlFromData = new \ReflectionMethod('IPS\Http\Url', 'reconstructUrlFromData');
			static::$reconstructUrlFromData->setAccessible(true);
		}
		static::$reconstructUrlFromData->invoke($url);
	}
}

class TicketStatus {
	public const Open          =  0;
	public const __MAX_BUILTIN = 15;
}

class StatusFlags {
	public const TicketResolved = 1 << 0;
}


class Application extends SystemApplication { }
