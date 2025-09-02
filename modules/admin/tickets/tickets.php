<?php namespace IPS\vssupport\modules\admin\tickets;

use IPS\Db;
use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\File;
use IPS\Member;
use IPS\Output;
use IPS\Theme;
use IPS\Helpers;
use IPS\Helpers\Form;
use IPS\Http\Url;
use IPS\Request;
use IPS\vssupport\ActionKind;
use IPS\vssupport\MessageFlags;
use IPS\vssupport\TicketFlags;

use function IPS\vssupport\query_all;
use function IPS\vssupport\query_all_assoc;
use function IPS\vssupport\query_one;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class tickets extends Controller
{
	// Tell IVC that we handle csrf prot internally. @copypasta
	public static bool $csrfProtected = TRUE;

	public function execute() : void
	{
		\IPS\Dispatcher::i()->checkAcpPermission('tickets_manage');
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

		$table->include = ['id', 'created', 'priority', 'subject', 'category', 'status', 'issuer_name', 'issuer_email', 'assigned_to', 'last_update_at', 'last_update_by'];
		$table->mainColumn = 'created';

		$table->joins = [[
			'select'=> 'vssupport_ticket_categories.name_key as category',
			'from'  => 'vssupport_ticket_categories',
			'where' => 'vssupport_ticket_categories.id = vssupport_tickets.category',
			'type'  => 'LEFT'
		], [
			'select'=> 'vssupport_ticket_stati.name_key as status',
			'from'  => 'vssupport_ticket_stati',
			'where' => 'vssupport_ticket_stati.id = vssupport_tickets.status',
			'type'  => 'LEFT'
		], [
			'select'=> 'core_members.name as assigned_to',
			'from'  => 'core_members',
			'where' => 'core_members.member_id = vssupport_tickets.assigned_to',
			'type'  => 'LEFT'
		]];

		$table->parsers = [
			'category' => function($val, $row) {
				return Member::loggedIn()->language()->addToStack("ticket_cat_{$val}_name");
			},
			'status' => function($val, $row) {
				return Member::loggedIn()->language()->addToStack("ticket_status_{$val}_name");
			},
			'priority' => function($val, $row) {
				$text = Member::loggedIn()->language()->addToStack("ticket_prio_{$val}_name");
				return "<span class='prio-label prio-$val'>$text</span>";
			},
		];

		$table->primarySortBy = 'priority';
		$table->primarySortDirection = 'desc';

		$table->sortBy = $table->sortBy ?: 'created';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->quickSearch = function($string) {
			return Db::i()->like('name', $string, TRUE, TRUE, \IPS\core\extensions\core\LiveSearch\Members::canPerformInlineSearch());
		};

		//TODO(Rennorb) @completeness: $table->advancedSearch

		$table->rowButtons = function($row) {
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
		// No way to make the wide table work properly via classes, so this template just wraps it in overflow auto.
		$output->output = $theme->getTemplate('tickets')->list($table);
		$output->cssFiles = array_merge($output->cssFiles, $theme->css('global.css', location: 'global'));
	}

	static ?string $unknown = null;
	static function _unknownName($lang) : string
	{
		if(static::$unknown === null) {
			static::$unknown = $lang->addToStack('unknown');
		}
		return static::$unknown;
	}

	public function view() : void
	{
		$output = Output::i();
		$theme = Theme::i();
		$lang = Member::loggedIn()->language();
		$db =  Db::i();
		$request = Request::i();

		$ticketId = intval($request->id);

		$ticket = query_one(
			$db->select('t.*, c.name_key AS category_name, s.name_key as status_name, u.name AS assigned_to_name', ['vssupport_tickets', 't'], 't.id = '.$ticketId)
			->join(['vssupport_ticket_categories', 'c'], 'c.id = t.category')
			->join(['core_members', 'u'], 'u.member_id = t.assigned_to')
			->join(['vssupport_ticket_stati', 's'], 's.id = t.status')
		);
		if(!$ticket) {
			$output->error('node_error', '2C114/O', 404, '');
			return;
		}

		$actions = query_all(
			$db->select('a.created, a.kind, a.reference_id, a.initiator AS initiator_id, u.name AS initiator, m.text, m.flags, IFNULL(c.name_key, s.name_key) AS name_key, as.name AS assigned_to_name', ['vssupport_ticket_action_history', 'a'], where: 'a.ticket = '.$ticketId, order: 'a.created ASC')
			->join(['core_members', 'u'], 'u.member_id = a.initiator')
			->join(['vssupport_messages', 'm'], 'm.id = a.reference_id AND a.kind = '.ActionKind::Message)
			->join(['vssupport_ticket_categories', 'c'], 'c.id = a.reference_id AND a.kind = '.ActionKind::CategoryChange)
			->join(['vssupport_ticket_stati', 's'], 's.id = a.reference_id AND a.kind = '.ActionKind::StatusChange)
			->join(['core_members', 'as'], 'as.member_id = a.reference_id AND a.kind = '.ActionKind::Assigned)
		);
		
		foreach($actions as &$action) {
			// The null checks are here in case users get deleted. We cant really do much about it, but we can at least think about that case.
			if(!$action['initiator']) $action['initiator'] = $action['initiator_id'] === 0 ? $ticket['issuer_name'] : static::_unknownName($lang);
			if($action['kind'] === ActionKind::Assigned && !$action['assigned_to_name']) $action['assigned_to_name'] = static::_unknownName($lang);
			if($action['kind'] === ActionKind::PriorityChange) $action['reference_id'] -= 2; // :UnsignedPriority
		}
		unset($action);

		$form = static::_createMessageForm($ticketId, true, $ticket, Url::internal('app=vssupport&module=tickets&controller=tickets&do=reply'));

		$extraBlocks = [];

		if($ticket['issuer_id']) {
			$dispatcher = Dispatcher::i();
			if($dispatcher->checkAcpPermission('purchases_view', 'nexus', 'customers', true)) {
				$extraBlocks[] = purchases::formatBlock($ticketId, $ticket['issuer_id']);
			}
			if($dispatcher->checkAcpPermission('invoices_manage', 'nexus', 'invoices', true)) {
				$extraBlocks[] = invoices::formatBlock($ticketId, $ticket['issuer_id'], $ticket['issuer_name']);
			}
		}

		$output->title = $lang->addToStack('ticket').' #'.$ticketId.' - '.$ticket['subject'];
		$bc = &$output->breadcrumb;
		$bc[] = [URl::internal('app=vssupport&module=tickets&controller=tickets', seoTemplate: 'tickets_list'), $lang->addToStack('tickets')];
		$bc[] = [null, $ticket['subject']];
		$output->showTitle = false;
		$output->output = $theme->getTemplate('tickets')->ticket($ticket, $actions, $form, $extraBlocks);
		$output->cssFiles = array_merge($output->cssFiles, $theme->css('global.css', location: 'global'), $theme->css('ticket.css'));
	}

	public function reply() : void
	{
		$member = Member::loggedIn();
		$request = Request::i();
		$output = Output::i();
		$db = Db::i();

		$ticketId = intval($request->replyTo);
		$form = static::_createMessageForm($ticketId, false, null);

		$ticket = query_one($db->select('*', 'vssupport_tickets', 'id = '.$ticketId));

		$ticketUpdates = [];
		$actions = [];
		if(($newCategory = intval($request->moveToCategory)) !== $ticket['category']) {
			$ticketUpdates['category'] = $newCategory;
			$actions[] = ['kind' => ActionKind::CategoryChange, 'reference_id' => $newCategory];
		}
		if(($newStatus = intval($request->moveToStatus)) !== $ticket['status']) {
			$ticketUpdates['status'] = $newStatus;
			$actions[] = ['kind' => ActionKind::StatusChange, 'reference_id' => $newStatus];
		}
		if(($newPriority = intval($request->moveToPriority)) !== $ticket['priority']) {
			$ticketUpdates['priority'] = $newPriority;
			$actions[] = ['kind' => ActionKind::PriorityChange, 'reference_id' => 2 + $newPriority]; // The column here is unsigned, need to wrap the priority. :UnsignedPriority
		}
		if(($newLockState = !!$request->lock) !== !!($ticket['flags'] & TicketFlags::Locked)) {
			$ticketUpdates['flags'] = $newLockState ? ('`flags` | '.TicketFlags::Locked) : ('`flags` & ~'.TicketFlags::Locked);
			$actions[] = ['kind' => ActionKind::LockedChange, 'reference_id' => intval($newLockState)];
		}
		{
			//TODO(Rennorb) @cleanup: This is stupid. Find a way to get back the member id, not the member name...
			$newAssignment = query_one($db->select('member_id', 'core_members', ['name = ?', $request->assignTo]));
			//NOTE(Rennorb): Coercing comparison here, because assignment = null should be treated as assignment = 0.
			if($newAssignment != $ticket['assigned_to']) {
				$ticketUpdates['assigned_to'] = $newAssignment;
				$actions[] = ['kind' => ActionKind::Assigned, 'reference_id' => $newAssignment ?: null];
			}
		}
		
		if($ticketUpdates) {
			$db->update('vssupport_tickets', $ticketUpdates, 'id = '.$ticketId, flags: Db::ALLOW_INCDEC_VALUES);
		}

		if(($values = $form->values()) && $values['text']) {
			$flags = 0;
			if($request->submit === 'internal')   $flags |= MessageFlags::Internal;

			$messageId = $db->insert('vssupport_messages', [
				'ticket'          => $ticketId,
				'text'            => $values['text'],
				'text_searchable' => strip_tags($values['text']),
				'flags'           => $flags,
			]);
			$actions[] = ['kind' => ActionKind::Message, 'reference_id' => $messageId];

			File::claimAttachments(static::_formatEditorKey($ticketId), $ticketId, $messageId);
		}

		foreach($actions as &$action) {
			$action['ticket'] = $ticketId;
			$action['initiator'] = $member->member_id;
		}
		unset($action);
		$db->insert('vssupport_ticket_action_history', $actions);


		$output->redirect(Url::internal('app=vssupport&module=tickets&controller=tickets&do=view&id='.$ticketId));
	}

	static function _createMessageForm(int $ticketId, bool $allInputs, array|null $ticket, Url $action = null) : Form {
		$theme = Theme::i();

		// submitLang = null disables builtin button
		$form = new Form(submitLang: null, action: $action);
		// Prevent the label being placed to the side. We want full width.
		$form->class = 'ipsForm--vertical';
		$form->hiddenValues['replyTo'] = $ticketId;

		// Do the buttons manually because we want more than a simple submit:
		if($allInputs) {
			$db = Db::i();
			$lang = Member::loggedIn()->language();

			{
				$categories = query_all_assoc($db->select("id, name_key", 'vssupport_ticket_categories'));
				foreach($categories as $catId => &$cat) {
					$cat = htmlspecialchars($lang->addToStack("ticket_cat_{$cat}_name"), ENT_DISALLOWED, 'UTF-8', FALSE);
					if($catId === $ticket['category'])  $cat .= ' ('.htmlspecialchars($lang->addToStack('current'), ENT_DISALLOWED, 'UTF-8', FALSE).')';
				}
				unset($cat);

				$select = $theme->getTemplate('forms', 'core')
					->select('moveToCategory', $ticket['category'], false, $categories, class: 'ipsInput--auto stretch');
				$title = htmlspecialchars($lang->addToStack('category'), ENT_DISALLOWED, 'UTF-8', FALSE);
				$form->actionButtons[] = "<span title='$title' class='i-flex_00'>$select</span>";
			}
			{
				for($i = -2; $i <= 2; $i++) {
					$options[$i] = htmlspecialchars($lang->addToStack("ticket_prio_{$i}_name"), ENT_DISALLOWED, 'UTF-8', FALSE);
					if($i === $ticket['priority'])  $options[$i] .= ' ('.htmlspecialchars($lang->addToStack('current'), ENT_DISALLOWED, 'UTF-8', FALSE).')';
				}
				$select = $theme->getTemplate('forms', 'core')
					->select('moveToPriority', $ticket['priority'], false, $options, class: 'ipsInput--auto stretch');
				$title = htmlspecialchars($lang->addToStack('priority'), ENT_DISALLOWED, 'UTF-8', FALSE);
				$form->actionButtons[] = "<span title='$title' class='i-flex_00'>$select</span>";
			}
			{
				$select = (new Form\Member('assignTo', $ticket['assigned_to_name'], options: [
					// Copy pasted from the original, just with the additional &type=mod.
					'autocomplete' => [
						'source'               => 'app=core&module=system&controller=ajax&do=findMember&type=mod',
						'resultItemTemplate'   => 'core.autocomplete.memberItem',
						'commaTrigger'         => false,
						'unique'               => true,
						'minAjaxLength'        => 3,
						'disallowedCharacters' => [],
						'lang'                 => 'mem_optional',
						'suggestionsOnly'      => true,
					],
					'placeholder' => 'dont_change',
				]))->html();
				$title = htmlspecialchars($lang->addToStack('assigned_to'), ENT_DISALLOWED, 'UTF-8', FALSE);
				$form->actionButtons[] = "<span title='$title' class='i-flex_00'>$select</span>";
			}
			{
				$stati = query_all_assoc($db->select("id, name_key", 'vssupport_ticket_stati'));
				foreach($stati as $statusId => &$status) {
					$status = htmlspecialchars($lang->addToStack("ticket_status_{$status}_name"), ENT_DISALLOWED, 'UTF-8', FALSE);
					if($statusId === $ticket['status'])  $status .= ' ('.htmlspecialchars($lang->addToStack('current'), ENT_DISALLOWED, 'UTF-8', FALSE).')';
				}
				unset($status);

				$select = $theme->getTemplate('forms', 'core')
					->select('moveToStatus', $ticket['status'], false, $stati, class: 'ipsInput--auto stretch');
				$title = htmlspecialchars($lang->addToStack('status'), ENT_DISALLOWED, 'UTF-8', FALSE);
				$form->actionButtons[] = "<span title='$title' class='i-flex_00'>$select</span>";
			}
			{
				$lockEl = $theme->getTemplate('forms', 'core', 'global')
					->checkbox('lock', $ticket['flags'] & TicketFlags::Locked, label: 'ticket_lock', fancyToggle: true, tooltip: $lang->addToStack('ticket_lock_desc'));
				$form->actionButtons[] = "<span style='user-select: none; vertical-align: center;'>$lockEl</span>";
			}
			$form->actionButtons[] = '<span class="i-flex_91" style="flex-basis: 20ch;"></span>'; // spacer
			$form->actionButtons[] = $theme->getTemplate('forms', 'core', 'global')
				->button('respond_internal', 'submit', null, 'ipsButton ipsButton--secondary', ['tabindex' => '3', 'accesskey' => 's', 'name' => 'submit', 'value' => 'internal']);
			$form->actionButtons[] = $theme->getTemplate('forms', 'core', 'global')
				->button('respond_public', 'submit', null, 'ipsButton ipsButton--primary', ['tabindex' => '2', 'accesskey' => 's', 'name' => 'submit', 'value' => 'public']);
		}


		$form->add(new Form\Editor('text', required: false, options: [
			'app' => 'vssupport',
			'key' => 'TicketText',
			'autoSaveKey' => static::_formatEditorKey($ticketId),
			'attachIds' => null,
		]));

		return $form;
	}

	static function _formatEditorKey(int $ticketId) : string { return 'ticket-message-'.$ticketId; }
}
