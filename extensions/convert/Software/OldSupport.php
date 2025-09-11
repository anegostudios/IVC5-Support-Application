<?php namespace IPS\vssupport\extensions\convert\Software;

use IPS\convert\App;
use IPS\convert\Library;
use IPS\convert\Software as ConverterSoftware;

use function IPS\vssupport\query_one;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}


class OldSupport extends ConverterSoftware
{
	static string $targetApp = 'core'; // required for it to show up in the "start conversion" menu

	// override to returnthe correct thing  ?
	public function getLibrary( ?string $library=NULL ): Library
	{
		$library = $library ?: 'vssupport';

		$classname = Library::libraries()[$library];

		if (!class_exists($classname)) {
			throw new \InvalidArgumentException( 'invalid_library' );
		}

		return new $classname($this);
	}

	public static function softwareName() : string
	{
		return "Invision Community 4 (Nexus Support)";
	}

	public static function softwareKey() : string
	{
		return 'vssupport';
	}

	/**
	 * Content we can convert from this software.
	 * @return    array|null
	 */
	public static function canConvert() : ?array
	{
		return [
			'convertCategories'	=> [
				'table' => 'nexus_support_departments',
				'where' => NULL,
			],
			'convertStati'	=> [
				'table' => 'nexus_support_statuses',
				'where' => NULL,
			],
			'convertTickets'	=> [
				'table' => 'nexus_support_requests',
				'where' => NULL,
			],
			'convertMessages'	=> [
				'table' => 'nexus_support_replies',
				'where' => NULL,
			],
			'convertActions'	=> [
				'table' => 'nexus_support_request_log',
				'where' => NULL,
			],
		];
	}
	
	/**
	 * Can we convert passwords from this software.
	 * @return    boolean
	 */
	public static function loginEnabled() : bool
	{
		return FALSE;
	}
	
	public static function canConvertSettings() : bool
	{
		return FALSE; // TODO maybe? 
	}
	public function settingsMap() : array
	{
		return [];
	}
	public function settingsMapList() : array
	{
		return [];
	}
	
	/**
	 * Returns a block of text, or a language string, that explains what the admin must do to start this conversion
	 * @return    string|null
	 */
	public static function getPreConversionInformation() : ?string
	{
		return 'vssupport_pre_convert_notice';
	}
	
	/**
	 * List of conversion methods that require additional information
	 * @return    array
	 */
	public static function checkConf() : array
	{
		return [];
	}
	
	/**
	 * Get More Information
	 * @param string $method	Conversion method
	 * @return    array|null
	 */
	public function getMoreInfo(string $method) : ?array
	{
		return [];
	}
	
	/**
	 * Finish - Adds everything it needs to the queues and clears data store
	 * @return	array		Messages to display
	 */
	public function finish() : array
	{
		return [];
	}
	
	/**
	 * Pre-process content for the Invision Community text parser
	 *
	 * @param	string			The post
	 * @param	string|null		Content Classname passed by post-conversion rebuild
	 * @param	int|null		Content ID passed by post-conversion rebuild
	 * @param	App|null		App object if available
	 * @return	string			The converted post
	 */
	public static function fixPostData(string $post, ?string $className = null, ?int $contentId = null, ?App $app = null) : string
	{
		return $post;
	}
	
	public function convertCategories()
	{
		$libraryClass = $this->getLibrary();
		$libraryClass::setKey('dpt_id');

		$q = $this->fetch(['nexus_support_departments', 'd'], 'dpt_id', null, 'd.dpt_id, IFNULL(d.dpt_name, w.word_default) AS dpt_name')
			//:TranslatedField
			->join(['core_sys_lang_words', 'w'], "d.dpt_name IS NULL AND w.lang_id = (SELECT lang_id FROM {$this->db->prefix}core_sys_lang WHERE lang_short = 'english' LIMIT 1) AND w.word_key = CONCAT('nexus_department_', d.dpt_id)");
		foreach($q as $row) {
			$libraryClass->convertCategory($row);
			$libraryClass->setLastKeyValue($row['dpt_id']);
		}
	}

	public function convertStati()
	{
		$libraryClass = $this->getLibrary();
		$libraryClass::setKey('status_id');

		$q = $this->fetch(['nexus_support_statuses', 's'], 'status_id', null, 's.status_id, IFNULL(s.status_name, w.word_default) AS status_name')
			// Default stati don't actually use the database rows, but use translation keys (just as we do), so we need to fallback to those. :TranslatedField
			->join(['core_sys_lang_words', 'w'], "s.status_name IS NULL AND w.lang_id = (SELECT lang_id FROM {$this->db->prefix}core_sys_lang WHERE lang_short = 'english' LIMIT 1) AND w.word_key = CONCAT('nexus_status_', s.status_id,'_admin')");
		foreach($q as $row) {
			$libraryClass->convertStatus($row);
			$libraryClass->setLastKeyValue($row['status_id']);
		}
	}

	public function convertTickets()
	{
		$libraryClass = $this->getLibrary();
		$libraryClass::setKey('r_id');

		$severityDelta = 1 + query_one($this->db->select('MAX(sev_position) - MIN(sev_position)', 'nexus_support_severities'));

		$q = $this->fetch(['nexus_support_requests', 'r'], 'r_id', null, 'r.*, m.name AS member_name, m.email AS member_email, sm.email AS staff_email, s.sev_position')
			->join(['core_members', 'm'], 'm.member_id = r.r_member')
			->join(['core_members', 'sm'], 'sm.member_id = r.r_staff')
			->join(['nexus_support_severities', 's'], 's.sev_id = r.r_severity');
		foreach($q as $row) {
			$libraryClass->convertTicket($row, $severityDelta);
			$libraryClass->setLastKeyValue($row['r_id']);
		}
	}

	public function convertMessages()
	{
		$libraryClass = $this->getLibrary();
		$libraryClass::setKey('reply_id');

		$q = $this->fetch(['nexus_support_replies', 'r'], 'reply_id', null, 'r.*, m.email AS member_email')
			->join(['core_members', 'm'], 'm.member_id = r.reply_member');
		foreach($q as $row) {
			$libraryClass->convertMessage($row);
			$libraryClass->setLastKeyValue($row['reply_id']);
		}
	}

	public function convertActions()
	{
		$libraryClass = $this->getLibrary();
		$libraryClass::setKey('rlog_id');

		$severityDelta = 1 + query_one($this->db->select('MAX(sev_position) - MIN(sev_position)', 'nexus_support_severities'));

		$q = $this->fetch(['nexus_support_request_log', 'l'], 'rlog_id', null, 'l.*, m.email AS member_email, s.sev_position, nsm.email AS new_staff_email')
			->join(['core_members', 'm'], 'm.member_id = l.rlog_member')
			->join(['nexus_support_severities', 's'], "l.rlog_action = 'severity' AND s.sev_id = l.rlog_new")
			->join(['core_members', 'nsm'], "l.rlog_action = 'staff' AND nsm.member_id = l.rlog_new");
		foreach($q as $row) {
			$libraryClass->convertAction($row, $severityDelta);
			$libraryClass->setLastKeyValue($row['rlog_id']);
		}
	}
}