<?php namespace IPS\vssupport\modules\front\tickets;

use IPS\Db;
use IPS\Dispatcher\Controller;
use IPS\Email;
use IPS\File;
use IPS\Member;
use IPS\Output;
use IPS\Theme;
use IPS\Helpers\Form;
use IPS\Http\Url;
use IPS\Request;
use IPS\vssupport\ActionKind;
use IPS\vssupport\MessageFlags;
use IPS\vssupport\StatusFlags;
use IPS\vssupport\Ticket;
use IPS\vssupport\TicketFlags;
use IPS\vssupport\TicketStatus;

use function defined;
use function IPS\vssupport\log_ticket_action;
use function IPS\vssupport\query_all;
use function IPS\vssupport\query_all_assoc;
use function IPS\vssupport\query_one;

if(!defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class tickets extends Controller
{
	public function execute() : void
	{
		parent::execute();
	}

	protected function manage() : void
	{
		$member = Member::loggedIn();
		$lang = $member->language();
		$output = Output::i();
		$db = Db::i();
		$theme = Theme::i();

		$tickets = query_all(
			// :ReadMarkerTimestamps
			$db->select('t.id, t.subject, t.priority, t.created, t.flags, HEX(t.hash) AS hash, t.category, FROM_UNIXTIME((mark.item_app_key_2 << 32) | mark.item_app_key_3) >= la.last_update_at AS `read`', ['vssupport_tickets', 't'], 't.issuer_id = '.($member->member_id ?? 0).' AND !(s.flags & '.StatusFlags::TicketResolved.')')
			->join(['vssupport_ticket_stati', 's'], 's.id = t.status')
			->join($db->select('ticket, max(created) as last_update_at', ['vssupport_ticket_action_history', 'la'], group: 'ticket'), 'la.ticket = t.id') //NOTE(Rennorb): Due to the extremely questionable implementation of the query joiner this _has to be_ a select object...
			->join(['core_item_markers', 'mark'], "mark.item_app = 'vssupport' AND mark.item_member_id = {$member->member_id} AND mark.item_app_key_1 = t.id")
		);

		$output->title = $lang->addToStack('tickets');
		$output->breadcrumb[] = [null, $lang->addToStack('my_tickets')];
		$output->output = $theme->getTemplate('tickets')->list($tickets);
		$output->cssFiles = array_merge($output->cssFiles, $theme->css('global.css', location: 'global'));
	}

	public function create() : void
	{
		$member = Member::loggedIn();
		$lang = $member->language();
		$output = Output::i();
		$db = Db::i();

		$form = new Form(submitLang: 'ticket_submit');

		if(!$member->member_id) {
			$form->add(new Form\Text('name', required: true, options: [ 'maxLength' => 256 ]));
			$form->add(new Form\Email('email', required: true, options: [ 'maxLength' => 256 ]));
		}

		$categories = query_all_assoc($db->select("id, CONCAT('ticket_category_', id, '_name')", 'vssupport_ticket_categories'));
		//NOTE(Rennorb): for some reason, the provided id here doesn't translate directly into a html id. it gets prefixed with `elSelect_`
		$form->add(new Form\Select('category', required: true, options: [ 'options' => $categories ], id: 'ticket-category'));
		$disclaimers = '';
		foreach($categories as $id => $_) {
			$text = $lang->addToStack("ticket_category_{$id}_disclaimer", options: ['returnBlank' => true]);
			$classExtra = !$disclaimers && $text ? ' selected' : ''; // make the first one visible
			$disclaimers .= "<div id='ticket-disclaimer-{$id}' class='ipsMessage ipsMessage--form$classExtra'>$text</div>";
		}
		$form->addHtml(<<<HTML
			<style>#ticket-disclaimers>*{display:none;}#ticket-disclaimers>.selected:not(:empty){display:block;margin-top:0;}</style>
			<div id='ticket-disclaimers'>{$disclaimers}</div>
			<script>{
				const select = document.getElementById('elSelect_ticket-category');
				let lastSelectedEl = document.getElementById('ticket-disclaimer-'+select.value);
				select.addEventListener('change', e => {
					lastSelectedEl.classList.remove('selected');
					lastSelectedEl = document.getElementById('ticket-disclaimer-'+e.target.value)
					lastSelectedEl.classList.add('selected');
				});
			}</script>
		HTML);

		$form->add(new Form\Text('subject', required: true, options: [ 'maxLength' => 256 ]));

		$form->add(new Form\Editor('text', required: true, options: [
			'app'         => 'vssupport',
			'key'         => 'TicketText',
			'autoSaveKey' => 'new-ticket',
			'attachIds'   => null,
		]));

		$form->add(new Form\Captcha());

		if($values = $form->values()) {
			if(!$member->member_id) {
				$issuerName =  $values['name'];
				$issuerEmail =  $values['email'];
			}
			else {
				$issuerName = $member->get_name();
				$issuerEmail = $member->email;
			}

			// manual query construction to allow usage of UNHEX
			$ticketHash = md5(uniqid()); //TODO(Rennorb) @correctness: Tn theory insertion could fail in case of a collision;
			$ticketId = $db->preparedQuery(<<<SQL
					INSERT INTO {$db->prefix}vssupport_tickets (issuer_name, issuer_email, category, subject, issuer_id, hash)
					VALUES(?, ?, ?, ?, ?, UNHEX(?))
				SQL,
				[$issuerName, $issuerEmail, $values['category'], $values['subject'], $member->member_id, $ticketHash]
			)->insert_id;
			$messageId = $db->insert('vssupport_messages', [
				'ticket'          => $ticketId,
				'text'            => $values['text'],
				'text_searchable' => strip_tags($values['text']),
			]);
			log_ticket_action($db, $ticketId, ActionKind::Message, $member->member_id ?? 0, $messageId);

			File::claimAttachments('new-ticket', $ticketId, $messageId);

			// Since unregistered users cannot receive notifications, and have no record of their tickets, we just send them an email.
			if(!$member->member_id) {
				$emailParams = [$ticketId, $ticketHash, $values['text'], $issuerName];
				$email = Email::buildFromTemplate('vssupport', 'notification_ticket_created', $emailParams, Email::TYPE_TRANSACTIONAL);
				$email->send($issuerEmail);
			}

			$output->redirect(Url::internal('app=vssupport&module=tickets&controller=tickets&do=view&hash='.$ticketHash, seoTemplate: 'tickets_view_hash'));
			return;
		}

		$output->title = $lang->addToStack('ticket_create');
		$output->breadcrumb[] = [null, $lang->addToStack('ticket_create')];
		$output->output = $form;
	}

	public function view() : void
	{
		$output = Output::i();
		$member = Member::loggedIn();
		$db =  Db::i();
		$request = Request::i();

		$ticket = query_one(
			$db->select('t.*, t.id, HEX(hash) AS hash, s.flags AS status_flags', ['vssupport_tickets', 't'], ['hash = UNHEX(?)', $request->hash])
			->join(['vssupport_ticket_stati', 's'], 's.id = t.status')
		);
		if(!$ticket) {
			$output->error('node_error', '2C114/O', 404, '');
			return;
		}

		if($ticket['issuer_id'] != $member->member_id && !$member->isAdmin()) {
			$output->error('node_error', '2C114/O', 404, '');
			return;
		}

		$form = null;
		if(!($ticket['flags'] & TicketFlags::Locked)) {
			$form = new Form(submitLang: 'message_add');
			// Prevent the label being placed to the side. We want full width.
			//$form->class = 'ipsForm--vertical';

			$autoSaveKey = 'ticket-message-'.$ticket['id'];
			$form->add(new Form\Editor('text', required: true, options: [
				'app'         => 'vssupport',
				'key'         => 'TicketText',
				'autoSaveKey' => $autoSaveKey,
				'attachIds'   => null,
			]));

			if($values = $form->values()) {
				$messageId = $db->insert('vssupport_messages', [
					'ticket'          => $ticket['id'],
					'text'            => $values['text'],
					'text_searchable' => strip_tags($values['text']),
				]);
				log_ticket_action($db, $ticket['id'], ActionKind::Message, $member->member_id ?? 0, $messageId);
	
				File::claimAttachments($autoSaveKey, $ticket['id'], $messageId);

				if($ticket['status_flags'] & StatusFlags::TicketResolved) {
					$db->update('vssupport_tickets', ['status' => TicketStatus::Open]);
					log_ticket_action($db, $ticket['id'], ActionKind::StatusChange, $member->member_id ?? 0, TicketStatus::Open);
				}
	
				$output->redirect($request->url());
				return;
			}
			else {
				if($ticket['status_flags'] & StatusFlags::TicketResolved) {
					$notice = htmlspecialchars($member->language()->addToStack('ticked_considered_closed_notice'), ENT_DISALLOWED, 'UTF-8', FALSE);
					array_unshift($form->actionButtons, "<div>$notice</div>");
				}
			}
		}

		$lang = $member->language();
		$theme = Theme::i();

		$actions = query_all(
			$db->select('a.created, a.kind, a.reference_id, a.initiator AS initiator_id, u.name AS initiator, m.text, m.flags, as.name AS assigned_to_name', ['vssupport_ticket_action_history', 'a'],
				where: 'a.ticket = '.$ticket['id'].' AND !(IFNULL(m.flags, 0) & '.MessageFlags::Internal.')', order: 'a.created ASC')
			->join(['core_members', 'u'], 'u.member_id = a.initiator')
			->join(['vssupport_messages', 'm'], 'm.id = a.reference_id AND a.kind = '.ActionKind::Message)
			->join(['core_members', 'as'], 'as.member_id = a.reference_id AND a.kind = '.ActionKind::Assigned)
		);
		foreach($actions as &$action) {
			if(!$action['initiator']) $action['initiator'] = $ticket['issuer_name'];
			if($action['kind'] === ActionKind::Assigned && !$action['assigned_to_name']) $action['assigned_to_name'] = $lang->addToStack('unknown');
			if($action['kind'] === ActionKind::PriorityChange) $action['reference_id'] -= 2; // :UnsignedPriority
			if($action['kind'] === ActionKind::Message) {
				$classes = $action['initiator_id'] === 0 || $action['initiator'] === $ticket['issuer_name'] ? 'message-issuer' : 'message-moderator';
				if($action['flags'] & MessageFlags::EmailIngest) $classes .= ' email-ingest';
				$action['classes'] = $classes;
			} 
		}
		unset($action);

		$output->title = $lang->addToStack('ticket').' #'.$ticket['id'].' - '.$ticket['subject'];
		$bc = &$output->breadcrumb;
		$bc[] = [URl::internal('app=vssupport&module=tickets&controller=tickets', seoTemplate: 'tickets_list'), $lang->addToStack('my_tickets')];
		$bc[] = [null, $ticket['subject']];
		$output->output = $theme->getTemplate('tickets')->ticket($ticket, $actions, $form);
		$output->cssFiles = array_merge($output->cssFiles, $theme->css('global.css', location: 'global'));

		Ticket::markRead($ticket['id'], $member->member_id);
	}
}
