# BizSkill Training

Training tracker for small businesses, live at https://train.bizskilledu.com

An owner adds employees and assigns courses. Each lesson is a short video followed by a test, and the owner sees every employee's watch time and score.

## Files

- `index.html`: the whole app screen (owner, employee and admin views)
- `api.php`: the server side (logins, courses, watch tracking, tests, plans)
- `config.example.php`: settings template. On the server it is copied to `config.php` and filled in. `config.php` is never stored here.
- `data/`: customer records live here on the server. Only the `.htaccess` that blocks web access is stored here.
- `.htaccess`: blocks web access to the settings file

## Setting up a fresh copy

1. Upload the files to a PHP 8 web folder.
2. Copy `config.example.php` to `config.php` and set a long random `setup_key`.
3. Open `/#/setup?k=<setup_key>` once to create the admin login.

This repo is a backup and history of the live site. Changes are uploaded to the site separately; nothing deploys from here automatically.
