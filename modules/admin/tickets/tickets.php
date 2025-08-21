<?php namespace IPS\vssupport\modules\admin\tickets;

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

use IPS\vssupport\MessageFlags;
use IPS\vssupport\TicketFlags;

use function defined;
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
	// Tell IVC that we handle csrf prot internally. @copypasta
	public static bool $csrfProtected = TRUE;

	const PRIORITY_DONT_CHANGE = 999; // Special priority value that indicates we don't want to change the priority.

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
		$theme = Theme::i();

		/* Some advanced search links may bring us here */
		$output->bypassCsrfKeyCheck = true;

		$table = new Helpers\Table\Db('vssupport_tickets', Url::internal('app=vssupport&module=tickets&controller=tickets'));
		//$table->langPrefix = 'ticket_';
		$table->keyField = 'id';

		$table->include = ['created', 'priority', 'subject', 'category', 'status', 'user_name', 'user_email', 'assigned_to', 'last_update_at', 'last_update_by'];
		$table->mainColumn = 'created';

		$table->joins[] = [
			'select'=> 'vssupport_ticket_categories.name_key as category',
			'from'  => 'vssupport_ticket_categories',
			'where' => 'vssupport_ticket_categories.id = vssupport_tickets.category',
			'type'  => 'LEFT'
		];

		$table->joins[] = [
			'select'=> 'core_members.name as assigned_to',
			'from'  => 'core_members',
			'where' => 'core_members.member_id = vssupport_tickets.assigned_to',
			'type'  => 'LEFT'
		];

		$table->parsers['category'] = function($val, $row) {
			return Member::loggedIn()->language()->addToStack("ticket_cat_name_$val");
		};

		$table->parsers['priority'] = function($val, $row) {
			$text = Member::loggedIn()->language()->addToStack("ticket_prio_name_$val");
			return "<span class='prio-label prio-$val'>$text</span>";
		};

		$table->primarySortBy = 'priority';
		$table->primarySortDirection = 'desc';

		$table->sortBy = $table->sortBy ?: 'created';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->quickSearch = function($string) {
			return Db::i()->like( 'name', $string, TRUE, TRUE, \IPS\core\extensions\core\LiveSearch\Members::canPerformInlineSearch() );
		};

		//TODO(Rennorb) @completeness: $table->advancedSearch

		$table->rowButtons = function($row) {
			return [
				'view' => [
					'icon'  => 'search',
					'title' => 'view',
					'link'  => URl::internal('app=vssupport&module=tickets&controller=tickets&do=view&id='.$row['id'], seoTemplate: 'tickets_view'),
				],
			];
		};

		$output->title = $lang->addToStack('tickets');
		$output->breadcrumb[] = [null, $lang->addToStack('tickets')];
		// No way to make the wide table work properly via classes, so this template just wraps it in overflow auto.
		$output->output = $theme->getTemplate('tickets')->list($table);
		$output->cssFiles = array_merge($output->cssFiles, $theme->css('global.css', location: 'global'));
	}

	public function view() : void
	{
		$output = Output::i();
		$theme = Theme::i();
		$lang = Member::loggedIn()->language();
		$db =  Db::i();

		$ticketId = intval(Request::i()->id);

		$r = $db->select('*, vssupport_tickets.id, vssupport_ticket_categories.name_key as category', 'vssupport_tickets', 'vssupport_tickets.id = '.$ticketId)
			->join('vssupport_ticket_categories', 'vssupport_ticket_categories.id = vssupport_tickets.category');
		$ticket = query_one($r);
		if(!$ticket) {
			$output->error('node_error', '2C114/O', 404, '');
			return;
		}

		$messages = query_all($db->select('*', 'vssupport_messages', 'ticket = '.$ticketId));

		$categories = query_all_assoc($db->select("id, name_key", 'vssupport_ticket_categories'));
		foreach($categories as &$cat) {
			$cat = htmlspecialchars($lang->addToStack('ticket_cat_name_'.$cat), ENT_DISALLOWED, 'UTF-8', FALSE);
		}
		$categories = [0 => $lang->addToStack('dont_change')] + $categories; // Don't unshift here, would reindex the array!
		unset($cat);

		$form = static::_createMessageForm($ticketId, $categories, Url::internal('app=vssupport&module=tickets&controller=tickets&do=reply'));

		$output->title = $lang->addToStack('ticket').' #'.$ticketId.' - '.$ticket['subject'];
		$bc = &$output->breadcrumb;
		$bc[] = [URl::internal('app=vssupport&module=tickets&controller=tickets', seoTemplate: 'tickets_list'), $lang->addToStack('tickets')];
		$bc[] = [null, $ticket['subject']];
		$output->showTitle = false;
		$output->output = $theme->getTemplate('tickets')->ticket($ticket, $messages, $form);
		$output->cssFiles = array_merge($output->cssFiles, $theme->css('global.css', location: 'global'), $theme->css('ticket.css'));
	}

	public function reply() : void
	{
		$member = Member::loggedIn();
		$request = Request::i();
		$output = Output::i();
		$db = Db::i();

		$ticketId = intval($request->replyTo);
		$form = static::_createMessageForm($ticketId, [/* doesn't matter */]);
		
		if($values = $form->values()) {
			$ticketUpdates = [];
			if($newCategory = intval($request->moveToCategory)) {
				$ticketUpdates['category'] = $newCategory;
			}
			if(($newPriority = intval($request->moveToPriority)) !== static::PRIORITY_DONT_CHANGE) {
				$ticketUpdates['priority'] = $newPriority;
			}
			if($request->lock) {
				$ticketUpdates['flags'] = '`flags` | '.TicketFlags::Locked;
			}
			if($ticketUpdates) {
				$db->update('vssupport_tickets', $ticketUpdates, 'id = '.$ticketId, flags: Db::ALLOW_INCDEC_VALUES);
			}

			$flags = 0;
			if($request->submit === 'internal')   $flags |= MessageFlags::Internal;

			$messageId = $db->insert('vssupport_messages', [
				'ticket'          => $ticketId,
				'text'            => $values['text'],
				'text_searchable' => strip_tags($values['text']),
				'user_name'       => $member->get_name(),
				'flags'           => $flags,
			]);

			File::claimAttachments(static::_formatEditorKey($ticketId), $ticketId, $messageId);

			$output->redirect(Url::internal('app=vssupport&module=tickets&controller=tickets&do=view&id='.$ticketId, seoTemplate: 'tickets_view'));
		}
		else {
			$output->error('', '', 400, '');
		}
	}

	/**
	 * @param string[] $categories id to translatable strings map
	 */
	static function _createMessageForm(int $ticketId, array $categories, Url $action = null) : Form {
		$theme = Theme::i();

		// submitLang = null disables builtin button
		$form = new Form(submitLang: null, action: $action);
		// Prevent the label being placed to the side. We want full width.
		$form->class = 'ipsForm--vertical';
		$form->hiddenValues['replyTo'] = $ticketId;

		// Do the buttons manually because we want more than a simple submit:
		if($categories) {
			$lang = Member::loggedIn()->language();
			{
				$select = $theme->getTemplate('forms', 'core')
					->select('moveToCategory', '', false, $categories, class: 'ipsInput--auto');
				$title = htmlspecialchars($lang->addToStack('category'), ENT_DISALLOWED, 'UTF-8', FALSE);
				$form->actionButtons[] = "<span title='$title'>$select</span>";
			}
			{
				$options = [static::PRIORITY_DONT_CHANGE => $lang->addToStack('dont_change')];
				for($i = -2; $i <= 2; $i++) {
					$options[$i] = htmlspecialchars($lang->addToStack('ticket_prio_name_'.$i), ENT_DISALLOWED, 'UTF-8', FALSE);
				}
				$select = $theme->getTemplate('forms', 'core')
					->select('moveToPriority', '', false, $options, class: 'ipsInput--auto');
				$title = htmlspecialchars($lang->addToStack('priority'), ENT_DISALLOWED, 'UTF-8', FALSE);
				$form->actionButtons[] = "<span title='$title'>$select</span>";
			}
			{
				$lockEl = $theme->getTemplate('forms', 'core', 'global')
					->checkbox('lock', label: 'ticket_lock', fancyToggle: true, tooltip: $lang->addToStack('ticket_lock_desc'));
				$form->actionButtons[] = "<span style='user-select: none'>$lockEl</span>";
			}
			$form->actionButtons[] = '<span class="i-flex_91"></span>'; // spacer
			$form->actionButtons[] = $theme->getTemplate('forms', 'core', 'global')
				->button('respond_internal', 'submit', null, 'ipsButton ipsButton--secondary', ['tabindex' => '3', 'accesskey' => 's', 'name' => 'submit', 'value' => 'internal'] );
			$form->actionButtons[] = $theme->getTemplate('forms', 'core', 'global')
				->button('respond_public', 'submit', null, 'ipsButton ipsButton--primary', ['tabindex' => '2', 'accesskey' => 's', 'name' => 'submit', 'value' => 'public'] );
		}


		$form->add(new Form\Editor('text', required: true, options: [
			'app' => 'vssupport',
			'key' => 'TicketText',
			'autoSaveKey' => static::_formatEditorKey($ticketId),
			'attachIds' => null,
		]));

		return $form;
	}

	static function _formatEditorKey(int $ticketId) : string { return 'ticket-message-'.$ticketId; }
}
