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
use IPS\vssupport\Color;
use IPS\vssupport\StatusFlags;
use IPS\vssupport\TicketStatus;

use function defined;
use function IPS\vssupport\query_all_assoc;
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

		/* Some advanced search links may bring us here */
		$output->bypassCsrfKeyCheck = true;

		$table = new Helpers\Table\Db('vssupport_ticket_stati', Url::internal('app=vssupport&module=tickets&controller=stati'));
		//$table->langPrefix = 'stati_';
		$table->keyField = 'id';

		$table->include = ['preview', 'is_closing_status', 'tickets_count'];

		$table->joins['tickets_count'] = [
			'select'=> 'tickets_count',
			'from'  => Db::i()->select('status, count(*) as tickets_count', 'vssupport_tickets', group: 'status'),
			'where' => 'status = vssupport_ticket_stati.id',
			'type'  => 'LEFT'
		];

		$table->extraHtml = '<style>.label-test-light { background-color: white !important; padding: 0.25em; } .label-test-dark { background-color: #222222 !important; padding: 0.25em; }</style>';

		$table->parsers['preview'] = function($val, $row) {
			$label = Theme::i()->getTemplate('tickets', 'vssupport', 'global')->ticketLabel('status', $row['id']);
			$html = "<div><div class='label-test-light'>$label</div><div class='label-test-dark'>$label</div></div>";
			$lightBg = Color::toRgbaHexString($row['color_light_bg_rgb']);
			$lightFg = Color::toRgbaHexString($row['color_light_fg_rgb']);
			$darkBg  = Color::toRgbaHexString($row['color_dark_bg_rgb']);
			$darkFg  = Color::toRgbaHexString($row['color_dark_fg_rgb']);
			$style = "<style>.label-test-light .status-{$row['id']} { background-color: #$lightBg; color: #$lightFg } .label-test-dark .status-{$row['id']} { background-color: #$darkBg; color: #$darkFg }</style>";
			return $html.$style;
		};
		$table->parsers['is_closing_status'] = function($val, $row) {
			$ico = ($row['flags'] & StatusFlags::TicketResolved) ? 'fa-check': 'fa-times';
			return "<i class='fa-fw fa-solid $ico'></i>";
		};

		// $table->quickSearch = function($string) {
		// 	return Db::i()->like('name_key', $string, TRUE, TRUE, \IPS\core\extensions\core\LiveSearch\Members::canPerformInlineSearch());
		// };

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
					'link'  => URl::internal('app=vssupport&module=tickets&controller=stati&do=delete&id='.$row['id']),
					'data'  => ['ipsDialog' => '', 'ipsDialog-title' => Member::loggedIn()->language()->addToStack('status_delete')],
				];
			}
			return $buttons;
		};

		$output->addCssFiles('global.css', 'vssupport', 'global');
		$output->title = $lang->addToStack('stati');
		$output->breadcrumb[] = [null, $lang->addToStack('stati')];
		$output->output .= $table;
	}

	public function edit() : void
	{
		$request = Request::i();
		$editing = $request->id !== null;
		$statusId = intval($request->id);
		$output = Output::i();
		$db = Db::i();

		$form = new Helpers\Form(submitLang: $editing ? 'save' : 'status_add');
		$form->add(new Helpers\Form\Translatable('name', required: true, options: ['app' => 'vssupport', 'key' => $editing ? "ticket_status_{$statusId}_name" : null]));
		$currentValues = [
			'closing' => false,
			'color_light_bg_rgb' => 'cccccc',
			'color_dark_bg_rgb' => '333333',
		];
		if($editing && !$request->form_submitted) {
			$currentValues = query_one($db->select('color_light_bg_rgb, color_dark_bg_rgb, flags', 'vssupport_ticket_stati', 'id = '.$statusId));
			$currentValues['color_light_bg_rgb'] = substr(Color::toRgbaHexString($currentValues['color_light_bg_rgb']), 0, 6);
			$currentValues['color_dark_bg_rgb'] = substr(Color::toRgbaHexString($currentValues['color_dark_bg_rgb']), 0, 6);
			$currentValues['closing'] = boolval($currentValues['flags'] & StatusFlags::TicketResolved);
		}
		$form->add(new Helpers\Form\YesNo('is_closing_status', defaultValue: $currentValues['closing'], required: true, options: ['disabled' => $editing && $statusId <= TicketStatus::__MAX_BUILTIN ]));

		$form->add(new Helpers\Form\Color('label_light_bg', $currentValues['color_light_bg_rgb'], true));
		$form->add(new Helpers\Form\Color('label_dark_bg', $currentValues['color_dark_bg_rgb'], true));

		if($values = $form->values()) {
			$lightBg = Color::fromColorInputString($values['label_light_bg']);
			$lightFg = Color::isLightColor($lightBg >> 24, ($lightBg >> 16) & 0xff, ($lightBg >> 8) & 0xff) ? 0x0000_00ff : 0xffff_ffff;

			$darkBg = Color::fromColorInputString($values['label_dark_bg']);
			$darkFg = Color::isLightColor($darkBg >> 24, ($darkBg >> 16) & 0xff, ($darkBg >> 8) & 0xff) ? 0x0000_00ff : 0xffff_ffff;

			$data = [
				'flags' => $values['is_closing_status'] ? StatusFlags::TicketResolved : 0,
				'color_light_bg_rgb' => $lightBg,
				'color_light_fg_rgb' => $lightFg,
				'color_dark_bg_rgb'  => $darkBg,
				'color_dark_fg_rgb'  => $darkFg,
			];
			if($editing) {
				$db->update('vssupport_ticket_stati', $data, 'id = '.$statusId);
			}
			else {
				$statusId = $db->insert('vssupport_ticket_stati', $data);
			}
			Lang::saveCustom('vssupport', "ticket_status_{$statusId}_name", $values['name']);

			Color::updateLabelColorCssBlock($db);

			$output->redirect(Url::internal('app=vssupport&module=tickets&controller=stati'), $editing ? 'saved' : 'created');
			return;
		}

		$lang = Member::loggedIn()->language();

		$output->breadcrumb[] = [URl::internal('app=vssupport&module=tickets&controller=stati'), $lang->addToStack('stati')];
		$output->title = $lang->addToStack($editing ? 'status_edit' : 'status_add');
		$output->output .= $form;
	}

	public function delete() : void
	{
		$request = Request::i();
		$output = Output::i();

		$statusId = intval($request->id);

		if(!$statusId) {
			$output->error('missing_id', '', 400, '');
			return;
		}
		if($statusId <= TicketStatus::__MAX_BUILTIN) {
			$output->error('cannot_delete', '', 403, '');
			return;
		}

		$db = Db::i();

		if(query_one($db->select('COUNT(*)', ['vssupport_tickets', 't'], 't.status = '.$statusId)) > 0) {
			$form = new Helpers\Form(submitLang: 'transfer_and_delete');

			$otherStati = query_all_assoc($db->select("id, CONCAT('ticket_status_', id, '_name')", 'vssupport_ticket_stati', 'id != '.$statusId));
			$form->addMessage('status_replacement_notice');
			$form->add(new Helpers\Form\Select('status_replacement', required: true, options: ['options' => $otherStati]));

			$values = $form->values();
			if(!$values) {
				$output->output = $form;
				return;
			}

			$db->update('vssupport_tickets', ['status' => $values['status_replacement']], 'status = '.$statusId);
		}
		else {
			if(!$request->confirmedDelete(submit: 'status_delete'))   return;
		}

		$db->delete('vssupport_ticket_stati', 'id='.$statusId);
		$output->redirect(Url::internal('app=vssupport&module=tickets&controller=stati'), 'deleted');
	}
}
