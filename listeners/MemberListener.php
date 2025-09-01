<?php namespace IPS\vssupport\listeners;

use IPS\Db;
use IPS\Events\ListenerType\MemberListenerType;
use IPS\Member;
use IPS\vssupport\ActionKind;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class MemberListener extends MemberListenerType
{

	function onValidate(Member $member) : void
	{
		$db = Db::i();
		$initiator = intval(Member::loggedIn()->member_id);

		$kind = ActionKind::UserValidated;
		$query = $db->preparedQuery(<<<SQL
			INSERT INTO vssupport_ticket_action_history (ticket, kind, `initiator`)
			SELECT id, $kind, $initiator
			FROM vssupport_tickets
			WHERE issuer_id = 0 AND issuer_email = ?
		SQL, [$member->email]);
		if($query->affected_rows) {
			$db->update('vssupport_tickets', [
				'issuer_id' => $member->member_id,
				'issuer_name' => $member->name,
			], ['issuer_id = 0 AND member_email = ?', $member->email]);
		}
	}

	function onMerge(Member $memberToKeep, Member $otherMember) : void
	{
		$db = Db::i();
		$initiator = intval(Member::loggedIn()->member_id);

		$toId = intval($memberToKeep->member_id);
		$fromId = intval($otherMember->member_id);

		$kind = ActionKind::UserMerged;
		$query = $db->preparedQuery(<<<SQL
			INSERT INTO vssupport_ticket_action_history (ticket, kind, `initiator`)
			SELECT id, $kind, $initiator
			FROM vssupport_tickets
			WHERE issuer_id = ?
		SQL, [$fromId]);
		if($query->affected_rows) {
			$db->update('vssupport_tickets', [
				'issuer_id'  => $toId,
				'issuer_name'  => $memberToKeep->name,
				'issuer_email' => $memberToKeep->email,
			], ['issuer_id = ?', $fromId]);
		}

		static::reassignTickets($db, $toId, $fromId, $initiator);

		static::transferMemberIdsInActions($db, $toId, intval($otherMember->member_id));
	}

	function onDelete(Member $member) : void
	{
		$db = Db::i();
		$initiator = intval(Member::loggedIn()->member_id);

		$kind = ActionKind::UserDeleted;
		$query = $db->preparedQuery(<<<SQL
			INSERT INTO vssupport_ticket_action_history (ticket, kind, `initiator`)
			SELECT id, $kind, $initiator
			FROM vssupport_tickets
			WHERE issuer_id = ?
		SQL, [$member->member_id]);
		if($query->affected_rows) {
			$db->update('vssupport_tickets', 'issuer_id = 0', ['issuer_id = ?', $member->member_id]);
		}

		static::reassignTickets($db, null, intval($member->member_id), $initiator);
	}

	function onEmailChange(Member $member, string $new, string $old) : void
	{
		$db = Db::i();
		$initiator = intval(Member::loggedIn()->member_id);

		$kind = ActionKind::UserEmailChanged;
		$query = $db->preparedQuery(<<<SQL
			INSERT INTO vssupport_ticket_action_history (ticket, kind, `initiator`)
			SELECT id, $kind, $initiator
			FROM vssupport_tickets
			WHERE issuer_id != 0 AND issuer_email = ?
		SQL, [$old]); // Lets only change emails for actual users. If the ticket is not from an account, lets not move it.
		if($query->affected_rows) {
			$db->update('vssupport_tickets', ['issuer_email' => $new], ['issuer_email = ?', $old]);
		}
	}

	function onProfileUpdate(Member $member, array $changes) : void
	{
		if(!isset($changes['name'])) return;

		$initiator = intval(Member::loggedIn()->member_id);

		$previousName = new \ReflectionProperty($member, 'previousName');
		$previousName->setAccessible(true);
		$previousName = $previousName->getValue($member);

		$db = Db::i();

		$kind = ActionKind::UserNameChanged;
		$query = $db->preparedQuery(<<<SQL
			INSERT INTO vssupport_ticket_action_history (ticket, kind, `initiator`)
			SELECT id, $kind, $initiator
			FROM vssupport_tickets
			WHERE issuer_id != 0 AND issuer_name = ?
		SQL, [$previousName]); // Lets only change names for actual users. If the ticket is not from an account, lets not move it.
		if($query->affected_rows) {
			$db->update('vssupport_tickets', ['issuer_name ' => $changes['name']], ['issuer_name = ?', $previousName]);
		}
	}

	static function reassignTickets(Db $db, int|null $to, int $from, int $initiator) : void
	{
		if($to === null) $to = 'null';
		$kind = ActionKind::Assigned;
		$db->query(<<<SQL
			INSERT INTO vssupport_ticket_action_history (ticket, kind, reference_id, `initiator`)
			SELECT id, $kind, $to, $initiator
			FROM vssupport_tickets
			WHERE assigned_to = $from
		SQL, read: false);
		if($db->affected_rows) {
			$db->update('vssupport_tickets', 'assigned_to = '.$to, 'assigned_to = '.$from);
		}
	}

	static function transferMemberIdsInActions(Db $db, int $to, int $from) : void
	{
		$db->query(<<<SQL
			UPDATE vssupport_ticket_action_history 
			SET `initiator` = $to
			WHERE `initiator` = $from
		SQL, read: false);

		$kind = ActionKind::Assigned;
		$db->query(<<<SQL
			UPDATE vssupport_ticket_action_history 
			SET reference_id = $to
			WHERE kind = $kind AND reference_id = $from
		SQL, read: false);
	}
}