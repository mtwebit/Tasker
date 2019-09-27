#!/usr/bin/php
<?php namespace ProcessWire;

/*
 * Invoking Tasker from Cron
 * 
 * Example: docker exec --user nginx iti bash -c "cd /var/www/html/iti/site/modules/Tasker && /usr/bin/php runByCron.php"
 * 
 * Copyright 2017 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

require "../../../index.php";

if (!wire('modules')->isInstalled('Tasker')) {
//  echo 'Tasker module is missing.';
  exit;
}

$tasker = wire('modules')->get('Tasker');

$tasker->executeByCron();
