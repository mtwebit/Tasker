#!/opt/bitnami/php/bin/php
<?php namespace ProcessWire;

/*
 * Invoking Tasker from Cron
 * 
 * Copyright 2017 Tamas Meszaros <mt+github@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

require "../../../index.php";

if (!wire('modules')->isInstalled('Tasker')) {
  echo 'Tasker module is missing.';
  exit;
}

$tasker = wire('modules')->get('Tasker');

$tasker->executeByCron();
