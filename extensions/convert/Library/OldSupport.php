<?php namespace IPS\vssupport\extensions\convert\Library;

use IPS\convert\App;
use \IPS\convert\Library as CoreLibrary;
use IPS\Db;
use IPS\Lang;
use IPS\vssupport\ActionKind;
use IPS\vssupport\Color;
use IPS\vssupport\MessageFlags;
use IPS\vssupport\TicketFlags;
use IPS\vssupport\TicketStatus;

use function IPS\vssupport\query_one;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class OldSupport extends CoreLibrary
{
	public static string $app = 'vssupport';

	/**
	 * Returns an array of items that we can convert, including the amount of rows stored in the Community Suite as well as the recommend value of rows to convert per cycle
	 *
	 * @param	bool	$rowCounts		enable row counts
	 * @return	array
	 */
	public function menuRows(bool $rowCounts=FALSE): array
	{
		$db = Db::i();
		$return = [];
		$extraRows = $this->software->extraMenuRows();

		foreach($this->getConvertableItems() as $k => $v) {
			switch($k) {
				case 'convertStaffPreferences':
					$return[$k] = [
						'step_title'   => 'convert_staff_preferences',
						'step_method'  => 'convertStaffPreferences',
						'ips_rows'     => $db->select('COUNT(*)', 'vssupport_mod_preferences'),
						'source_rows'  => ['table' => $v['table'], 'where' => $v['where']],
						'per_cycle'    => 200,
						'dependencies' => [],
						'link_type'    => 'vssupport_members',
					];
					break;

				case 'convertCategories':
					$return[$k] = [
						'step_title'   => 'convert_categories',
						'step_method'  => 'convertCategories',
						'ips_rows'     => $db->select('COUNT(*)', 'vssupport_ticket_categories'),
						'source_rows'  => ['table' => $v['table'], 'where' => $v['where']],
						'per_cycle'    => 200,
						'dependencies' => [],
						'link_type'    => 'vssupport_ticket_categories',
					];
					break;

				case 'convertStati':
					$return[$k] = [
						'step_title'   => 'convert_stati',
						'step_method'  => 'convertStati',
						'ips_rows'     => $db->select('COUNT(*)', 'vssupport_ticket_stati'),
						'source_rows'  => ['table' => $v['table'], 'where' => $v['where']],
						'per_cycle'    => 200,
						'dependencies' => [],
						'link_type'    => 'vssupport_ticket_stati',
					];
					break;

				case 'convertTickets':
					$return[$k] = [
						'step_title'   => 'convert_tickets',
						'step_method'  => 'convertTickets',
						'ips_rows'     => $db->select('COUNT(*)', 'vssupport_tickets'),
						'source_rows'  => ['table' => $v['table'], 'where' => $v['where']],
						'per_cycle'    => 200,
						'dependencies' => ['convertCategories', 'convertStati'],
						'link_type'    => 'vssupport_tickets',
					];
					break;

				case 'convertMessages':
					$return[$k] = [
						'step_title'   => 'convert_messages',
						'step_method'  => 'convertMessages',
						'ips_rows'     => $db->select('COUNT(*)', 'vssupport_messages'),
						'source_rows'  => ['table' => $v['table'], 'where' => $v['where']],
						'per_cycle'    => 200,
						'dependencies' => ['convertTickets'],
						'link_type'    => 'vssupport_messages',
					];
					break;

				case 'convertActions':
					$return[$k] = [
						'step_title'   => 'convert_actions',
						'step_method'  => 'convertActions',
						'ips_rows'     => $db->select('COUNT(*)', 'vssupport_ticket_action_history'),
						'source_rows'  => ['table' => $v['table'], 'where' => $v['where']],
						'per_cycle'    => 200,
						'dependencies' => ['convertTickets'],
						'link_type'    => 'vssupport_ticket_actions',
					];
					break;
			}

			/* Append any extra steps immediately to retain ordering */
			if(isset($v['extra_steps'])) {
				foreach($v['extra_steps'] as $extra) {
					$return[$extra] = $extraRows[$extra];
				}
			}
		}

		/* Run the queries if we want row counts */
		if($rowCounts) {
			return $this->getDatabaseRowCounts($return);
		}

		return $return;
	}
	
	/**
	 * Returns an array of tables that need to be truncated when Empty Local Data is used
	 * Should return multiple array members for each table that needs to be truncated. The key should be the table, while the value should be a WHERE clause, or NULL to completely empty the table.
	 *
	 * @param	string	$method	Method to truncate
	 * @return	array
	 */
	protected function truncate(string $method) : array
	{
		$classname = get_class($this->software);
		$cc = $classname::canConvert();
		if($cc === null || !isset($cc[$method])) return [];

		switch($method) {
			case 'convertStaffPreferences': return ['vssupport_mod_preferences' => null];
			case 'convertCategories': return ['vssupport_ticket_categories' => null]; // TODO(Rennorb) @correctness: Should this truncate the language tables ? 
			case 'convertStati':      return ['vssupport_ticket_stati' => 'id > '.TicketStatus::__MAX_BUILTIN]; // TODO(Rennorb) @correctness: Should this truncate the language tables ?
			case 'convertTickets':    return ['vssupport_tickets' => null, 'vssupport_messages' => null, 'vssupport_ticket_action_history' => null];
			case 'convertMessages':   return ['vssupport_messages' => null];
			case 'convertActions':    return ['vssupport_ticket_action_history' => null];
		}

		return [];
	}

	public function convertStaffPreferences(array $row)
	{
		$db = Db::i();

		$newUser = $this->mapUserByNameAndEmail($db, $row['staff_id'], $row['name'], $row['email']);
		$db->insert('vssupport_mod_preferences', ['member_id' => $newUser, 'default_reply' => $row['content']], true);
	}
	
	public function convertCategory(Lang $currentLang, array &$localLanguageMap, array $row, Db $remoteDatabase)
	{
		$db = Db::i();

		//TODO(Rennorb) @correctness: Validate that all languages match before considering mergeable. :MultiLangMerge
		// In theory we should only consider the merge if all languages have the same values, but that seems extremely complicated for little gain.

		$mergeCandidate = query_one($db->select('w.word_key', ['core_sys_lang_words', 'w'], [
			["word_app = 'vssupport'"],
			['lang_short = ?', $currentLang->short],
			['word_default = ?', $row['word_default']],
			['word_custom = ?', $row['word_custom']]
		]));
		if($mergeCandidate) {
			$newId = substr($mergeCandidate, /*ticket_category_*/ 16, /*_name*/ -5);
		}
		else {
			$newId = $db->insert('vssupport_ticket_categories', []);

			$word_key = "ticket_category_{$newId}_name";
			$valuesFolded = "(?, 'vssupport', '{$word_key}', ?, ?, ?)";
			$binds = [$currentLang->id, $row['word_default'], $row['word_custom'], $row['word_custom'] !== null];

			$extraLangs = $remoteDatabase->select('l.lang_short, IFNULL(w.word_default, d.dpt_name) AS word_default, w.word_custom', ['nexus_support_departments', 'd'], ['d.dpt_id = ?', $row['dpt_id']])
				->join(['core_sys_lang_words', 'w'], ["w.lang_id != ? AND w.word_key = CONCAT('nexus_department_', d.dpt_id)", $row['lang_id']])
				->join(['core_sys_lang', 'l'], "l.lang_id = w.lang_id");
			foreach($extraLangs as $extraRow) {
				$localLanguage = static::mapLanguage($db, $localLanguageMap, $extraRow['lang_short']);
				if(!$localLanguage) {
					$valuesFolded .= ", (?, 'vssupport', '{$word_key}', ?, ?, ?)";
					array_push($binds, $localLanguage, $extraRow['word_default'], $extraRow['word_custom'], $extraRow['word_custom'] !== null);
				}
				//TODO(Rennorb) @debug: Log missing language
			}

			$db->preparedQuery(<<<SQL
				INSERT INTO core_sys_lang_words (lang_id, word_app, word_key, word_default, word_custom, word_is_custom)
					VALUES ($valuesFolded)
				ON DUPLICATE KEY UPDATE
					word_default = VALUES(word_default), word_custom = VALUES(word_custom), word_is_custom = VALUES(word_is_custom)
			SQL, $binds);
		}
		$this->software->app->addLink($newId, $row['dpt_id'], 'vssupport_ticket_categories');
	}

	public function convertStatus(Lang $currentLang, array &$localLanguageMap, array $row, Db $remoteDatabase)
	{
		$db = Db::i();

		//TODO(Rennorb): :MultiLangMerge

		$mergeCandidate = query_one($db->select('w.word_key', ['core_sys_lang_words', 'w'], [
			["word_app = 'vssupport'"],
			['lang_short = ?', $currentLang->short],
			['word_default = ?', $row['word_default']],
			['word_custom = ?', $row['word_custom']]
		]));
		if($mergeCandidate) {
			$newId = substr($mergeCandidate, /*ticket_status_*/ 14, /*_name*/ -5);
		}
		else {
			$newData = [];
			if(!empty($row['status_color'])) { // seems to be null most of the time
				$colorBg = Color::fromColorInputString('#'.$row['color_status']);
				$colorFg = Color::isLightColor($colorBg >> 24, ($colorBg >> 16) & 0xff, ($colorBg >> 8) & 0xff) ? 0x0000_00ff : 0xffff_ffff;
				$newData['color_light_bg_rgb'] = $colorBg;
				$newData['color_light_fg_rgb'] = $colorFg;
				$newData['color_dark_bg_rgb'] = $colorBg;
				$newData['color_dark_fg_rgb'] = $colorFg;
			}
			$newId = $db->insert('vssupport_ticket_stati', $newData);

			$word_key = "ticket_status_{$newId}_name";
			$valuesFolded = "(?, 'vssupport', '{$word_key}', ?, ?, ?)";
			$binds = [$currentLang->id, $row['word_default'], $row['word_custom'], $row['word_custom'] !== null];

			$extraLangs = $remoteDatabase->select('l.lang_short, IFNULL(w.word_default, s.status_name) AS word_default, w.word_custom', ['nexus_support_statuses', 's'], ['s.status_id = ?', $row['status_id']])
				->join(['core_sys_lang_words', 'w'], ["w.lang_id != ? AND w.word_key = CONCAT('nexus_status_', s.status_id, '_admin')", $row['lang_id']])
				->join(['core_sys_lang', 'l'], "l.lang_id = w.lang_id");
			foreach($extraLangs as $extraRow) {
				$localLanguage = static::mapLanguage($db, $localLanguageMap, $extraRow['lang_short']);
				if(!$localLanguage) {
					$valuesFolded .= ", (?, 'vssupport', '{$word_key}', ?, ?, ?)";
					array_push($binds, $localLanguage, $extraRow['word_default'], $extraRow['word_custom'], $extraRow['word_custom'] !== null);
				}
				//TODO(Rennorb) @debug: Log missing language
			}

			$db->preparedQuery(<<<SQL
				INSERT INTO core_sys_lang_words (lang_id, word_app, word_key, word_default, word_custom, word_is_custom)
					VALUES ($valuesFolded)
				ON DUPLICATE KEY UPDATE
					word_default = VALUES(word_default), word_custom = VALUES(word_custom), word_is_custom = VALUES(word_is_custom)
			SQL, $binds);
		}
		
		$this->software->app->addLink($newId, $row['status_id'], 'vssupport_ticket_stati');
	}

	static function mapLanguage(Db $db, array &$localLanguageMap, string $languageShort) : int
	{
		if(isset($localLanguageMap[$languageShort]))  return $localLanguageMap[$languageShort];

		return $localLanguageMap[$languageShort] = intval(query_one($db->select('lang_id', 'core_sys_lang', ['lang_short = ?', $languageShort])));
	}

	// $severityDelta = 1 + SELECT MAX(sev_position) - MIN(sev_position) FROM nexus_support_severities
	public function convertTicket(array $row, int $severityDelta)
	{
		$db = Db::i();

		$member_id = $this->mapUserByEmail($db, $row['r_member'], $row['r_email'] ?? $row['member_email']);
		if($row['r_member'] && !$member_id) $this->software->app->log('vssupport_convert_failed_to_look_up_user', __METHOD__, App::LOG_WARNING, $row['r_id']);
		$staff_id = $this->mapUserByEmail($db, $row['r_staff'], $row['staff_email']);
		if($row['r_staff'] && !$staff_id) $this->software->app->log('vssupport_convert_failed_to_look_up_user', __METHOD__, App::LOG_WARNING, $row['r_id']);

		// need to use more than a simple insert here, cant use the wrappers
		$newId = $db->preparedQuery(<<<SQL
			INSERT INTO {$db->prefix}vssupport_tickets (hash, subject, assigned_to, issuer_id, issuer_name, issuer_email, priority, category, status, flags, created)
			VALUES (
				UNHEX(MD5(?)), -- hash
				?, -- subject
				?, -- assigned_to
				?, -- issuer_id
				?, -- issuer_name
				?, -- issuer_email
				?, -- priority
				?, -- category
				?, -- status
				?, -- flags
				FROM_UNIXTIME(?) -- created
			)
		SQL, [
			$row['r_id'], // hash
			substr('[Migrated] '.($row['r_title'] ?? 'No subject'), 0, 255), // subject
			$staff_id ?: null, // assigned_to
			$member_id ?: null, // issuer_id
			$row['member_name'], // issuer_name
			$row['r_email'] ?? $row['member_email'], // issuer_email
			static::remapPriority(intval($row['sev_position']), $severityDelta), // priority
			$this->software->app->getLink($row['r_department'], 'vssupport_ticket_categories'), // category
			$this->software->app->getLink($row['r_status'], 'vssupport_ticket_stati'), // status
			$row['r_severity_lock'] ? TicketFlags::Locked : 0,
			$row['r_started'],
		])->insert_id;
		$this->software->app->addLink($newId, $row['r_id'], 'vssupport_tickets');
	}

	public function convertMessage(array $row)
	{
		$db = Db::i();

		$ticketId = $this->software->app->getLink($row['reply_request'], 'vssupport_tickets');
		$issuer = $this->mapUserByEmail($db, $row['reply_member'], $row['reply_email'] ?? $row['member_email']);
		if($row['reply_member'] && !$issuer) $this->software->app->log('vssupport_convert_failed_to_look_up_user', __METHOD__, App::LOG_WARNING, $row['reply_id']);

		$newId = $db->insert('vssupport_messages', [
			'ticket' => $ticketId,
			'text'   => $row['reply_post'],
			'text_searchable' => strip_tags($row['reply_post']),
			'flags' => $row['reply_type'] == 'h' ? MessageFlags::Internal : 0,
		]);
		//$this->software->app->addLink($newId, $row['reply_id'], 'vssupport_messages');

		$db->preparedQuery(<<<SQL
			INSERT INTO {$db->prefix}vssupport_ticket_action_history (ticket, kind, reference_id, initiator, created)
			VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))
		SQL, [$ticketId, ActionKind::Message, $newId, $issuer, $row['reply_date']]);
	}

	public function convertAction(array $row, int $severityDelta)
	{
		$db = Db::i();

		switch($row['rlog_action']) {
			case 'status':
				$kind = ActionKind::StatusChange;
				$referenceId = $this->software->app->getLink($row['rlog_new'], 'vssupport_ticket_stati');
				break;

			case 'department':
				$kind = ActionKind::CategoryChange;
				$referenceId = $this->software->app->getLink($row['rlog_new'], 'vssupport_ticket_categories');
				break;

			case 'severity':
				$kind = ActionKind::PriorityChange;
				$referenceId = static::remapPriority(intval($row['sev_position']), $severityDelta) + 2; // shift over two for storage in unsigned int :UnsignedPriority
				break;

			case 'staff':
				$kind = ActionKind::Assigned;
				$referenceId = $this->mapUserByEmail($db, $row['rlog_new'], $row['new_staff_email']);
				break;

			case 'purchase':
			case 'split_away':
			case 'split_new':
			case 'previous_request':
			case 'autoresolve_warning':
			case 'autoresolve':
			case 'merge':
				return; // not implemented

			default:
				return; // unknown action kind, could log i suppose ?
		}

		$ticketId = $this->software->app->getLink($row['rlog_request'], 'vssupport_tickets');
		$issuer = $this->mapUserByEmail($db, $row['rlog_member'], $row['member_email']);
		if($row['rlog_member'] && !$issuer) $this->software->app->log('vssupport_convert_failed_to_look_up_user', __METHOD__, App::LOG_WARNING, $row['rlog_id']);

		$db->preparedQuery(<<<SQL
			INSERT INTO {$db->prefix}vssupport_ticket_action_history (ticket, kind, reference_id, initiator, created)
			VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))
		SQL, [$ticketId, $kind, $referenceId, $issuer, $row['rlog_date']]);
	}

	//  remap old severities by position to [-2; 2]
	static function remapPriority(int $oldSeverityPosition, int $oldSeverityPositionDelta) : int
	{
		// "nexus_severity_1": "Normal" kindof fails here, but it should work if there are multiple severities
		return round($oldSeverityPosition * (5 / $oldSeverityPositionDelta)) - 3;
	}

	function mapUserByEmail(Db $db, int $oldId, ?string $email) : int
	{
		if(!$oldId) return 0;

		try {
			$newId = $this->software->app->getLink($oldId, 'vssupport_members');
		}
		catch(\OutOfRangeException $ex) {
			if(!$email) return 0;

			$newId = intval(query_one($db->select('member_id', 'core_members', ['email = ?', $email])));
			$this->software->app->addLink($newId, $oldId, 'vssupport_members');
		}

		return $newId;
	}

	function mapUserByNameAndEmail(Db $db, int $oldId, string $name, string $email) : int
	{
		if(!$oldId) return 0;

		try {
			$newId = $this->software->app->getLink($oldId, 'vssupport_members');
		}
		catch(\OutOfRangeException $ex) {
			if(!$email) return 0;

			$newId = intval(query_one($db->select('member_id', 'core_members', [['name = ?', $name], ['email = ?', $email]])));
			$this->software->app->addLink($newId, $oldId, 'vssupport_members');
		}

		return $newId;
	}
}