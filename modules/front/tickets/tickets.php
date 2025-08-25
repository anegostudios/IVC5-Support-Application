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
			$db->select('t.id, t.subject, t.priority, t.created, HEX(t.hash) AS hash, c.name_key as category', ['vssupport_tickets', 't'], 't.member_id = '.($member->member_id ?? 0))
			->join(['vssupport_ticket_categories', 'c'], 'c.id = t.category')
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

		$categories = query_all_assoc($db->select("id, CONCAT('ticket_cat_name_', name_key)", 'vssupport_ticket_categories'));

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
					INSERT INTO {$db->prefix}vssupport_tickets (user_name, user_email, category, subject, member_id, hash)
					VALUES(?, ?, ?, ?, ?, UNHEX(?))
				SQL,
				[$name, $email, $values['category'], $values['subject'], $member->member_id, $ticketHash]
			)->insert_id;
			$messageId = $db->insert('vssupport_messages', [
				'ticket'          => $ticketId,
				'text'            => $values['text'],
				'text_searchable' => strip_tags($values['text']),
			]);
			log_ticket_action($db, $ticketId, ActionKind::Message, $member->member_id, $messageId);

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
			$db->select('*, t.id, HEX(hash) AS hash, c.name_key as category', ['vssupport_tickets', 't'], ['hash = UNHEX(?)', $request->hash])
			->join(['vssupport_ticket_categories', 'c'], 'c.id = t.category')
		);
		if(!$ticket) {
			$output->error('node_error', '2C114/O', 404, '');
			return;
		}

		if($ticket['member_id'] != $member->member_id && !$member->isAdmin()) {
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
				log_ticket_action($db, $ticket['id'], ActionKind::Message, $member->member_id, $messageId);
	
				File::claimAttachments($autoSaveKey, $ticket['id'], $messageId);
	
				$output->redirect($request->url());
				return;
			}
		}

		$lang = $member->language();
		$theme = Theme::i();

		$actions = query_all(
			$db->select('a.created, a.kind, a.reference_id, u.name as initiator, m.text, m.flags, c.name_key, as.name as assigned_to_name', ['vssupport_ticket_action_history', 'a'],
				where: 'a.ticket = '.$ticket['id'].' AND !(IFNULL(m.flags, 0) & '.MessageFlags::Internal.')', order: 'a.created ASC')
			->join(['core_members', 'u'], 'u.member_id = a.initiator')
			->join(['vssupport_messages', 'm'], 'm.id = a.reference_id AND a.kind = '.ActionKind::Message)
			->join(['vssupport_ticket_categories', 'c'], 'c.id = a.reference_id AND a.kind = '.ActionKind::CategoryChange)
			->join(['core_members', 'as'], 'as.member_id = a.reference_id AND a.kind = '.ActionKind::Assigned)
		);
		foreach($actions as &$action) {
			if(!$action['initiator']) $action['initiator'] = $ticket['user_name'];
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
