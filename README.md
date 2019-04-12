# CardDAV contacts import for AVM FRITZ!Box
[![Donate](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BB3W3WH7GVSNW)

This is an entirely simplified version of https://github.com/jens-maus/carddav2fb. The Vcard parser has been replaced by an extended version of https://github.com/jeroendesloovere/vcard.

## Features

  * download from any number of CardDAV servers
  * selection (include/exclude) by categories or groups (e.g. iCloud)
  * upload of contact pictures to display them on the FRITZ!Fon (handling see below)
  * transfer of quick dial and vanity numbers (see wiki for handling details)
  * if more than nine phone numbers are included, the contact will be divided into a corresponding number of phonebook entries (any existing email addresses are assigned to the first set [there is no quantity limit!])
  * phone numbers are sorted by type. The order of the conversion values ('phoneTypes') determines the order in the phone book entry
  * the contact's UID of the CardDAV server is added to the phonebook entry (not visible in the FRITZ! Box GUI)
  * automatically preserves QuickDial and Vanity attributes of phone numbers
    set in FRITZ!Box Web GUI. Works without config. (Hint: If you used the
    old way of configuring your CardDav server with X-FB-QUICKDIAL /X-FB-VANITY, then your old config is respected and this new automatic feature is skipped).
  * generates an image with keypad and designated quickdial numbers, which can be uploaded to designated handhelds (see details below)

## Requirements

  * PHP >7.0 (`apt-get install php php-curl php-mbstring php-xml`)
  * Composer (follow the installation guide at https://getcomposer.org/download/)

## Installation

Install requirements

    git clone https://github.com/andig/carddav2fb.git
    cd carddav2fb
    composer install --no-dev

edit `config.example.php` and save as `config.php`

## Usage

List all commands:

    php carddav2fb.php list

Complete processing:

    php carddav2fb.php run

Get help for a command:

    php carddav2fb.php run -h

Only upload your quickdial numbers as a background image to FRITZ!Fon (nothing else!)

    php carddav2fb.php background

## Precondition for using image upload (command -i)

  * works only with FRITZ!Fon C4 or C5 handhelds
  * your memory (USB stick) is indexed [Heimnetz -> Speicher (NAS) -> Speicher an der FRITZ!Box]
  * ftp access is activ [Heimnetz -> Speicher (NAS) -> Heimnetzfreigabe]
  * you use an standalone user (NOT! dslf-config) which has explicit permissions for FRITZ!Box settings, access to NAS content and read/write permission to all available memory [System -> FRITZ!Box-Benutzer -> [user] -> Berechtigungen]

## Precondition for using the background image upload

  * works only with FRITZ!Fon C4 or C5 handhelds
  * settings in FRITZ!Fon: Einstellungen -> Anzeige -> Startbildschirme -> Klassisch -> Optionen -> Hintergrundbild
  * assignment is made via the internal number(s) of the handheld(s) in the 'fritzfons'-array in config.php 
  * internal number have to be between '610' and '615', no '**'-prefix
  
## Debugging

For debugging please set your config.php to

    'http' => 'debug' => true

## License
This script is released under Public Domain, some parts under GNU AGPL or MIT license. Make sure you understand which parts are which.

## Authors
Copyright (c) 2012-2019 Andreas Götz, Volker Püschel, Karl Glatz, Christian Putzke, Martin Rost, Jens Maus, Johannes Freiburger