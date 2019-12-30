<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * Tasker module - configuration
 * 
 * Allows modules to execute long-running tasks (i.e. longer than max_exec_time).
 * It supports Cron, Javascript and a LazyCron scheduling of tasks.
 * 
 * Copyright 2017 Tamas Meszaros <mt+github@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

class TaskerConfig extends ModuleConfig {

  public function getDefaults() {
    return array(
      'enableCron' => 0,
      'enableLazyCron' => 0,
      'taskTemplate' => 'tasker-task',
      'ajaxTimeout' => 15,
      'lazyCronTimeout' => 15,
      'debug' => 1,
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
This module provides support for execution of long-running tasks.
For more information check the module\'s home at https://github.com/mtwebit/Tasker/
</p>');
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldMarkup');
    $f->label = __('Usage tips');
    $f->columnWidth = 50;
    $f->value = '<p>
- <br />
</p>';
    $fieldset->add($f);

    $inputfields->add($fieldset);

/********************  Scheduler settings ******************************/
    $fieldset = $this->wire('modules')->get('InputfieldFieldset');
    $fieldset->label = __('Scheduler setup');

    $f = $this->modules->get('InputfieldCheckbox');
    $f->attr('name', 'enableLazyCron');
    $f->label = __('Enable LazyCron execution of tasks');
    if (!$this->modules->isInstalled('LazyCron')) {
      $f->description = __('LazyCron is not installed, this setting does not have any effect.');
    } else {
      $f->description = __('LazyCron can be used to perform tasks in the background in every minute.');
    }
    $f->columnWidth = 50;
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldCheckbox');
    $f->attr('name', 'enableCron');
    $f->label = __('Enable Cron execution of tasks');
    $f->description = __('Unix Cron will be used to perform tasks in the background in every minute.');
    $f->columnWidth = 50;
    $fieldset->add($f);

    $inputfields->add($fieldset);

/********************  Module settings ******************************/
    $fieldset = $this->wire('modules')->get('InputfieldFieldset');
    $fieldset->label = __('Module settings');

    $f = $this->modules->get('InputfieldText');
    $f->attr('name', 'ajaxTimeout');
    $f->label = __('Max exec time for HTTP (AJAX) task execution.');
    $f->description = __('Lower value means more frequent progress field updates and higher overhead. Should be less than ').ini_get('max_execution_time');
    $f->required = true;
    $f->columnWidth = 50;
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldText');
    $f->attr('name', 'lazyCronTimeout');
    $f->label = __('Max exec time for LazyCron task execution.');
    $f->description = __('LazyCron is not really suitable for executing long tasks.');
    $f->required = true;
    $f->columnWidth = 50;
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldSelect');
    $f->attr('name', 'taskTemplate');
    $f->label = __('Task template');
    $f->description = __('Required fields: task_running, task_data, task_state, ...');
    $f->options = array();
    $f->required = true;
    $f->columnWidth = 100;
    foreach($this->wire('templates') as $template) {
      if ($template->hasField('task_data')) {
        $f->addOption($template->name, $template->name);
      }
    }
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldCheckbox');
    $f->attr('name', 'debug');
    $f->label = __('Debug mode');
    $f->description = __('Enable detailed log messages while executing tasks.');
    $f->columnWidth = 50;
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldCheckbox');
    $f->attr('name', 'profiling');
    $f->label = __('Execution profiling');
    $f->description = __('Add timestamps (in seconds) to certain debug messages for basic code profiling.');
    $f->columnWidth = 50;
    $fieldset->add($f);

    $inputfields->add($fieldset);

    return $inputfields;
  }
}
