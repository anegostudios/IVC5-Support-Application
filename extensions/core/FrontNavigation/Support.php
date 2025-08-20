<?php
/**
 * @brief		Front Navigation Extension: Support
 * @author		Rennorb
 * @copyright	(c) 2025 Anego Studios
 * @package		Invision Community
 * @subpackage	VS Support
 * @since		20 Aug 2025
 */

namespace IPS\vssupport\extensions\core\FrontNavigation;

use IPS\core\FrontNavigation\FrontNavigationAbstract;
use IPS\Dispatcher;
use IPS\Http\Url;
use IPS\Member as MemberClass;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class Support extends FrontNavigationAbstract
{
	public string $defaultIcon = '\f15c';

	/** Get Type Title which will display in the AdminCP Menu Manager */
	public static function typeTitle(): string
	{
		return MemberClass::loggedIn()->language()->addToStack('frontnavigation_vssupport');
	}

	public function title(): string
	{
		return MemberClass::loggedIn()->language()->addToStack('frontnavigation_vssupport');
	}

	public function link(): Url
	{
		return Url::internal('app=vssupport&module=tickets&controller=tickets', seoTemplate: 'tickets_list');
	}

	public function active(): bool
	{
		return Dispatcher::i()->application->directory === 'vssupport';
	}
}