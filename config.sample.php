<?php
/*
 * TDC Lab Tracker – settings
 *
 * Copy this file to config.php (in the same folder) and fill it in.
 * config.php is never committed to GitHub, so your passwords stay on the server.
 */

// The one password the whole office uses to sign in.
// Changing it later signs every phone and computer out.
define('OFFICE_PASSWORD', 'change-me');

// MySQL database from Hostinger hPanel → Databases → Management.
define('DB_HOST', 'localhost');
define('DB_NAME', 'u000000000_labs');
define('DB_USER', 'u000000000_labs');
define('DB_PASS', 'database-password-here');

// Open Dental sync (optional). The same long random value goes in the office
// gateway's .env as LAB_SYNC_SECRET (create one there with: node gateway/cli.js new-secret).
// Leave this line out to keep the sync off.
// define('OD_SYNC_SECRET', 'paste-the-64-character-secret-here');

// Dental Dashboard tiles (optional). The same long random value goes in the
// Dashboard's settings for the Lab Tracker. Make one with:
//   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
// Leave this line out to keep the tiles off (the address then answers "not found").
// define('TILE_TOKEN', 'paste-the-64-character-token-here');
