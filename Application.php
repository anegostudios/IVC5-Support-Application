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
use IPS\Db\Select;

class MessageFlags {
	public const Internal = 1 << 0;
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

class Application extends SystemApplication { }
