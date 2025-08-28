<?php namespace IPS\vssupport\modules\admin\tickets;

use IPS\Db;
use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\Http\Url;
use IPS\Member;
use IPS\Output;
use IPS\Patterns\ActiveRecordIterator;
use IPS\Request;
use IPS\Theme;

use function defined;
use function IPS\vssupport\query_one;

if(!defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

//NOTE(Rennorb): A lot of the functions we need do exist, but are unfortunately not quite customizable enough for what we want to do.
// Often it's simply a missing redirect parameter, or some other ux issue. We therefore route all the actions through us so we can manipulate the result before returning it.
// We do not directly extend the original controller on purpose, just so we wont get any wired permissions issues. We only expose a very small surface.
// :PurchaseRelay

class purchases extends Controller
{
	static function formatBlock(int $ticketId, int $targetMemberId) : string
	{
		$targetMember = Member::load($targetMemberId);

		$baseUrl = Url::internal("app=vssupport&module=tickets&controller=purchases&__tid={$ticketId}");
		
		$getTabUrl = $baseUrl->setQueryString(['do' => 'getTab__', '__mid' => $targetMemberId]);
		$counts = query_one(Db::i()->select(<<<SQL
			COUNT(IF(ps_show AND ps_active                             , 1, null)) as `active`,
			COUNT(IF(!ps_active AND !ps_cancelled AND ps_expire < NOW(), 1, null)) as `expired`,
			COUNT(IF(ps_cancelled                                      , 1, null)) as `canceled`
		SQL, 'nexus_purchases', ['ps_parent = 0 AND ps_member = ?', $targetMemberId]));
		$tabs = [
			'active'   => ['icon' => 'credit-card', 'count' => $counts['active']  , 'url' => $getTabUrl->setQueryString('tab', 'active')],
			'expired'  => ['icon' => 'credit-card', 'count' => $counts['expired'] , 'url' => $getTabUrl->setQueryString('tab', 'expired')],
			'canceled' => ['icon' => 'credit-card', 'count' => $counts['canceled'], 'url' => $getTabUrl->setQueryString('tab', 'canceled')],
		];

		$purchases = static::_getPurchasesTab($targetMemberId, 'active', $baseUrl);

		return (string) Theme::i()->getTemplate('tickets')->purchasesWidget($targetMember->name, $tabs, 'active', $purchases);
	}

	public function getTab__() : void
	{
		\IPS\Session::i()->csrfCheck();
		Dispatcher::i()->checkAcpPermission('purchases_view', 'nexus', 'customers');
		$request = Request::i();
		$output = Output::i();

		$targetMemberId = intval($request->__mid);

		switch($request->tab) {
			case 'active': case 'expired': case 'canceled': /* ok */ break;
			default:
				$output->error('invalid_tab', '', 400);
				return;
		}

		$ticketId = intval($request->__tid);
		$baseUrl = Url::internal("app=vssupport&module=tickets&controller=purchases&__tid={$ticketId}");
		$output->sendOutput(static::_getPurchasesTab($targetMemberId, $request->tab, $baseUrl));
		exit();
	}

	static function _getPurchasesTab(int $targetMemberId, string $activeTab, Url $url) : string
	{
		$member = Member::loggedIn();
		$language = $member->language();
		$theme = Theme::i();

		$where = [['ps_parent = 0 AND ps_member = ?', $targetMemberId]];
		switch($activeTab) {
			case 'active': $where[] = ['ps_active']; break;
			case 'expired': $where[] = ['!ps_active AND !ps_cancelled AND ps_expire < NOW()']; break;
			case 'canceled': $where[] = ['ps_cancelled']; break;
		}

		$html = '';

		$rowTemplate = $theme->getTemplate('tickets');

		foreach(new ActiveRecordIterator(Db::i()->select('*', 'nexus_purchases', $where, 'ps_Start DESC'), 'IPS\nexus\Purchase') as $purchase) {
			/** @var \IPS\nexus\Purchase $purchase */

			$description = $language->addToStack('purchase_number', FALSE, ['sprintf' => [$purchase->id]]);

			$buttons = [
				'view' => [
					'title' => 'view',
					'icon' => 'search',
					'link' => $purchase->acpUrl()->setQueryString('popup', true),
					//'data' => ['ipsDialog' => ''], // Cant do dialog it seems, doesn't show results.
					'target' => '_blank', // The least we can do is open in a new tab.
				],
			];

			if((!$purchase->billing_agreement || $purchase->billing_agreement->canceled) && $member->hasAcpRestriction('nexus', 'customers', 'purchases_cancel')) {
				$relayUrl = $url->setQueryString('id', $purchase->id);

				$extension = null;
				try {
					$meth = new \ReflectionMethod($purchase, 'extension');
					$meth->setAccessible(true);
					$extension = $meth->invoke($purchase);
				} catch(\OutOfRangeException) { }


				if($purchase->cancelled)
				{
					if($extension && $extension::canAcpReactivate($purchase))
					{
						$buttons['reactivate'] = array(
							'icon'	=> 'check',
							'title'	=> 'reactivate',
							'link'	=> $relayUrl->setQueryString('__do', 'reactivate')->csrf(),
							'data'	=> ['confirm' => '']
						);
					}
				}
				else
				{
					$buttons['cancel'] = array(
						'icon'	=> 'times',
						'title'	=> 'cancel',
						'link'	=> $relayUrl->setQueryString('__do', 'cancel'),
						'data'	=> ['ipsDialog' => '', 'ipsDialog-title' => $language->addToStack('cancel')]
					);
				}
			}

			$html .= $rowTemplate->purchasesWidgetRow($purchase, $description, $buttons);
		}

		return $html ?: '<div class="i-padding_2 i-text-align_center">Empty</div>';
	}

	public function manage() : void // :PurchaseRelay
	{
		$request = Request::i();
		$output  = Output::i();
		switch($request->__do) {
			case 'cancel': case 'reactivate': {
				// It's not really required for what we do here, but just in case we are symmetrical about it here in purchases. :ApplicationDirectoryHack
				Dispatcher::i()->application->directory = 'nexus';

				$controller = new PurchasesOverride();
				$controller->ticketId = intval($request->__tid);
				
				// After we received the request, we change the 'do' parameter so the base execute method dispatches to the correct method.
				$request->do = $request->__do;
				$controller->execute();
			} break;

			default:
				$output->error('node_error', '2X195/2', 404);
		}
	}
}

class PurchasesOverride extends \IPS\nexus\modules\admin\customers\purchases { // :PurchaseRelay
	public int $ticketId;

	function _redirect() : void
	{
		// Normally the controller redirects to members for almost everything. We don't want that.
		Output::i()->redirect(Url::internal('app=vssupport&module=tickets&controller=tickets&do=view&id='.$this->ticketId));
	}
}
