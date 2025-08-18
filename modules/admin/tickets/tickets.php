<?php


namespace IPS\vssupport\modules\admin\tickets;

use IPS\Db;
use IPS\Dispatcher\Controller;
use IPS\File;
use IPS\Member;
use IPS\Output;
use IPS\Theme;
use IPS\Helpers;
use IPS\Helpers\Form;
use IPS\Http\Url;
use IPS\Request;
use IPS\Session;

use IPS\vssupport\MessageFlags;

use function defined;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class tickets extends Controller
{
	// Tell IVC that we handle csrf prot internally. @copypasta
	public static bool $csrfProtected = TRUE;

	public function execute() : void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'tickets_manage' );
		parent::execute();
	}

	// @copypasta: Copied in large parts from core.members .
	protected function manage() : void
	{
		$lang = Member::loggedIn()->language();
		$output = Output::i();

		/* Some advanced search links may bring us here */
		$output->bypassCsrfKeyCheck = true;

		$table = new Helpers\Table\Db('vssupport_tickets', Url::internal('app=vssupport&module=tickets&controller=tickets'));
		$table->langPrefix = 'tickets_';
		$table->keyField = 'id';

		$table->include = ['created', 'subject', 'user_name', 'user_email', 'status', 'assigned_to', 'last_update_at', 'last_update_by'];
		$table->mainColumn = 'created';

		$table->sortBy = $table->sortBy ?: 'created';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->quickSearch = function($string) {
			return Db::i()->like( 'name', $string, TRUE, TRUE, \IPS\core\extensions\core\LiveSearch\Members::canPerformInlineSearch() );
		};

		//TODO(Rennorb) @completeness: $table->advancedSearch

		//TODO(Rennorb) @completeness: $table->parsers

		$table->rowButtons = function($row)
		{
			return [
				'view' => [
					'icon'  => 'search',
					'title' => 'view',
					'link'  => URl::internal('app=vssupport&module=tickets&controller=tickets&do=view&id='.$row['id']),
				],
			];
		};

		$output->title = $lang->addToStack('tickets');
		$output->breadcrumb[] = [null, $lang->addToStack('tickets')];
		$output->output = Theme::i()->output .= $table;
	}

	public function view() : void
	{
		$output = Output::i();
		$lang = Member::loggedIn()->language();
		$db =  Db::i();

		$ticketId = intval(Request::i()->id);

		$r = $db->select('*', 'vssupport_tickets', 'id = '.$ticketId);
		$r->rewind();
		if(!$r->valid()) {
			$output->error('node_error', '2C114/O', 404, '');
			return;
		}
		$ticket = $r->current();

		$messages = [];
		$r = $db->select('*', 'vssupport_messages', 'ticket = '.$ticketId);
		for($r->rewind(); $r->valid(); $r->next()) {
			$messages[] = $r->current();
		}

		$form = static::_createMessageForm($ticketId, Url::internal('app=vssupport&module=tickets&controller=tickets&do=reply'));

		$output->title = $lang->addToStack('ticket').' #'.$ticketId.' - '.$ticket['subject'];
		$bc = &$output->breadcrumb;
		$bc[] = [URl::internal('app=vssupport&module=tickets&controller=tickets'), $lang->addToStack('tickets')];
		$bc[] = [null, $ticket['subject']];
		$output->output = Theme::i()->getTemplate('tickets')->ticket($ticket, $messages, $form);
	}

	public function reply() : void
	{
		$member = Member::loggedIn();
		$request = Request::i();
		$output = Output::i();

		$ticketId = intval($request->replyTo);
		$form = static::_createMessageForm($ticketId);

		if($values = $form->values()) {
			$flags = 0;
			if($request->submit === 'internal')   $flags |= MessageFlags::Internal;

			$messageId = Db::i()->insert('vssupport_messages', [
				'ticket'          => $ticketId,
				'text'            => $values['text'],
				'text_searchable' => strip_tags($values['text']),
				'user_name'       => $member->get_name(),
				'flags'           => $flags,
			]);

			File::claimAttachments('ticket-message', $ticketId, $messageId);
		}

		$output->redirect(Url::internal('app=vssupport&module=tickets&controller=tickets&do=view&id='.$ticketId));
	}

	static function _createMessageForm(int $ticketId, Url $action = null) : Form {
		$form = new Form(submitLang: null, action: $action);
		// Prevent the label being placed to the side. We want full width.
		$form->class = 'ipsForm--vertical';
		// Do the buttons manually because we want to set the name/value:
		$form->actionButtons[] = Theme::i()->getTemplate('forms', 'core', 'global')
			->button('respond_internal', 'submit', null, 'ipsButton ipsButton--secondary', ['tabindex' => '3', 'accesskey' => 's', 'name' => 'submit', 'value' => 'internal'] );
		$form->actionButtons[] = Theme::i()->getTemplate('forms', 'core', 'global')
			->button('respond_public', 'submit', null, 'ipsButton ipsButton--primary', ['tabindex' => '2', 'accesskey' => 's', 'name' => 'submit', 'value' => 'public'] );
		$form->hiddenValues['replyTo'] = $ticketId;
		$form->add(new Form\Editor('text', required: true, options: [
			'app' => 'vssupport',
			'key' => 'TicketText',
			'autoSaveKey' => 'ticket-message',
			'attachIds' => [$ticketId, null],
		]));

		return $form;
	}
}