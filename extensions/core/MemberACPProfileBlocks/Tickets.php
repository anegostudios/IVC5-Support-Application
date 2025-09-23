<?php namespace IPS\vssupport\extensions\core\MemberACPProfileBlocks;

use IPS\core\MemberACPProfile\Block;
use IPS\Db;
use IPS\Member;
use IPS\Theme;

use function IPS\vssupport\query_all;
use function IPS\vssupport\query_one;

if(!defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}


class Tickets extends Block
{
	/**
	 * Optionally show this profile block on a tab outside of your application.
	 * Example: to show on the main profile tab, set this to 'core_Main'.
	 *
	 * @var string
	 */
	public static string $displayTab = 'core_Main';

	/**
	 * Used in conjunction with static::$displayTab.
	 * If showing on a profile tab outside of your application,
	 * set this to 'left' or 'main' to place it in the proper column.
	 *
	 * @var string
	 */
	public static string $displayColumn = 'left';

	public function output(): string
	{
		$member = Member::loggedIn();
		$db = Db::i();
		$totalCount = query_one($db->select('COUNT(*)', 'vssupport_tickets', 'issuer_id = '.($member->member_id)));
		$tickets = query_all(
			$db->select('t.id, t.subject, t.priority, t.created, t.category, t.status', ['vssupport_tickets', 't'], 't.issuer_id = '.($member->member_id), 't.created DESC', 10)
			->join(['vssupport_ticket_stati', 's'], 's.id = t.status')
		);
		return Theme::i()->getTemplate('tickets', 'vssupport', 'admin')->profileBlockList($tickets, $totalCount, $member);
	}
}