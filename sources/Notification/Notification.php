<?php namespace IPS\vssupport;

use IPS\IPS;
use IPS\Lang;
use IPS\Member;

if(!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class Notification extends \IPS\Notification
{
	static function sendNewResponseNotification(int $ticketId, string $ticketHash, Member $ticketIssuer, Message $message, string $responderName) : void
	{
		$emailParams = [$ticketId, $ticketHash /* :TrackingHashIndex */, $message->text, $responderName];
		$notification = new static(Application::load('vssupport'), 'ticket_response', $message, $emailParams, allowMerging: false);
		$notification->recipients->attach($ticketIssuer);
		$notification->send();
	}

	// This is overwritten to add the tracking headers we need to email notifications.
	protected function sendEmails( array $emails, array $emailRecipients ) : void
	{
		foreach ( $emails as $languageId => $email )
		{
			if ( !empty( $emailRecipients[ $languageId ] ) )
			{
				$email->mergeAndSend( $emailRecipients[ $languageId ], NULL, NULL, array(
					'List-Unsubscribe' 		=> '<*|list_unsubscribe_link|*>',
					'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
					'X-Auto-Response-Suppress' => 'OOF, AutoReply', // suppress "currently out of office" notifications
					'Message-ID' => Email::generateMessageId($this->emailParams[1 /* :TrackingHashIndex */]), // :EmailResponseTracking
				), Lang::load( $languageId ) );
			}
		}
	}
}
