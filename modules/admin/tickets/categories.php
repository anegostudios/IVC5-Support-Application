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

use function defined;
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
		Dispatcher::i()->checkAcpPermission('manage_categories');
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

		$table->include = ['name_key', 'translated_name'];

		$table->parsers['translated_name'] = function($val, $row) {
			return Member::loggedIn()->language()->addToStack("ticket_cat_name_{$row['name_key']}");
		};

		$table->quickSearch = function($string) {
			return Db::i()->like('name_key', $string, TRUE, TRUE, \IPS\core\extensions\core\LiveSearch\Members::canPerformInlineSearch());
		};

		$table->rootButtons = [[
			'icon' => 'plus-circle',
			'title' => 'category_add',
			'link' => URl::internal('app=vssupport&module=tickets&controller=categories&do=edit'),
		]];

		$table->rowButtons = function($row) {
			return [
				'edit' => [
					'icon'  => 'edit',
					'title' => 'edit',
					'link'  => URl::internal('app=vssupport&module=tickets&controller=categories&do=edit&id='.$row['id']),
				],
				'delete' => [
					'icon'  => 'times-circle',
					'title' => 'delete',
					'link'  => URl::internal('app=vssupport&module=tickets&controller=categories&do=delete&id='.$row['id'])->csrf(),
					'data'  => ['delete' => ''], // shows modal
				],
			];
		};

		$output->title = $lang->addToStack('categories');
		$output->breadcrumb[] = [null, $lang->addToStack('categories')];
		// No way to make the wide table work properly via classes, so this template just wraps it in overflow auto.
		$output->output = $theme->getTemplate('tickets')->list($table);
	}

	public function edit() : void
	{
		$categoryId = intval(Request::i()->id);
		$output = Output::i();
		$db = Db::i();

		$form = new Helpers\Form(submitLang: $categoryId ? 'save' : 'category_add');
		$nameKeyEl = new Helpers\Form\Text('name_key', required: true);
		if($categoryId) {
			$form->hiddenValues['categoryId'] = $categoryId;
			$nameKeyEl->value = query_one($db->select('name_key', 'vssupport_ticket_categories', 'id = '.$categoryId));
		}
		$form->add($nameKeyEl);

		if($values = $form->values()) {
			if($categoryId) { // editing existing
				$db->update('vssupport_ticket_categories', ['name_key' => $values['name_key']], 'id = '.$categoryId);
			}
			else { // create new
				$db->insert('vssupport_ticket_categories', ['name_key' => $values['name_key']]);
			}

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
		Session::i()->csrfCheck();
		$output = Output::i();
		
		//TODO move tickets to other category form
		$categoryId = intval(Request::i()->id);
		if(!$categoryId) {
			$output->error('missing_id', '', 400, '');
			return;
		}

		$affected = Db::i()->delete('vssupport_ticket_categories', 'id='.$categoryId);
		if(!$affected) {
			$output->error('category_not_found', '', 404, '');
			return;
		}

		$output->redirect(Url::internal('app=vssupport&module=tickets&controller=categories'), 'deleted');
	}
}
