<?php namespace IPS\vssupport\modules\front\tickets;

use IPS\Db;
use IPS\Dispatcher\Controller;
use IPS\File;
use IPS\Member;
use IPS\Output;
use IPS\Theme;
use IPS\Helpers\Form;
use IPS\Http\Url;
use IPS\Request;
use IPS\vssupport\ActionKind;
use IPS\vssupport\MessageFlags;
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
			$db->select('t.id, t.subject, t.priority, t.created, t.flags, HEX(t.hash) AS hash, c.name_key as category, s.name_key as status', ['vssupport_tickets', 't'], 't.issuer_id = '.($member->member_id ?? 0))
			->join(['vssupport_ticket_categories', 'c'], 'c.id = t.category')
			->join(['vssupport_ticket_stati', 's'], 'c.id = t.status')
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

		$categories = query_all_assoc($db->select("id, CONCAT('ticket_cat_', name_key, '_name')", 'vssupport_ticket_categories'));

		$form = new Form(submitLang: 'ticket_submit');
		if(!$member->member_id) {
			$form->add(new Form\Text('name', required: true, options: [ 'maxLength' => 256 ]));
			$form->add(new Form\Email('email', required: true, options: [ 'maxLength' => 256 ]));
		}
		$form->add(new Form\Select('category', required: true, options: [ 'options' => $categories ]));
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
				$name =  $values['name'];
				$email =  $values['email'];
			}
			else {
				$name = $member->get_name();
				$email = $member->email;
			}

			// manual query construction to allow usage of UNHEX
			$ticketHash = md5(uniqid()); //TODO(Rennorb) @correctness: Tn theory insertion could fail in case of a collision;
			$ticketId = $db->preparedQuery(<<<SQL
					INSERT INTO {$db->prefix}vssupport_tickets (issuer_name, issuer_email, category, subject, issuer_id, hash)
					VALUES(?, ?, ?, ?, ?, UNHEX(?))
				SQL,
				[$name, $email, $values['category'], $values['subject'], $member->member_id, $ticketHash]
			)->insert_id;
			$messageId = $db->insert('vssupport_messages', [
				'ticket'          => $ticketId,
				'text'            => $values['text'],
				'text_searchable' => strip_tags($values['text']),
			]);
			log_ticket_action($db, $ticketId, ActionKind::Message, $member->member_id ?? 0, $messageId);

			File::claimAttachments('new-ticket', $ticketId, $messageId);

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
			$db->select('t.*, t.id, HEX(hash) AS hash, c.name_key as category, s.name_key as status_name', ['vssupport_tickets', 't'], ['hash = UNHEX(?)', $request->hash])
			->join(['vssupport_ticket_categories', 'c'], 'c.id = t.category')
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

				if($ticket['status'] & TicketStatus::__FLAG_CLOSED) {
					$db->update('vssupport_tickets', ['status' => TicketStatus::Open]);
					log_ticket_action($db, $ticket['id'], ActionKind::StatusChange, $member->member_id ?? 0, TicketStatus::Open);
				}
	
				$output->redirect($request->url());
				return;
			}
			else {
				if($ticket['status'] & TicketStatus::__FLAG_CLOSED) {
					$notice = htmlspecialchars($member->language()->addToStack('ticked_considered_closed_notice'), ENT_DISALLOWED, 'UTF-8', FALSE);
					array_unshift($form->actionButtons, "<div>$notice</div>");
				}
			}
		}

		$lang = $member->language();
		$theme = Theme::i();

		$actions = query_all(
			$db->select('a.created, a.kind, a.reference_id, u.name AS initiator, m.text, m.flags, IFNULL(c.name_key, s.name_key) AS name_key, as.name AS assigned_to_name', ['vssupport_ticket_action_history', 'a'],
				where: 'a.ticket = '.$ticket['id'].' AND !(IFNULL(m.flags, 0) & '.MessageFlags::Internal.')', order: 'a.created ASC')
			->join(['core_members', 'u'], 'u.member_id = a.initiator')
			->join(['vssupport_messages', 'm'], 'm.id = a.reference_id AND a.kind = '.ActionKind::Message)
			->join(['vssupport_ticket_categories', 'c'], 'c.id = a.reference_id AND a.kind = '.ActionKind::CategoryChange)
			->join(['vssupport_ticket_stati', 's'], 's.id = a.reference_id AND a.kind = '.ActionKind::StatusChange)
			->join(['core_members', 'as'], 'as.member_id = a.reference_id AND a.kind = '.ActionKind::Assigned)
		);
		foreach($actions as &$action) {
			if(!$action['initiator']) $action['initiator'] = $ticket['issuer_name'];
			if($action['kind'] === ActionKind::PriorityChange) $action['reference_id'] -= 2; // :UnsignedPriority
		}
		unset($action);

		$output->title = $lang->addToStack('ticket').' #'.$ticket['id'].' - '.$ticket['subject'];
		$bc = &$output->breadcrumb;
		$bc[] = [URl::internal('app=vssupport&module=tickets&controller=tickets', seoTemplate: 'tickets_list'), $lang->addToStack('my_tickets')];
		$bc[] = [null, $ticket['subject']];
		$output->output = $theme->getTemplate('tickets')->ticket($ticket, $actions, $form);
		$output->cssFiles = array_merge($output->cssFiles, $theme->css('global.css', location: 'global'));
	}
}
