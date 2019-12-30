<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * Admin module for Tasker -  information and settings
 * 
 * Provides management and API interface for Tasker.
 * 
 * Copyright 2017 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

$info = array(
  'title' => 'Task Administration',
  'version' => '0.9.5',
  'summary' => 'The module provides Web UI for task administration.',
  'href' => 'https://github.com/mtwebit/Tasker/',
  'singular' => true,
  'autoload' => false,
  'icon' => 'tasks',
  'page' => array( // an admin page for this module
    'name' => 'tasks',
    'parent' => 'page',
    'title' => 'Tasks',
    'template' => 'admin'
  ),
);
