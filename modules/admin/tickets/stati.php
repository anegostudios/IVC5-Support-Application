<?php namespace IPS\vssupport\modules\admin\tickets;

use IPS\Db;
use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\Member;
use IPS\Theme;
use IPS\Output;
use IPS\Helpers;
use IPS\Http\Url;
use IPS\Request;
use IPS\Session;
use IPS\vssupport\TicketStatus;

use function defined;
use function IPS\vssupport\query_one;

if(!defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class stati extends Controller
{
	public static bool $csrfProtected = TRUE; // manual csrf

	public function execute() : void
	{
		Dispatcher::i()->checkAcpPermission('stati_manage');
		parent::execute();
	}

	protected function manage() : void
	{
		$lang = Member::loggedIn()->language();
		$output = Output::i();
		$theme = Theme::i();

		/* Some advanced search links may bring us here */
		$output->bypassCsrfKeyCheck = true;

		$table = new Helpers\Table\Db('vssupport_ticket_stati', Url::internal('app=vssupport&module=tickets&controller=stati'));
		//$table->langPrefix = 'stati_';
		$table->keyField = 'id';

		$table->include = ['name_key', 'translated_name', 'is_closing_status'];

		$table->parsers['translated_name'] = function($val, $row) {
			return Member::loggedIn()->language()->addToStack("ticket_status_{$row['name_key']}_name");
		};
		$table->parsers['is_closing_status'] = function($val, $row) {
			$ico = ($row['id'] & TicketStatus::__FLAG_CLOSED) ? 'fa-check': 'fa-times';
			return "<i class='fa-fw fa-solid $ico'></i>";
		};

		$table->quickSearch = function($string) {
			return Db::i()->like('name_key', $string, TRUE, TRUE, \IPS\core\extensions\core\LiveSearch\Members::canPerformInlineSearch());
		};

		$table->rootButtons = [[
			'icon' => 'plus-circle',
			'title' => 'status_add',
			'link' => URl::internal('app=vssupport&module=tickets&controller=stati&do=edit'),
			'data'  => ['ipsDialog' => '', 'ipsDialog-title' => $lang->addToStack('status_add')],
		]];

		$table->rowButtons = function($row) {
			$buttons = [
				'edit' => [
					'icon'  => 'edit',
					'title' => 'edit',
					'link'  => URl::internal('app=vssupport&module=tickets&controller=stati&do=edit&id='.$row['id']),
					'data'  => ['ipsDialog' => '', 'ipsDialog-title' => Member::loggedIn()->language()->addToStack('status_edit')],
				],
			];
			if($row['id'] > TicketStatus::__MAX_BUILTIN) {
				$buttons['delete'] = [
					'icon'  => 'times-circle',
					'title' => 'delete',
					'link'  => URl::internal('app=vssupport&module=tickets&controller=stati&do=delete&id='.$row['id'])->csrf(),
					'data'  => ['delete' => ''], // shows modal
				];
			}
			return $buttons;
		};

		$output->title = $lang->addToStack('stati');
		$output->breadcrumb[] = [null, $lang->addToStack('stati')];
		// No way to make the wide table work properly via classes, so this template just wraps it in overflow auto.
		$output->output = $theme->getTemplate('tickets')->list($table);
	}

	public function edit() : void
	{
		$statusId = intval(Request::i()->id);
		$output = Output::i();
		$db = Db::i();

		$form = new Helpers\Form(submitLang: $statusId ? 'save' : 'status_add');
		$oldVal = null;
		if($statusId && empty(Request::i()->status_name_key)) {
			$oldVal = query_one($db->select('name_key', 'vssupport_ticket_stati', 'id = '.$statusId));
		}
		$form->add(new Helpers\Form\Text('status_name_key', defaultValue: $oldVal, required: true));

		if($values = $form->values()) {
			if($statusId) { // editing existing
				$db->update('vssupport_ticket_stati', ['name_key' => $values['status_name_key']], 'id = '.$statusId);
			}
			else { // create new
				$db->insert('vssupport_ticket_stati', ['name_key' => $values['status_name_key']]);
			}

			$output->redirect(Url::internal('app=vssupport&module=tickets&controller=stati'), $statusId ? 'saved' : 'created');
			return;
		}

		$lang = Member::loggedIn()->language();

		$output->breadcrumb[] = [URl::internal('app=vssupport&module=tickets&controller=stati'), $lang->addToStack('stati')];
		$output->title = $lang->addToStack($statusId ? 'status_edit' : 'status_add');
		$output->output .= $form;
	}

	public function delete() : void
	{
		Session::i()->csrfCheck();
		$output = Output::i();
		
		//TODO move tickets to other status form
		$statusId = intval(Request::i()->id);
		if(!$statusId) {
			$output->error('missing_id', '', 400, '');
			return;
		}

		$affected = Db::i()->delete('vssupport_ticket_stati', 'id='.$statusId);
		if(!$affected) {
			$output->error('status_not_found', '', 404, '');
			return;
		}

		$output->redirect(Url::internal('app=vssupport&module=tickets&controller=stati'), 'deleted');
	}
}
