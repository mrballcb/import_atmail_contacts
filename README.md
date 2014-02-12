##Import Atmail Contacts into Roundcube
======================

###OVERVIEW

This plugin will import contacts and groups from Atmail to Roundcube's
addressbook on login.  When enabled, it checks on every login to see if
it needs to import contacts from Atmail's database.  It will do nothing
if the user logging in already has more than one contact in Roundcube.
Rephrased: after a successful import has previously run, the plugin will
immediately exit on subsequent logins because it detects contacts.

###USAGE

- Make sure you have installed the necessary PDO modules for your database

- Make a Roundcube plugin directory: plugins/import_atmail_contacts/

- Place the import_atmail_contacts.php script into this subdirectory

- Enable the plugin in config/main.inc.php

- Add your Atmail database credentials to config/db.inc.php
  Optional debug settings can be added to config/main.inc.php
  (see import_atmail_contacts.php for example)

- Log in and see your imported contacts and groups
