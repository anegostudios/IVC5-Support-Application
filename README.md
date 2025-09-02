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
		- Action tracking (history of attribute changes)
	- Frontend module
		- Create new tickets (also possible as guest)
		- Inspect individual tickets
			- Add more messages / Reply to the ticket if it is not locked
		- List of tickets created by current member
		- Tickets are not enumerable
		- Requires captcha if enabled
	- Admin module
		- View all tickets
		- View individual ticket
			- Reply or add Internal Note and
				- Change Status
				- Change Category
				- Change Priority
				- Lock to staff members
				- Assign to specific staff member
			- Manage Customer Purchases without leaving the ticket
				- Inspect or Cancel / Reinstate
			- Manage Customer Invoices largely without leaving the ticket
				- Inspect, Track, Cancel, Refund, Resend or Delete
				- opens in new tab: Edit or Print
		- Create / Edit / Delete Ticket Categories
			- Preview translated names
		- Create / Edit / Delete new Ticket Stati
			- Some stati are builtin and cannot be removed
			- Preview translated names

### Screenshots

![img](.doc/ticket_create.jpg)  
Creating a ticket in the frontend.

![img](.doc/ticket_admin_list.jpg)  
Listing tickets in the backend (some features are WIP).

![img](.doc/ticket_admin_view.jpg)  
Viewing and responding to a ticket in the backend.

![img](.doc/ticket_view.jpg)  
Viewing and responding to a ticket from the frontend.

![img](.doc/ticket_admin_view_purchases.jpg)  
Managing purchase information of affected customer.

![img](.doc/ticket_admin_view_purchases_overlay.jpg)  
Without leaving the ticket for most actions.

## TODO

- Email egress
	- Daily digest for moderators
	- Response notifications for users
		- Notification settings
- Email ingest
- GitHub linking
- GitLab linking
- More filter options
	-	by assignment
	- by user
	- by email
- Admin ticket view
	- Quick actions
- Admin bulk actions
- Migration from ivc4 builtin tickets
- Option to delete open tickets when issuer gets deleted.
- Link from user profile to their tickets.
- Better user change tracking
	- User name change tracking is flaky

## License

The code and other files in this repository are provided under the MIT license unless specified otherwise.
