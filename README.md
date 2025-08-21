# Anego Studios's Support Application

This repository holds the source code for our support ticket system. 

While the source is publicly available, PRs will generally be ignored. For now this repository is only source available.

If you have concerns, security related or otherwise, feel free to [contact us](https://www.vintagestory.at/support/).


## Features

- Tickets
	- General features
		- Attachments
		- Editor autosaves
		- Priorities (-2 to 2, currently not extendable) (translatable)
		- Arbitrary Categories (translatable)
		- Timestamps on tickets and messages
		- Friendly urls (mostly, tickets are still referenced by id)
	- Frontend module
		- Create new tickets (also possible as guest)
		- Inspect individual tickets
			- Add more messages / Reply to the ticket
		- List of tickets created by current member
	- Admin module
		- View all tickets
		- View individual ticket
			- Reply or add Internal Note and
				- Change Category
				- Change Priority
		- Create / Edit / Delete Categories 
			- Preview translated names

## TODO

- Email egress
	- Daily digest
- Email ingest
- GitHub linking
- GitLab linking
- Sales linking
- Ticket Locking
- Proper ticket assignment
- More filter options
	-	by assignment
	- by user
	- by email
- Admin ticket view
	- Quick actions
- Admin bulk actions
- Hashes instead of ids for tickets
- Captcha on frontend
- Action tracking
