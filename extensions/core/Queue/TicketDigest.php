<?php namespace IPS\vssupport\extensions\core\Queue;

use IPS\Db;
use IPS\Email;
use IPS\Member;
use IPS\Task\Queue\OutOfRangeException as QueueOutOfRangeException;
use IPS\vssupport\Moderators;
use IPS\vssupport\TicketStatus;
use OutOfRangeException;

use function IPS\vssupport\query_all;
use function IPS\vssupport\query_one;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class TicketDigest extends \IPS\Extensions\QueueAbstract
{
	/**
	 * Parse data before queuing
	 *
	 * @param	array	$data
	 * @return	array|null
	 */
	public function preQueueData(?array $data) : ?array
	{
		$count = query_one(Moderators::select(Db::i(), 'COUNT(*)'));
		return ['count' => intval($count), 'done' => 0];
	}

	/**
	 * Run Background Task
	 *
	 * @param	array						$data	Data as it was passed to \IPS\Task::queue()
	 * @param	int							$offset	Offset
	 * @return	int							New offset
	 * @throws	QueueOutOfRangeException	        Indicates offset doesn't exist and thus task is complete
	 */
	public function run(array &$data, int $offset) : int
	{
		$db = Db::i();

		$oldOffset = $offset;

		//$ticketOpenQuery = '!(t.status & '.TicketStatus::__FLAG_CLOSED.')';
		$ticketOpenQuery = 't.status = '.TicketStatus::Open;
		$orderByLastAction = '(SELECT MAX(created) FROM vssupport_ticket_action_history WHERE ticket = t.id) DESC'; //TODO(Rennorb) @perf

		try {
		$query = query_all(Moderators::select($db, 'm.*', 'm.member_id > '.$offset, 'm.member_id ASC', 10));
		foreach($query as $row) {
			$assignedAndOpen = query_all($db->select('t.id, t.subject, t.priority, t.created, s.name_key AS status, c.name_key as category',
				['vssupport_tickets', 't'], "$ticketOpenQuery AND t.assigned_to = {$row['member_id']}", $orderByLastAction)
				->join(['vssupport_ticket_stati', 's'], 's.id = t.status')
				->join(['vssupport_ticket_categories', 'c'], 'c.id = t.category')
			);

			$emailParams = [
				$assignedAndOpen,
			];

			$member = Member::constructFromData($row);
			$email = Email::buildFromTemplate('vssupport', 'moderator_digest', $emailParams, Email::TYPE_TRANSACTIONAL);
			$email->send($member, returnException: true);

			$offset = intval($row['member_id']);
			$data['done']++;
		}
	}catch(\Exception $ex) {
		die(var_dump($ex));
	}

		if($oldOffset === $offset)  throw new QueueOutOfRangeException();

		return $offset;
	}
	
	/**
	 * Get Progress
	 *
	 * @param	array					$data	Data as it was passed to \IPS\Task::queue()
	 * @param	int						$offset	Offset
	 * @return	array('text' => 'Doing something...', 'complete' => 50)	Text explaining task and percentage complete
	 * @throws	OutOfRangeException	Indicates offset doesn't exist and thus task is complete
	 */
	public function getProgress(array $data, int $offset) : array
	{
		$lang = Member::loggedIn()->language();
		$progress = ($data['done'] / $data['count']) * 100;
		return array('text' => $lang->addToStack('sending_moderator_digest'), 'complete' => $progress);
	}
}