<?php namespace IPS\vssupport\modules\admin\tickets;

use IPS\Application;
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
use IPS\vssupport\Email;
use IPS\vssupport\Message;
use IPS\vssupport\MessageFlags;
use IPS\vssupport\Moderators;
use IPS\vssupport\Notification;
use IPS\vssupport\StatusFlags;
use IPS\vssupport\Ticket;
use IPS\vssupport\TicketFlags;
use IPS\vssupport\TicketStatus;

use function IPS\vssupport\format_local_date_time;
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

	protected function manage() : void
	{
		$member = Member::loggedIn();
		$lang = $member->language();
		$output = Output::i();
		$theme = Theme::i();
		$request = Request::i();
		$db = Db::i();

		/* Some advanced search links may bring us here */
		$output->bypassCsrfKeyCheck = true;

		$baseUrl = Url::internal('app=vssupport&module=tickets&controller=tickets');
		$table = new Helpers\Table\Db('vssupport_tickets', $baseUrl);
		$table->enableRealtime = true;
		//$table->langPrefix = 'ticket_';
		$table->keyField = 'id';

		
		if(!$request->advancedSearchForm && !$request->advanced_search_submitted) {
			$tabs = [
				'u' => ['url' => $baseUrl->setQueryString('s', 'u'), 'name' => 'all_new_and_unassigned'],
				'a' => ['url' => $baseUrl->setQueryString('s', 'a'), 'name' => 'all_unresolved'],
				'm' => ['url' => $baseUrl, 'name' => 'all_new_and_assigned_to_me'],
				'n' => ['url' => $baseUrl->setQueryString('s', 'n'), 'name' => 'all_unresolved_and_assigned_to_me'],
			];

			$tabKey = array_key_exists($request->s, $tabs) ? $request->s : 'm';
			switch($tabKey) {
				case 'u':
					$table->where = [['!(s.flags & '.StatusFlags::TicketResolved.')'], ['assigned_to IS NULL']];
					$table->baseUrl = $table->baseUrl->setQueryString('s', $tabKey);
					break;

				case 'a':
					$table->where = [['!(s.flags & '.StatusFlags::TicketResolved.')']];
					$table->baseUrl = $table->baseUrl->setQueryString('s', $tabKey);
					break;

				case 'm':
					$table->where = [['status = '.TicketStatus::Open], ['assigned_to = ? ', $member->member_id]];
					// don't set search param, this here is the tab for now
					break;

				case 'n':
					$table->where = [['!(s.flags & '.StatusFlags::TicketResolved.')'], ['assigned_to = ? ', $member->member_id]];
					$table->baseUrl = $table->baseUrl->setQueryString('n', $tabKey);
					break;
			}

			$myId = intval($member->member_id);
			// @security: $myId is numeric, therefore sql inert.
			$counts = query_one($db->select("
				COUNT(CASE WHEN t.assigned_to IS NULL THEN 1 ELSE NULL END) AS `u`,
				COUNT(*) AS `a`,
				COUNT(CASE WHEN t.assigned_to = $myId AND t.status = ".TicketStatus::Open." THEN 1 ELSE NULL END) AS `m`,
				COUNT(CASE WHEN t.assigned_to = $myId THEN 1 ELSE NULL END) AS `n`
			", ['vssupport_tickets', 't'], '!(s.flags & '.StatusFlags::TicketResolved.')')
				->join(['vssupport_ticket_stati', 's'], 's.id = t.status', 'LEFT'));
			foreach($counts as $key => $count) {
				$tabs[$key]['count'] = $count;
			}

			$table->extraHtml = $theme->getTemplate('tickets', 'vssupport')->listExtra($tabs, $tabKey);
		}
		
		$table->include = ['ticket', 'status', 'priority', 'assigned_to', 'created', 'last_update'];
		$table->mainColumn = 'ticket';

		$table->joins = [[
			'select'=> 'm.name as assigned_to',
			'from'  => ['core_members', 'm'],
			'where' => 'm.member_id = vssupport_tickets.assigned_to',
			'type'  => 'LEFT'
		], [
			'select'=> 'la.last_update_at, la.conv_length',
			'from'  => $db->select('ticket, max(created) as last_update_at, count(CASE WHEN kind = '.ActionKind::Message.' THEN 1 ELSE NULL END) as conv_length', ['vssupport_ticket_action_history', 'la'], group: 'ticket'), //NOTE(Rennorb): Due to the extremely questionable implementation of the query joiner this _has to be_ a select object...
			'where' => 'la.ticket = vssupport_tickets.id',
			'type'  => 'LEFT'
		], [
			//'select'=> 'a.initiator as last_update_by',
			'from'  => ['vssupport_ticket_action_history', 'la2'],
			'where' => 'la2.ticket = vssupport_tickets.id AND la2.created = la.last_update_at',
			'type'  => 'LEFT'
		], [
			'select'=> 'lm.name as last_update_by_name',
			'from'  => ['core_members', 'lm'],
			'where' => 'lm.member_id = la2.initiator',
			'type'  => 'LEFT'
		], [
			'from'  => ['vssupport_ticket_stati', 's'],
			'where' => 's.id = vssupport_tickets.status',
			'type'  => 'LEFT'
		], [
			'select' => 'FROM_UNIXTIME((mark.item_app_key_2 << 32) | mark.item_app_key_3) >= la.last_update_at AS `read`', // :ReadMarkerTimestamps
			'from'   => ['core_item_markers', 'mark'],
			'where'  => "mark.item_app = 'vssupport' AND mark.item_member_id = {$member->member_id} AND mark.item_app_key_1 = vssupport_tickets.id",
			'type'   => 'LEFT',
		]];

		$table->parsers = [
			'ticket' => function($val, $row) {
				$subject = htmlspecialchars($row['subject'], ENT_DISALLOWED, 'UTF-8', FALSE);
				$category = Member::loggedIn()->language()->addToStack("ticket_category_{$row['category']}_name", options: ['escape' => 1]);
				$class = $row['read'] ? ' read' : ' i-color_contrast';
				return <<<HTML
					<div class="ticket-read-mark{$class}">
						<h4>{$subject}&nbsp;<small class="i-color_soft">#{$row['id']}</small></h4>
						<small class="i-color_soft">{$category}</small>
					</div>
				HTML;
			},
			'status' => function($val, $row) {
				return Member::loggedIn()->language()->addToStack("ticket_status_{$val}_name");
			},
			'priority' => function($val, $row) {
				$text = Member::loggedIn()->language()->addToStack("ticket_prio_{$val}_name", options: ['escape' => 1]);
				return "<span class='prio-label prio-$val'>$text</span>";
			},
			'created' => function($val, $row) {
				$name = htmlspecialchars($row['issuer_name'], ENT_DISALLOWED, 'UTF-8', FALSE);
				$date = format_local_date_time($val);
				return <<<HTML
					<div>
						<p>{$name}</p>
						<p><small class="i-color_soft">{$date->html(short: true)}</small></p>
					</div>
				HTML;
			},
			'last_update' => function($val, $row) {
				$name = htmlspecialchars($row['last_update_by_name'] ?: $row['issuer_name'], ENT_DISALLOWED, 'UTF-8', FALSE);
				$date = format_local_date_time($row['last_update_at'] ?? '0000-00-00 00:00:00');
				return <<<HTML
					<div>
						<p>{$name}</p>
						<p><small class="i-color_soft">{$date->html(short: true)}</small></p>
					</div>
				HTML;
			},
		];

		if(!isset($table->sortBy)) {
			$table->primarySortBy = 'priority';
			$table->primarySortDirection = 'desc';
		}

		//NOTE(Rennorb): I feel like the table->sortOptions should do this translation, but afaict those literally do nothing.
		$sortTranslation = [
			'ticket'      => 'vssupport_tickets.subject',
			'status'      => 'vssupport_tickets.status',
			'priority'    => 'vssupport_tickets.priority',
			'assigned_to' => 'vssupport_tickets.assigned_to',
			'created'     => 'vssupport_tickets.created',
			'last_update' => 'vssupport_tickets.last_update_at',
		];
		$table->sortBy = ($sortTranslation[$table->sortBy] ?? $table->sortBy) ?: 'created';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->quickSearch = 'subject';

		$priorities = [];
		$categories = [];
		$stati = [];
		if($request->advancedSearchForm || $request->advanced_search_submitted) {
			// Only do the heavy lifting when displaying the advanced search mask

			for($p = -2; $p <= 2; $p++) {
				$priorities[$p] = "ticket_prio_{$p}_name";
			}

			$q = $db->select('id', 'vssupport_ticket_categories');
			foreach($q as $id) {
				$categories[$id] = "ticket_category_{$id}_name";
			}
			$q = $db->select('id', 'vssupport_ticket_stati');
			foreach($q as $id) {
				$stati[$id] = "ticket_status_{$id}_name";
			}

			$table->advancedSearch = [
				'id'             => Helpers\Table\SEARCH_NUMERIC,
				'created'        => Helpers\Table\SEARCH_DATE_RANGE,
				'subject'        => Helpers\Table\SEARCH_CONTAINS_TEXT,
				'priority'       => [Helpers\Table\SEARCH_SELECT, ['noDefault' => true, 'multiple' => true, 'options' => $priorities]],
				'category'       => [Helpers\Table\SEARCH_SELECT, ['noDefault' => true, 'multiple' => true, 'options' => $categories]],
				'status'         => [Helpers\Table\SEARCH_SELECT, ['noDefault' => true, 'multiple' => true, 'options' => $stati]],
				'issuer_name'    => Helpers\Table\SEARCH_CONTAINS_TEXT,
				'issuer_email'   => Helpers\Table\SEARCH_CONTAINS_TEXT,
				'assigned_to'    => [Helpers\Table\SEARCH_MEMBER, [
					'autocomplete' => [
						'source'               => 'app=vssupport&module=tickets&controller=tickets&do=ajaxGetModerators',
						'resultItemTemplate'   => 'core.autocomplete.memberItem',
						'commaTrigger'         => false,
						'unique'               => true,
						'minAjaxLength'        => 3,
						'disallowedCharacters' => [],
						'lang'                 => 'mem_optional',
						'suggestionsOnly'      => true,
					],
				]],
				'last_update_at' => Helpers\Table\SEARCH_DATE_RANGE,
			];
		}
		else {
			$table->advancedSearch = ['dummy' => 1]; // dummy so the advanced search actually shows up
		}


		$table->rowButtons = function($row) {
			return [
				'conv_len' => [
					'icon'   => '',
					'title'  => 'conversation_length',
					'data'   => ['count' => $row['conv_length']],
				],
				'view' => [
					'icon'   => 'search',
					'title'  => 'view',
					'link'   => URl::internal('app=vssupport&module=tickets&controller=tickets&do=view&id='.$row['id']),
					'target' => '_blank',
				],
			];
		};

		$output->title = $lang->addToStack('tickets');
		$output->breadcrumb[] = [URl::internal('app=vssupport&module=tickets&controller=tickets'), $lang->addToStack('tickets')];
		// No way to make the wide table work properly via classes, so this template just wraps it in overflow auto.
		$output->output = $theme->getTemplate('tickets', 'vssupport')->list($table);
		$output->cssFiles = array_merge($output->cssFiles, $theme->css('global.css', location: 'global'), $theme->css('hideBar.css'));
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
		$member = Member::loggedIn();
		$lang = $member->language();
		$db =  Db::i();
		$request = Request::i();

		$ticketId = intval($request->id);

		$ticket = query_one(
			$db->select('t.*, u.name AS assigned_to_name', ['vssupport_tickets', 't'], 't.id = '.$ticketId)
			->join(['core_members', 'u'], 'u.member_id = t.assigned_to')
			->join(['vssupport_ticket_stati', 's'], 's.id = t.status')
		);
		if(!$ticket) {
			$output->error('node_error', '2C114/O', 404, '');
			return;
		}

		$sortDir = ($request->cookie['ticketSort'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

		$actions = query_all(
			$db->select('a.created, a.kind, a.reference_id, a.initiator AS initiator_id, u.name AS initiator, m.text, m.flags, as.name AS assigned_to_name', ['vssupport_ticket_action_history', 'a'], where: 'a.ticket = '.$ticketId, order: 'a.created '.$sortDir)
			->join(['core_members', 'u'], 'u.member_id = a.initiator')
			->join(['vssupport_messages', 'm'], 'm.id = a.reference_id AND a.kind = '.ActionKind::Message)
			->join(['core_members', 'as'], 'as.member_id = a.reference_id AND a.kind = '.ActionKind::Assigned)
		);
		
		foreach($actions as &$action) {
			// The null checks are here in case users get deleted. We cant really do much about it, but we can at least think about that case.
			if(!$action['initiator']) $action['initiator'] = $action['initiator_id'] === 0 ? $ticket['issuer_name'] : static::_unknownName($lang);
			if($action['kind'] === ActionKind::Assigned && !$action['assigned_to_name']) $action['assigned_to_name'] = static::_unknownName($lang);
			if($action['kind'] === ActionKind::PriorityChange) $action['reference_id'] -= 2; // :UnsignedPriority
			if($action['kind'] === ActionKind::Message) {
				$classes = ($action['flags'] & MessageFlags::Internal) ? 'message-internal' : (($action['initiator_id'] === 0 || $action['initiator'] === $ticket['issuer_name']) ? 'message-issuer' : 'message-moderator');
				if($action['flags'] & MessageFlags::EmailIngest) $classes .= ' email-ingest';
				$action['classes'] = $classes;
			}
		}
		unset($action);

		$form = static::_createMessageForm($ticketId, true, $ticket, Url::internal('app=vssupport&module=tickets&controller=tickets&do=reply&id='.$ticketId));

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

		$totalCount = query_one($db->select('COUNT(*)', 'vssupport_tickets', ['issuer_email = ?', $ticket['issuer_email']]));
		$tickets = query_all(
			$db->select('t.id, t.subject, t.priority, t.created, t.category, t.status', ['vssupport_tickets', 't'], [['t.issuer_email = ?', $ticket['issuer_email']], ['t.id != '.$ticketId]], 't.created DESC', 10)
			->join(['vssupport_ticket_stati', 's'], 's.id = t.status')
		);
		$extraBlocks[] = $theme->getTemplate('tickets', 'vssupport')->profileBlockList('other_tickets_by_this_issuer', $tickets, $totalCount, $ticket['issuer_email']);

		$output->title = $lang->addToStack('ticket').' #'.$ticketId.' - '.$ticket['subject'];
		$bc = &$output->breadcrumb;
		$bc[] = [URl::internal('app=vssupport&module=tickets&controller=tickets', seoTemplate: 'tickets_list'), $lang->addToStack('tickets')];
		$bc[] = [null, $ticket['subject']];
		$output->showTitle = false;
		$output->output = $theme->getTemplate('tickets', 'vssupport')->ticket($ticket, $actions, $form, $extraBlocks, $sortDir);
		$output->cssFiles = array_merge($output->cssFiles, $theme->css('global.css', location: 'global'), $theme->css('ticket.css'), $theme->css('hideBar.css'));

		Ticket::markRead($ticketId, $member->member_id);
	}

	public function reply() : void
	{
		$member = Member::loggedIn();
		$request = Request::i();
		$output = Output::i();
		$db = Db::i();

		$ticketId = intval($request->id);
		if($request->submit == 'nomsg') unset($request->text);
		$form = static::_createMessageForm($ticketId, false, null);

		$ticket = query_one($db->select('*, HEX(hash) AS hash', 'vssupport_tickets', 'id = '.$ticketId));

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

		$flags = 0;
		if(($values = $form->values()) && $values['text']) {
			if($request->submit === 'internal')   $flags |= MessageFlags::Internal;

			$message = Message::insert($db, $ticketId, $values['text'], $flags);
			$actions[] = ['kind' => ActionKind::Message, 'reference_id' => $message->id];

			File::claimAttachments(static::_formatEditorKey($ticketId), $ticketId, $message->id);
		}

		foreach($actions as &$action) {
			$action['ticket'] = $ticketId;
			$action['initiator'] = $member->member_id;
		}
		unset($action);
		$db->insert('vssupport_ticket_action_history', $actions);

		if(!($flags & MessageFlags::Internal) && $values['text']) {
			// needed for notification
			$message->ticketHash = $ticket['hash'];

			if($ticket['issuer_id']) {
				Notification::sendNewResponseNotification($ticketId, $ticket['hash'], Member::load($ticket['issuer_id']), $message, $member->name);
			}
			else {
				Email::sendTicketResponseEmail($ticketId, $ticket['hash'], $values['text'], $ticket['issuer_email'], $member->name);
			}
		}

		//TODO(Rennorb) @perf: Would love to send output first, then do the email processing, but IVC doesn't really allow that.
		$output->redirect(Url::internal('app=vssupport&module=tickets&controller=tickets&do=view&id='.$ticketId));
	}

	static function _createMessageForm(int $ticketId, bool $allInputs, array|null $ticket, Url $action = null) : Form {
		$theme = Theme::i();

		// submitLang = null disables builtin button
		$form = new Form(submitLang: null, action: $action);
		$form->attributes['id'] = 'reply-form';
		// Prevent the label being placed to the side. We want full width.
		$form->class = 'ipsForm--vertical';

		$defaultReply = null;
		// Do the buttons manually because we want more than a simple submit:
		if($allInputs) {
			$db = Db::i();
			$member = Member::loggedIn();
			$lang = $member->language();

			{
				$categories = query_all_assoc($db->select("id, 1", 'vssupport_ticket_categories'));
				foreach($categories as $catId => &$cat) {
					$cat = htmlspecialchars($lang->addToStack("ticket_category_{$catId}_name"), ENT_DISALLOWED, 'UTF-8', FALSE);
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
						'source'               => 'app=vssupport&module=tickets&controller=tickets&do=ajaxGetModerators',
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
				$stati = query_all_assoc($db->select("id, flags", 'vssupport_ticket_stati'));
				foreach($stati as $statusId => &$status) {
					$flags = $status;
					$status = htmlspecialchars($lang->addToStack("ticket_status_{$statusId}_name"), ENT_DISALLOWED, 'UTF-8', FALSE);
					if($flags & StatusFlags::TicketResolved)  $status .= ' [C]';
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
				$form->actionButtons[] = "<span style='user-select: none; align-self: center;' title=''>$lockEl</span>";
			}

			$submitButtons = '';
			$submitButtons .= $theme->getTemplate('forms', 'core', 'global')
				->button('respond_nomsg', 'submit', null, 'ipsButton ipsButton--secondary', ['tabindex' => '4', 'accesskey' => 's', 'name' => 'submit', 'value' => 'nomsg']);
			$submitButtons .= $theme->getTemplate('forms', 'core', 'global')
				->button('respond_internal', 'submit', null, 'ipsButton ipsButton--secondary', ['tabindex' => '3', 'accesskey' => 's', 'name' => 'submit', 'value' => 'internal']);
			$submitButtons .= $theme->getTemplate('forms', 'core', 'global')
				->button('respond_public', 'submit', null, 'ipsButton ipsButton--primary', ['tabindex' => '2', 'accesskey' => 's', 'name' => 'submit', 'value' => 'public']);

			$form->actionButtons[] = "<span class='i-margin-start_auto'>$submitButtons</span>";

			$defaultReply = query_one($db->select('default_reply', 'vssupport_mod_preferences', 'member_id = '.$member->member_id));
			if($defaultReply) $defaultReply = str_replace('{issuer_name}', $ticket['issuer_name'], $defaultReply);
		}


		$form->add(new Form\Editor('text', $defaultReply, required: false, options: [
			'app' => 'vssupport',
			'key' => 'TicketText',
			'autoSaveKey' => static::_formatEditorKey($ticketId),
			'attachIds' => null,
		]));

		return $form;
	}

	static function _formatEditorKey(int $ticketId) : string { return 'ticket-message-'.$ticketId; }

	public function preferences()
	{
		$member = Member::loggedIn();
		$lang = $member->language();
		$request = Request::i();
		$db = Db::i();

		if(!$request->form_submitted) {
			$default_reply = query_one($db->select('default_reply', 'vssupport_mod_preferences', 'member_id = '.$member->member_id));
		}
		else {
			$default_reply = null;
		}

		$form = new Form();
		// Prevent the label being placed to the side. We want full width.
		$form->class = 'ipsForm--vertical';

		$form->add(new Form\Editor('default_reply', $default_reply,  options: [
			'app'         => 'vssupport',
			'key'         => 'TicketText',
			'autoSaveKey' => 'default_message',
			'allowAttachments' => false,
			'tags'        => [
				'{issuer_name}' => $lang->addToStack('issuer_name'),
			],
		]));

		if($values = $form->values()) {
			$db->insert('vssupport_mod_preferences', ['member_id' => $member->member_id, 'default_reply' => trim($values['default_reply']) ?: null], true);
			$request->setClearAutosaveCookie('default_message');
		}

		Output::i()->output .= $form;
	}


	public function ajaxGetModerators()
	{
		$results = [];

		$searchLike = str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(Request::i()->input)).'%';
		$q = Moderators::select(Db::i(), '*', ['m.name LIKE ?', $searchLike], 'LENGTH(name) ASC', 20);
			
		foreach($q as $row)
		{
			$member = Member::constructFromData($row);

			$results[] = [
				'id'    => $member->member_id,
				'value' => $member->name,
				'name'  => $member->group['prefix'] . htmlspecialchars($member->name, ENT_DISALLOWED | ENT_QUOTES, 'UTF-8', FALSE) . $member->group['suffix'],
				'extra' => $member->groupName,
				'photo' => (string) $member->photo,
			];
		}

		Output::i()->json($results);
	}
}
