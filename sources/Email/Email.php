<?php namespace IPS\vssupport;

use IPS\Data\Store;
use IPS\Db;
use IPS\Http\Url;
use IPS\IPS;
use IPS\Member;
use IPS\Settings;
use IPS\Text\Parser;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class Email
{
	//NOTE(Rennorb): To be able to track responses to sent notifications we set a specific Message-ID header.
	// When a client responds to the email, the In-Reply-To header will have that message Id. 
	// By putting a uid into that header we can associate a response with a sent notification with a ticket, being able to automatically add a response to the ticket.
	// :EmailResponseTracking

	public static function sendTicketResponseEmail(int $ticketId, string $hexTicketHash, string $messageText, string $issuerAddress, string $responderName) : void
	{
		$emailParams = [$ticketId, $hexTicketHash, $messageText, $responderName];
		$email = \IPS\Email::buildFromTemplate('vssupport', 'notification_ticket_response', $emailParams, \IPS\Email::TYPE_TRANSACTIONAL);
		$email->send($issuerAddress, additionalHeaders: [
			'X-Auto-Response-Suppress' => 'OOF, AutoReply', // suppress "currently out of office" notifications
			'Message-ID' => static::generateMessageId($hexTicketHash), // :EmailResponseTracking
		]);
	}

	/**
	 * @param string $hexTicketHash 32 character hex ticket hash
	 * :EmailResponseTracking
	 */
	static function generateMessageId(string $hexTicketHash) : string
	{
		assert(strlen($hexTicketHash) == 32);
		$randomString = $hexTicketHash . base_convert(strval(floor(microtime(true) * 1000)), 10, 36);
		$host = parse_url(Url::baseUrl(), PHP_URL_HOST);
		return "<{$randomString}@{$host}>";
	}

	const SETTING_MAIL_INGRES = "vssupport_mail_ingres";
	/**
	 * @throws DomainException Invalid config
	 * @throws ErrorException Connection cannot be established or mailbox cannot be found.
	 */
	static function fetchIncoming() : int
	{
		$config = json_decode(Settings::i()->{static::SETTING_MAIL_INGRES}, true);
		if(empty($config)) return 0;

		switch($config['mode']) {
			case 'imap':
				if(!function_exists('imap_open')) throw new \LogicException(Member::loggedIn()->language()->addToStack('php_imap_not_installed'));
				return static::fetchIncomingImap($config);

			default:
				throw new \DomainException("Unknown email ingest mode '{$config['mode']}'.");
		}
	}

	const STORAGE_IMAP_VALIDITY = "vssupport_imap_validity";
	const STORAGE_IMAP_NEXT_UID = "vssupport_imap_next_uid";

	/** @throws ErrorException connection cannot be established or mailbox cannot be found. */
	static function fetchIncomingImap(array $config) : int
	{
		$db = Db::i();
		$store = Store::i();

		$server = static::_formServerStringImap($config);
		$user = $config['user'];
		$password = $config['pass'];
		$box = $server.$config['inbox'];

		$markFlags = '\\Seen';
		if($config['delete']) $markFlags .= ' \\Delete';

		$conn = \imap_open($box, $user, $password);
		if($conn === false) throw new \ErrorException("Failed to connect to the imap server with provided credentials: ".\imap_last_error());

		$boxStatus = \imap_status($conn, $box, SA_UIDVALIDITY | SA_UIDNEXT);
		if($boxStatus === false) {
			\imap_close($conn);
			throw new \ErrorException("Provided inbox was not found.");
		}
		$currentValidity = $boxStatus->uidvalidity;
		$nextUid = $boxStatus->uidnext;

		$oldValidity = $store->{static::STORAGE_IMAP_VALIDITY};

		if($oldValidity && $oldValidity == $currentValidity) {
			$lastUid = $store->{static::STORAGE_IMAP_NEXT_UID};
			if($lastUid == $nextUid) { // No new messages.
				\imap_close($conn);
				return 0;
			}
			$overviews = \imap_fetch_overview($conn, $lastUid.':*', FT_UID);
		}
		else { // Mailbox reset, cant do much about that.
			$store->{static::STORAGE_IMAP_VALIDITY} = $currentValidity;
			$store->{static::STORAGE_IMAP_NEXT_UID} = $nextUid;
			\imap_close($conn);
			return 0;
		}

		$processed = 0;
		foreach($overviews as $overview) {
			if($overview->seen) continue;

			$inReplyTo = $overview->in_reply_to ?? '';
			if(!$inReplyTo) continue;

			if(!str_starts_with($inReplyTo, '<') || strlen($inReplyTo) < (1 + Ticket::HASH_BYTE_LEN * 2 + 2)) continue;
			$ticketHashHex = substr($inReplyTo, 1, Ticket::HASH_BYTE_LEN * 2); // :EmailResponseTracking

			$ticket = query_one($db->select('id, issuer_id', 'vssupport_tickets', ['hash = UNHEX(?)', $ticketHashHex]));
			if(!$ticket) continue;

			$structure = \imap_fetchstructure($conn, $overview->uid, FT_UID);
			$parts = [];
			static::_parseEmailParts($parts, $structure);

			$partIdSelected = array_key_first($parts);
			foreach($parts as $partId => $part) {
				if($part->type == TYPETEXT) {
					$partIdSelected = $partId;
					break;
				}
			}
			$partSelected = $parts[$partIdSelected];
			
			$body = \imap_fetchbody($conn, $overview->uid, $partIdSelected, FT_UID | FT_PEEK);
			switch($partSelected->encoding) {
				case ENC7BIT: $body = \imap_utf7_decode($body); break;
				case ENC8BIT: break;
				case ENCBINARY: break;
				case ENCBASE64: $body = base64_decode($body); break;
				case ENCQUOTEDPRINTABLE: $body = quoted_printable_decode($body); break;
				case ENCOTHER: break;
			}
			
			$issuer = Member::load($ticket['issuer_id']);

			$parser = new Parser(member: $issuer);
			$body = $parser->parse($body);

			$message = Message::insert($db, $ticket['id'], $body, MessageFlags::EmailIngest);
			$db->insert('vssupport_ticket_action_history', [
				'kind'         => ActionKind::Message,
				'ticket'       => $ticket['id'],
				'initiator'    => $issuer->member_id,
				'reference_id' => $message->id,
			]);


			if($markFlags) \imap_setflag_full($conn, $overview->uid, $markFlags, ST_UID);

			$processed++;
		}

		$store->{static::STORAGE_IMAP_NEXT_UID} = $nextUid;

		if($config['delete']) \imap_expunge($conn);

		\imap_close($conn);

		return $processed;
	}

	public static function _formServerStringImap(array $config) : string
	{
		return '{'.$config['host'].'/imap'.($config['ssl'] ? '/ssl' : '').($config['tls'] ? '/tls' : '').($config['validate_cert'] ? '' : '/novalidate-cert').'}';
	}

	static function _parseEmailParts(array &$result, object $structure, string $idPrefix = '') : void
	{
		if($structure->type != TYPEMESSAGE && !empty($structure->parts)) {
			//$result[$idPrefix ?? '1'] = $structure;
			if($idPrefix) $idPrefix .= '.';
			foreach($structure->parts as $i => $part) {
				static::_parseEmailParts($result, $part, $idPrefix.($i + 1));
			}
		}
		else {
			$result[$idPrefix ?? '1'] = $structure;
		}
	}
}
