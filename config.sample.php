<?php
/**
 * meetme Configuration Template
 * Rename this file to config.php and fill in your details.
 */

// Published iCloud calendar URL (must be https://)
define('ICS_URL', 'https://p177-caldav.icloud.com/published/2/YOUR_TOKEN_HERE');

// Owner settings
define('OWNER_EMAIL', 'your@email.com');
define('OWNER_NAME', 'Your Name');
define('FROM_EMAIL', 'your@email.com');
define('REPLY_TO', '');

// SMTP Settings
define('SMTP_HOST', 'asmtp.mail.hostpoint.ch');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your@email.com');
define('SMTP_PASS', 'YOUR_PASSWORD_HERE');
define('SMTP_SECURE', 'tls'); // 'tls' or 'ssl'

// Application Settings
define('CACHE_TTL', 600); // 10 minutes
define('DEFAULT_HORIZON_DAYS', 30);
define('DEFAULT_COOLDOWN_DAYS', 42); // 6 weeks
define('RATE_LIMIT_PER_HOUR', 5);
define('TIMEZONE', 'Europe/Zurich');
define('PUBLIC_REQUESTS_ENABLED', true);

// Location keyword mapping for pretty display
// If LOCATION contains the key, display "Preferred around <Value>"
$LOCATION_MAP = [
    'Kalkbreite' => 'Kalkbreite',
    'Hardbrücke' => 'Hardbrücke',
];
