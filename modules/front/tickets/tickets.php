<?php


namespace IPS\vssupport\modules\front\tickets;

use IPS\Db;
use IPS\Dispatcher\Controller;
use IPS\File;
use IPS\Member;
use IPS\Output;
use IPS\Theme;
use IPS\Helpers\Form;
use IPS\Http\Url;
use IPS\Request;
use IPS\vssupport\MessageFlags;

use function defined;
use function IPS\vssupport\query_all;
use function IPS\vssupport\query_all_assoc;
use function IPS\vssupport\query_one;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
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
		$lang = Member::loggedIn()->language();
		$output = Output::i();

		$output->title = $lang->addToStack('tickets');
		$output->breadcrumb[] = [null, $lang->addToStack('tickets')];
		$output->output = Theme::i()->getTemplate('tickets')->list();
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

		if($values = $form->values()) {
			if(!$member->member_id) {
				$name =  $values['name'];
				$email =  $values['email'];
			}
			else {
				$name = $member->get_name();
				$email = $member->email;
			}

			$ticketId = Db::i()->insert('vssupport_tickets', [
				'user_name'  => $name,
				'user_email' => $email,
				'category'   => $values['category'],
				'subject'    => $values['subject'],
			]);
			$messageId = Db::i()->insert('vssupport_messages', [
				'ticket'          => $ticketId,
				'text'            => $values['text'],
				'text_searchable' => strip_tags($values['text']),
				'user_name'       => $name,
			]);

			File::claimAttachments('new-ticket', $ticketId, $messageId);

			$output->redirect(Url::internal('app=vssupport&module=tickets&controller=tickets&do=view&id='.$ticketId));
			return;
		}

		$output->title = $lang->addToStack('ticket_create');
		$output->breadcrumb[] = [null, $lang->addToStack('ticket_create')];
		$output->output = $form;
	}

	public function view() : void
	{
		$output = Output::i();
		$db =  Db::i();

		$ticketId = intval(Request::i()->id); // TODO permissions

		$r = $db->select('*, vssupport_tickets.id, vssupport_ticket_categories.name_key as category', 'vssupport_tickets', 'vssupport_tickets.id = '.$ticketId)
			->join('vssupport_ticket_categories', 'vssupport_ticket_categories.id = vssupport_tickets.category');
		$ticket = query_one($r);
		if(!$ticket) {
			$output->error('node_error', '2C114/O', 404, '');
			return;
		}

		$lang = Member::loggedIn()->language();
		$theme = Theme::i();

		$messages = query_all($db->select('*', 'vssupport_messages', 'ticket = '.$ticketId.' AND !(flags & '.MessageFlags::Internal.')'));

		$output->title = $lang->addToStack('ticket').' #'.$ticketId.' - '.$ticket['subject'];
		$bc = &$output->breadcrumb;
		$bc[] = [URl::internal('app=vssupport&module=tickets&controller=tickets'), $lang->addToStack('my_tickets')];
		$bc[] = [null, $ticket['subject']];
		$output->output = $theme->getTemplate('tickets')->ticket($ticket, $messages);
		$output->cssFiles = array_merge($output->cssFiles, $theme->css('colors.css', location: 'global'));
	}
}
