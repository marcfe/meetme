# meetme

A minimal, database-less PHP web application for booking personal meeting slots (like dinner or coffee), powered entirely by an iCloud calendar ICS feed.

## Why meetme?

Most booking systems are heavy, require a database, and often force you into a specific platform. **meetme** is different:
- **No Database**: Uses flat files (JSON) for all state.
- **Single Source of Truth**: Your existing iCloud calendar is the master schedule.
- **Lightweight**: Pure PHP with minimal dependencies.
- **Privacy First**: You control your data and hosting.

## Features

- **iCloud Integration**: Automatically fetches slots from a published iCloud calendar.
- **Atomic Bookings**: Prevents double-bookings using file system locks and `O_CREAT|O_EXCL` operations.
- **Multi-language**: Supports Bärndütsch (Swiss German dialect), German, and English out of the box.
- **Smart Routing**: Clean, token-based invitation links (`/?k=your-token`).
- **Anti-Abuse**: Built-in honeypot protection and per-IP rate limiting.
- **Admin Dashboard**: Manage invitations, block slots manually, and track bookings.
- **Zero Cron Jobs**: Everything happens on-request.

## Installation

### 1. Requirements
- PHP 8.1 or higher
- Apache with `mod_rewrite` enabled (standard on most shared hosting)
- Composer (for dependencies)

### 2. Setup
1. Clone this repository to your web server.
2. Run `composer install` to pull in dependencies (`sabre/vobject` and `PHPMailer`).
3. Ensure the following directories are writable by the web server:
   - `bookings/`
   - `cache/`
   - `invites/`
4. Rename `config.sample.php` to `config.php` and fill in your details:
   - Your iCloud ICS URL (must be `https://`, not `webcal://`)
   - SMTP server settings for outgoing mail.
   - Your name and email.

### 3. Secure the Admin Panel
1. Generate a `.htpasswd` file for your admin credentials:
   ```bash
   htpasswd -c .htpasswd yourusername
   ```
2. Open `.htaccess`, find the "Protect admin.php" section, and update the `AuthUserFile` path to the absolute path of your `.htpasswd` file on the server.

## How to use

### Add Bookable Slots
Just create events in your iCloud calendar. The app will automatically see them as free slots.
- **Tags**: To make a slot private, add `#tagname` to the event title (e.g., "Dinner #anna"). In the admin panel, create an invite with the tag `anna` to reveal these slots.
- **Labels**: Use labels like "Lunch" or "Dinner" in your event titles. They will be automatically translated into the guest's preferred language.

### Managing Invitations
Access `admin.php` to generate unique invite links. You can choose between:
- **Single Use**: Link expires after one booking.
- **Reusable**: Link can be used multiple times with a configurable cooldown per guest email.

## License
MIT License. Feel free to use and modify for your own personal or commercial projects.
