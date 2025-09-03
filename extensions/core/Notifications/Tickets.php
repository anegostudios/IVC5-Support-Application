<?php namespace IPS\vssupport\extensions\core\Notifications;

use IPS\Extensions\NotificationsAbstract;
use IPS\Http\Url;
use IPS\Member;
use IPS\Notification\Inline;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class Tickets extends NotificationsAbstract
{
	/**
	 * Get fields for configuration
	 *
	 * @param	Member|null	$member		The member (to take out any notification types a given member will never see) or NULL if this is for the ACP
	 * @return	array
	 */
	public static function configurationOptions(?Member $member = NULL): array
	{
		return [
			'ticket_response' => [
				'type'               => 'standard',
				'notificationTypes'  => ['ticket_response'],
				'showTitle'          => false,
				'title'              => 'notifications__vssupport_Tickets_ticket_response',
				'description'        => 'notifications__vssupport_Tickets_ticket_response_desc',
				'adminCanSetDefault' => true,
				'default'            => ['email', 'inline', 'push'],
				'disabled'           => [],
			],
		];
	}
	
	/**
	 * Parse notification: ticket_response
	 *
	 * @param	Inline	$notification	The notification
	 * @param	bool	$htmlEscape		TRUE to escape HTML in title
	 * @return	array
	 * @code
	 return array(
		 'title'		=> "Mark has replied to A Topic",	// The notification title
		 'url'			=> Url::internal(...),	        // The URL the notification should link to
		 'content'		=> "Lorem ipsum dolar sit",			// [Optional] Any appropriate content. Do not format this like an email where the text
		 													// 	 explains what the notification is about - just include any appropriate content.
		 													// 	 For example, if the notification is about a post, set this as the body of the post.
		 'author'		=>  Member::load(1),	    		// [Optional] The user whose photo should be displayed for this notification
	);
	 * @endcode
	 */
	public function parse_ticket_response(Inline $notification, bool $htmlEscape = true) : array
	{
		$message = $notification->item;
		/** @var IPS\vssupport\Notification $message */
		return [
			'title'   => Member::loggedIn()->language()->addToStack('notification_ticket_response', options: ['sprintf' => $message->ticket]),
			'url'     => Url::internal('app=vssupport&module=tickets&controller=tickets&do=view&hash='.$message->ticketHash, seoTemplate: 'tickets_view_hash'),
			'content' => $message->text,
			'author'  => Member::load($message->initiator),
		];
	}
}