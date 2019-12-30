<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * Tasker - module information and settings
 * 
 * Allows modules to execute long-running tasks (i.e. longer than max_exec_time).
 * It supports Cron, Javascript and a LazyCron execution of tasks.
 * 
 * Copyright 2017 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

$info = array(
  'title' => 'Tasker module',
  'version' => '0.9.5',
  'summary' => 'The module provides support for executing long-running tasks.',
  'href' => 'https://github.com/mtwebit/Tasker/',
  'singular' => true,
  'autoload' => true,
  'icon' => 'tasks',
);
