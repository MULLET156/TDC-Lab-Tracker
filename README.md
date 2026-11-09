# TDC Lab Tracker

Lab case tracking for Toledo Dental Care: in-house and outside-lab cases, step checklists, due dates, labs and materials. Staff sign in with one office password; cases, labs and settings are stored in a MySQL database on Hostinger.

No patient data, passwords or database logins belong in this repository. They live only in the Hostinger database and in `config.php` on the server.

## Files

| File | What it is |
|---|---|
| `index.html` | The tracker itself (the page staff open). |
| `api.php` | Saves and loads data, and handles the office password. Creates its database tables on first use. |
| `config.sample.php` | Template for `config.php`, which holds the office password and database login. `config.php` is never committed. |
| `.htaccess` | Forces HTTPS and keeps `config.php` and the `.git` folder private. |

Requirements: Hostinger web hosting with PHP 8.1 or newer and MySQL.

## Set up on Hostinger

1. **Site and SSL.** Pick the website or subdomain in hPanel and make sure SSL is active. The tracker always redirects to `https://`.
2. **Database.** Under **Databases → Management**, create a MySQL database and note the full name, username and password.
3. **Connect this repository.** Under **Websites → Manage → Advanced → GIT**, use `https://github.com/MULLET156/TDC-Lab-Tracker.git`, branch `main`, then **Deploy**. Turn on **Auto Deployment** so every push to `main` updates the site.
4. **Create `config.php`.** In **Files → File Manager**, copy `config.sample.php` to `config.php` in the site folder and fill in the office password and database details. Use `localhost` for `DB_HOST`. Pick an office password of at least 12 characters; changing it later signs every device out.
5. **Sign in and bring over your data.** On the claude.ai version, go to **Setup → Backup, import & export → Full backup (.json)**. On the hosted site, sign in, go to the same section, choose that file under **Import** and confirm the preview.

## Updating

Edit the files and push to `main`. `config.php` and the database are never touched by a deploy.

## Backups

Use **Setup → Backup, import & export → Full backup** regularly and keep the file somewhere safe. It contains patient details and lab website passwords, so store it securely. Hostinger's own backups also cover the database.
