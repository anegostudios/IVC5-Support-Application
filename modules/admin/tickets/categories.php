<?php namespace IPS\vssupport\modules\admin\tickets;

use IPS\Db;
use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\Member;
use IPS\Theme;
use IPS\Output;
use IPS\Helpers;
use IPS\Http\Url;
use IPS\Lang;
use IPS\Request;
use IPS\Session;

use function defined;
use function IPS\vssupport\query_all_assoc;
use function IPS\vssupport\query_one;

if(!defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class categories extends Controller
{
	public static bool $csrfProtected = TRUE; // manual csrf

	public function execute() : void
	{
		Dispatcher::i()->checkAcpPermission('categories_manage');
		parent::execute();
	}

	protected function manage() : void
	{
		$lang = Member::loggedIn()->language();
		$output = Output::i();
		$theme = Theme::i();

		/* Some advanced search links may bring us here */
		$output->bypassCsrfKeyCheck = true;

		$table = new Helpers\Table\Db('vssupport_ticket_categories', Url::internal('app=vssupport&module=tickets&controller=categories'));
		//$table->langPrefix = 'category_';
		$table->keyField = 'id';

		$table->include = ['name', 'tickets_count'];

		$table->joins['tickets_count'] = [
			'select'=> 'tickets_count',
			'from'  => Db::i()->select('category, count(*) as tickets_count', 'vssupport_tickets', group: 'category'),
			'where' => 'category = vssupport_ticket_categories.id',
			'type'  => 'LEFT'
		];

		$table->parsers['name'] = function($val, $row) {
			return Member::loggedIn()->language()->addToStack("ticket_category_{$row['id']}_name");
		};

		// $table->quickSearch = function($string) {
		// 	return Db::i()->like('name_key', $string, TRUE, TRUE, \IPS\core\extensions\core\LiveSearch\Members::canPerformInlineSearch());
		// };

		$table->rootButtons = [[
			'icon'  => 'plus-circle',
			'title' => 'category_add',
			'link'  => URl::internal('app=vssupport&module=tickets&controller=categories&do=edit'),
			'data'  => ['ipsDialog' => '', 'ipsDialog-title' => $lang->addToStack('category_add')],
		]];

		$table->rowButtons = function($row) {
			return [
				'edit' => [
					'icon'  => 'edit',
					'title' => 'edit',
					'link'  => URl::internal('app=vssupport&module=tickets&controller=categories&do=edit&id='.$row['id']),
					'data'  => ['ipsDialog' => '', 'ipsDialog-title' => Member::loggedIn()->language()->addToStack('category_edit')],
				],
				'delete' => [
					'icon'  => 'times-circle',
					'title' => 'delete',
					'link'  => URl::internal('app=vssupport&module=tickets&controller=categories&do=delete&id='.$row['id']),
					'data'  => ['ipsDialog' => '', 'ipsDialog-title' => Member::loggedIn()->language()->addToStack('category_delete')],
				],
			];
		};

		$output->title = $lang->addToStack('categories');
		$output->breadcrumb[] = [null, $lang->addToStack('categories')];
		$output->output .= $table;
	}

	public function edit() : void
	{
		$categoryId = intval(Request::i()->id);
		$output = Output::i();
		$db = Db::i();

		$form = new Helpers\Form(submitLang: $categoryId ? 'save' : 'category_add');
		$form->add(new Helpers\Form\Translatable('name', required: true, options: ['app' => 'vssupport', 'key' => $categoryId ? "ticket_category_{$categoryId}_name" : null]));

		if($values = $form->values()) {
			if(!$categoryId) { // create new
				$categoryId = $db->insert('vssupport_ticket_categories', []);
			}
			Lang::saveCustom('vssupport', "ticket_category_{$categoryId}_name", $values['name']);

			$output->redirect(Url::internal('app=vssupport&module=tickets&controller=categories'), $categoryId ? 'saved' : 'created');
			return;
		}

		$lang = Member::loggedIn()->language();

		$output->breadcrumb[] = [URl::internal('app=vssupport&module=tickets&controller=categories'), $lang->addToStack('categories')];
		$output->title = $lang->addToStack($categoryId ? 'category_edit' : 'category_add');
		$output->output .= $form;
	}

	public function delete() : void
	{
		$request = Request::i();
		$output = Output::i();

		$categoryId = intval($request->id);

		if(!$categoryId) {
			$output->error('missing_id', '', 400, '');
			return;
		}

		$db = Db::i();

		if(query_one($db->select('COUNT(*)', ['vssupport_tickets', 't'], 't.category = '.$categoryId)) > 0) {
			$form = new Helpers\Form(submitLang: 'transfer_and_delete');

			$otherCategories = query_all_assoc($db->select("id, CONCAT('ticket_category_', id, '_name')", 'vssupport_ticket_categories', 'id != '.$categoryId));
			$form->addMessage('category_replacement_notice');
			$form->add(new Helpers\Form\Select('category_replacement', required: true, options: ['options' => $otherCategories]));

			$values = $form->values();
			if(!$values) {
				$output->output = $form;
				return;
			}

			$db->update('vssupport_tickets', ['category' => $values['category_replacement']], 'category = '.$categoryId);
		}
		else {
			if(!$request->confirmedDelete(submit: 'category_delete'))   return;
		}

		$db->delete('vssupport_ticket_categories', 'id='.$categoryId);
		$output->redirect(Url::internal('app=vssupport&module=tickets&controller=categories'), 'deleted');
	}
}
