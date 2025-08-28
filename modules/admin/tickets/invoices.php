<?php namespace IPS\vssupport\modules\admin\tickets;

use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\Http\Url;
use IPS\Member;
use IPS\nexus\Invoice;
use IPS\Output;
use IPS\Request;
use IPS\Theme;
use IPS\vssupport\UrlReflection;

use function defined;

if(!defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

//NOTE(Rennorb): A lot of the functions we need do exist, but are unfortunately not quite customizable enough for what we want to do.
// Often it's simply a missing redirect parameter, or some other ux issue. We therefore route all the actions through us so we can manipulate the result before returning it.
// We do not directly extend the original controller on purpose, just so we wont get any wired permissions issues. We only expose a very small surface.
// :InvoicesRelay

class invoices extends Controller
{
	static function formatBlock(int $ticketId, int $targetMemberId, string $targetMemberName) : string
	{
		$theme = Theme::i();

		$url = Url::internal('app=vssupport&module=tickets&controller=invoices&mid='.$targetMemberId);
		$table = Invoice::table(['i_member = ?', $targetMemberId], $url);

		$table->tableTemplate = [$theme->getTemplate('customers', 'nexus'), 'invoicesTable'];
		$table->rowsTemplate = [$theme->getTemplate('customers', 'nexus'), 'invoicesTableRows'];

		$oldButtonsCb = $table->rowButtons;
		$table->rowButtons = function($row) use($oldButtonsCb, $ticketId) {
			$buttons = $oldButtonsCb($row);
			// rewrite button links to ourself
			foreach($buttons as $action => &$button) {
				if(!isset($button['link'])) continue;
				$buttonLink = &$button['link'];
				/** @var Url $buttonLink */

				 // only rewrite links that we understand
				if(($buttonLink->queryString['app'] ?? '') == 'nexus' &&
					($buttonLink->queryString['module'] ?? '') == 'payments' &&
					($buttonLink->queryString['controller'] ?? '') == 'invoices'
				) {
					switch($action) {
						case '':
							/* don't try to capture, doesn't work */ break;
						case 'print': // Doesn't really work as a modal, open in new tab instead.
							break;

						case 'edit': case 'card': case 'credit': // The implementation of the multi step wizard in combination with the output singleton makes it impossible to rewrite it externally. It relies on the baseUrl for hashed keys, and we cannot get the Wizard object before its cast to a string when assigned to the output. New tab it is.
							break;
						

						case 'view': {
							$button['data']['ipsDialog'] = '';
							$button['data']['ipsDialog-title'] = Member::loggedIn()->language()->addToStack('invoice');
						} /* fallthrough */

						default:
							$buttonLink->queryString['app']        = 'vssupport';
							$buttonLink->queryString['module']     = 'tickets';
							//$buttonLink->queryString['controller'] = 'invoices';
							$buttonLink->queryString['__tid']      = $ticketId;
							$buttonLink->queryString['__do']       = $buttonLink->queryString['do'] ?? '';
							unset($buttonLink->queryString['do']);

							$buttonLink->data[Url::COMPONENT_QUERY] = Url::convertQueryAsArrayToString($buttonLink->queryString);
							UrlReflection::reconstructUrlFromData($buttonLink);
							continue 2;
					}
				}

				$button['target'] = '_blank'; // Always open in new tab if we don't understand the link.
			}
			unset($button);

			return $buttons;
		};

		$title = Member::loggedIn()->language()->addToStack('users_invoices', options: ['sprintf' => [$targetMemberName]]);
		return "<div class='ipsBox'><h2 class='ipsBox__header'>$title</h2>$table</div>";
	}

	public function manage() : void // :InvoicesRelay
	{
		$request = Request::i();
		$output  = Output::i();

		switch($request->__do) {
			case 'view': case 'paid': case 'resend': case 'track': case 'unpaid': case 'delete': {
				// Overwrite this value because nexus doesn't always use the full path to load templates and tries loading a template from us if we don't swap this. :ApplicationDirectoryHack
				Dispatcher::i()->application->directory = 'nexus';

				$controller = new InvoicesOverride();
				$controller->ticketId = intval($request->__tid);

				// After we received the request, we change the 'do' parameter so the base execute method dispatches to the correct method.
				$request->do = $request->__do;
				$controller->execute();
			} break;

			// Remaining functions we don't handle. See formatBlock as to why.
			// case 'printout': case 'generate': case 'card': case 'credit':

			default:
				$output->error('node_error', '2X195/2', 404);
		}
	}
}

/**
 * Class that can be installed to manually force a redirect to go to a different address than the call tells it to.
 */
class RedirectOverrideOutput extends Output {
	public static ?Output $underlying;
	public static ?Url $forcedRedirectTarget;

	static function install(Url $forcedRedirectTarget) : void
	{
		static::$underlying = Output::i();
		static::$forcedRedirectTarget = $forcedRedirectTarget;
		Output::$instance = new self();
	}

	public function redirect(Url|string $url, ?string $message = '', int $httpStatusCode = 301, bool $forceScreen = FALSE) : void
	{
		parent::redirect(static::$forcedRedirectTarget);
	}

	public function __call($name, $arguments)
	{
		static::$underlying->$name(...$arguments);
	}
}

class InvoicesOverride extends \IPS\nexus\modules\admin\payments\invoices { // :InvoicesRelay
	public int $ticketId;

	function delete() : void // For some reason this doesn't go though _redirect in the base ...
	{
		RedirectOverrideOutput::install(Url::internal('app=vssupport&module=tickets&controller=tickets&do=view&id='.$this->ticketId));
		parent::delete();
	}

	function _redirect(Invoice $invoice) : void
	{
		// Normally the controller redirects to members for almost everything. We don't want that.
		Output::i()->redirect(Url::internal('app=vssupport&module=tickets&controller=tickets&do=view&id='.$this->ticketId));
	}
}

