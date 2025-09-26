<?php namespace IPS\vssupport\modules\admin\tickets;

use IPS\Application;
use IPS\Data\Store;
use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\Member;
use IPS\Output;
use IPS\Helpers\Form;
use IPS\Http\Url;
use IPS\Settings as IPSSettings;
use IPS\vssupport\Email;

use function defined;

if(!defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class settings extends Controller
{
	public static bool $csrfProtected = TRUE; // manual csrf

	public function execute() : void
	{
		Dispatcher::i()->checkAcpPermission('manage_settings');
		parent::execute();
	}

	protected function manage() : void
	{
		$lang = Member::loggedIn()->language();
		$output = Output::i();
		$settings = IPSSettings::i();

		$form = new Form();
		$form->addHeader('email_ingres');
		$form->addMessage('email_ingres_only_supports_imap_for_now');
		
		$oldSettings = json_decode($settings->{Email::SETTING_MAIL_INGRES}, true);

		if(!function_exists('imap_open')) {
			$form->addMessage('php_imap_not_installed', 'ipsMessage--error');
		}
		if(!defined('I_MANUALLY_PATCHED_THE_CORE_SMTP_IMPLEMENTATION') /* && Application::getAvailableVersion('core') < ??? */) {
			$form->addMessage('core_smtp_impl_bug_error', 'ipsMessage--error');
		}
		else {
			$form->add(new Form\Text('smtp_host', $oldSettings['host'] ?? null, true, ['placeholder' => 'mail.my-domain.com:993']));
			$form->add(new Form\YesNo('smtp_validate_cert', $oldSettings['validate_cert'] ?? true));
			$form->add(new Form\YesNo('smtp_ssl', $oldSettings['ssl'] ?? true));
			$form->add(new Form\YesNo('smtp_tls', $oldSettings['tsl'] ?? false));
			$form->add(new Form\Text('smtp_user', $oldSettings['user'] ?? null, true, ['placeholder' => 'user@my-domain.com']));
			$form->add(new Form\Password('smtp_pass', $oldSettings['pass'] ?? null));

			$form->add(new Form\Text('smtp_inbox', $oldSettings['inbox'] ?? 'INBOX', true));

			$form->add(new Form\YesNo('smtp_delete_processed_mails', $oldSettings['delete'] ?? true));

			if($values = $form->values()) {
				$settingsArray = [
					'mode' => 'imap',
					'host' => $values['smtp_host'],
					'ssl' => $values['smtp_ssl'] ? 1 : 0,
					'tls' => $values['smtp_tls'] ? 1 : 0,
					'validate_cert' => $values['smtp_validate_cert'] ? 1 : 0,
					'user' => $values['smtp_user'],
					'pass' => $values['smtp_pass'] ?? '',
					'inbox' => $values['smtp_inbox'],
					'delete' => $values['smtp_delete_processed_mails'],
				];

				$server = Email::_formServerStringImap($settingsArray);
				$conn = \imap_open($server, $settingsArray['user'], $settingsArray['pass'], OP_HALFOPEN);
				if($conn === false) {
					$form->error = $lang->addToStack('imap_connection_failed', options: ['sprintf' => \imap_last_error()]);
				}
				else {
					$boxStatus = \imap_status($conn, $server.$settingsArray['inbox'], SA_UIDVALIDITY | SA_UIDNEXT);
					\imap_close($conn);
					if($boxStatus === false) {
						$form->error = $lang->addToStack('imap_mailbox_not_found', options: ['sprintf' => \imap_last_error()]);
					}
					else {
						// Might aswell store the info
						$store = Store::i();
						$store->{Email::STORAGE_IMAP_VALIDITY} = $boxStatus->uidvalidity;
						$store->{Email::STORAGE_IMAP_NEXT_UID} = $boxStatus->uidnext;

						$settings->changeValues([Email::SETTING_MAIL_INGRES => json_encode($settingsArray)]);
						$output->redirect(Url::internal('app=vssupport&module=tickets&controller=settings'), 'saved');
						return;
					}
				}
			}
		}

		$output->title = $lang->addToStack('vssupport_settings');
		$output->output .= $form;
	}
}
