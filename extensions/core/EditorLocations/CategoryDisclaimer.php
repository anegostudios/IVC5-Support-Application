<?php namespace IPS\vssupport\extensions\core\EditorLocations;

use IPS\Content as ContentClass;
use IPS\Extensions\EditorLocationsAbstract;
use IPS\Helpers\Form\Editor;
use IPS\Http\Url;
use IPS\Member as MemberClass;
use IPS\Node\Model;
use LogicException;
use function defined;

if(!defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0').' 403 Forbidden');
	exit;
}

class CategoryDisclaimer extends EditorLocationsAbstract
{
	/**
	 * Can we use attachments in this editor?
	 *
	 * @param	MemberClass					$member	The member
	 * @param	Editor	$field	The editor field
	 * @return	bool|null	NULL will cause the default value (based on the member's permissions) to be used, and is recommended in most cases. A boolean value will override that.
	 */
	public function canAttach( MemberClass $member, Editor $field ): ?bool
	{
		return false;
	}
	
	/**
	 * Permission check for attachments
	 *
	 * @param	MemberClass	    $member		The member
	 * @param	int|null	$id1		Primary ID
	 * @param	int|null	$id2		Secondary ID
	 * @param	string|null	$id3		Arbitrary data
	 * @param	array		$attachment	The attachment data
	 * @param	bool		$viewOnly	If true, just check if the user can see the attachment rather than download it
	 * @return	bool
	 */
	public function attachmentPermissionCheck( MemberClass $member, ?int $id1, ?int $id2, ?string $id3, array $attachment, bool $viewOnly=FALSE ): bool
	{
		return false;
	}
	
	/**
	 * Attachment lookup
	 *
	 * @param	int|null	$id1	Primary ID
	 * @param	int|null	$id2	Secondary ID
	 * @param	string|null	$id3	Arbitrary data
	 * @return	Url|ContentClass|Model|MemberClass|null
	 * @throws	LogicException
	 */
	public function attachmentLookup( ?int $id1=NULL, ?int $id2=NULL, ?string $id3=NULL ): Model|ContentClass|Url|MemberClass|null
	{
		return null;
	}
}