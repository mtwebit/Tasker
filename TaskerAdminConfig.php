<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * TaskerAdmin module - configuration
 * 
 * Provides management interfaces (HTML and REST API) for Tasker.
 * 
 * Copyright 2018 Tamas Meszaros <mt+github@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

class TaskerAdminConfig extends ModuleConfig {

  public function getDefaults() {
    return array(
      'autoStart' => 0,
      'enableWebRun' => 1,
      );
  }

  public function getInputfields() {
    $inputfields = parent::getInputfields();

/********************  Module info  *********************************/
    $fieldset = $this->wire('modules')->get('InputfieldFieldset');
    $fieldset->label = __('About the module and usage tips');
    $fieldset->collapsed = InputfieldFieldset::collapsedYes;

    $f = $this->modules->get('InputfieldMarkup');
    $f->label = __('About the module');
    $f->columnWidth = 50;
    $f->value = __('<p>
This module provides an admin interface and a backend API for Tasker.
For more information check the module\'s home at https://github.com/mtwebit/Tasker/
</p>');
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldMarkup');
    $f->label = __('Usage tips');
    $f->columnWidth = 50;
    $f->value = '<p>
- if autoStart is enabled, page saves may redirect to TaskerAdmin if a task has been started.<br />
</p>';
    $fieldset->add($f);

    $inputfields->add($fieldset);

/********************  Scheduler settings ******************************/
    $fieldset = $this->wire('modules')->get('InputfieldFieldset');
    $fieldset->label = __('Module settings');

    $f = $this->modules->get('InputfieldCheckbox');
    $f->attr('name', 'autoStart');
    $f->label = __('Automatically start an active task');
    $f->description = __('If a task becomes active start it immediately, if possible. Other modules may use this setting to redirect to TaskerAdmin\'s task monitoring page.');
    $f->columnWidth = 50;
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldCheckbox');
    $f->attr('name', 'enableWebRun');
    $f->label = __('Allow executing tasks using TaskerAdmin\'s Web interface');
    $f->description = __('TaskerAdmin provides a Web interface to manage tasks. Allow tasks to be executed using this interface.');
    $f->columnWidth = 50;
    $fieldset->add($f);

    $inputfields->add($fieldset);

    return $inputfields;
  }
}
