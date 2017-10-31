<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * Admin module for Tasker -  information and settings
 * 
 * Provides management and API interface for Tasker.
 * 
 * Copyright 2017 Tamas Meszaros <mt+github@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

$info = array(
  'title' => 'Task Administration',
  'version' => '0.1.1',
  'summary' => 'The module provides admin interface for tasker.',
  'href' => 'https://github.com/mtwebit/Tasker/',
  'singular' => true, // contains hooks
  'autoload' => false,
  'icon' => 'tasks', // fontawesome icon
  'page' => array( // we create an admin page for this module
    'name' => 'tasks',
    'parent' => '/admin/page/',
    'title' => 'Task management',
    'template' => 'admin'
  ),
);
