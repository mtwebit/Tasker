<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * Tasker module - configuration
 * 
 * Allows modules to execute long-running tasks (i.e. longer than PHP's max_exec_time).
 * It supports Unix Cron, Javascript and a LazyCron scheduling of tasks.
 * 
 * Copyright 2017 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

class Tasker extends WireData implements Module {
  // time when this module was instantiated
  private $startTime;
  // task states
  const taskUnknown = 0;
  const taskActive = 1;     // ready to run
  const taskWaiting = 2;    // waiting to be activated
  const taskFinished = 3;   // finished
  const taskKilled = 4;     // killed by the user (progress is reset to zero)
  const taskFailed = 5;     // failed to execute
  // TODO make this configurable
  // memory limit threshold
  const mem_thr = 5*1024*1024;
  // exception codes
  const exTimeout = 1;
  // Tasker template fields
  private $myFields = array(
    'title' => array('type' => 'FieldtypePageTitle', 'Label' => 'Title'),
    'task_data' => array('type' => 'FieldtypeTextarea', 'Label' => 'Task data'),
    'task_state' => array('type' => 'FieldtypeInteger', 'Label' => 'Task state', 'min' => '0', 'max' => '10'),
    'task_running' => array('type' => 'FieldtypeInteger', 'Label' => 'Task is running', 'min' => '0', 'max' => '1'),
    'progress' => array('type' => 'FieldtypeInteger', 'Label' => 'Progress [%]', 'min' => '0', 'max' => '100'),
    'signature' => array('type' => 'FieldtypeText', 'Label' => 'Signature'),
    'log_messages' => array('type' => 'FieldtypeTextarea', 'Label' => 'Log messages')
  );

/***********************************************************************
 * MODULE SETUP
 **********************************************************************/

 /**
   * Called only when this module is installed
   * 
   * Creates new custom database table for storing import configuration data.
   */
  public function ___install() {
    // check / add fieldgroup
    $fg = $this->fieldgroups->get('tasker-fieldgroup');
    if (!@$fg->id) {
      $fg = new Fieldgroup();
      $fg->name = 'tasker-fieldgroup';
      $fg->add($this->fields->get('title'));
      $fg->add($this->fields->get('body'));
      $field->tags = 'Tasker';
      $fg->save();
    }

    // check / add template
    $t = $this->templates->get('tasker_task');
    if (!$t) {
      $t = new Template();
      $t->name = 'tasker_task';
      $t->label = 'Tasker task';
      $t->tags = 'Tasker';
      $t->noLang = true;
      $t->fieldgroup = $fg; // set the field group
      $t->noChildren = 1;
      $t->save();
    }

    // create and add required fields, update the fg if necessary
    foreach ($this->myFields as $fname => $fcdata) {
      $field = $this->fields->get($fname);
      if (!@$field->id) {
        $field = new Field();
        $field->name = $fname;
        $field->label = $fcdata['label'];
        $field->type = $this->modules->get($fcdata['type']);
        if ($fcdata['type'] == 'FieldtypeInteger') {
          $field->inputType = 'number';
          $field->min = $fcdata['min'];
          $field->max = $fcdata['max'];
        }
        if ($fname != 'title') $field->tags = 'Tasker';
        $field->save();
      }
      if (!$fg->hasField($field)) $fg->add($field);
    }

    // save the fieldgroup
    $fg->save();

  }


  /**
   * Called only when this module is uninstalled
   * 
   * Drops database table created during installation.
   */
  public function ___uninstall() {
    // Delete the task template if no content present
    if ($this->taskTemplate && !$this->pages->count('template='.$this->taskTemplate.',include=hidden')) {
      $t = $this->templates->get('tasker_task');
      if ($t) {
        $this->templates->delete($t);
      }
      $fg = $this->fieldgroups->get('tasker-fieldgroup');
      if (@$fg->id) {
        $this->fieldgroups->delete($fg);
      }
      foreach ($this->myFields as $fname => $fcdata) {
        $field = $this->fields->get($fname);
        if ($field) {
          if ($field->numFieldgroups() > 0) continue;
          if (@$field->getTemplates()->count() > 0) continue;
          $this->fields->delete($field);
        }
      }
    }
  }

  /**
   * Initialization
   * 
   * This function attaches a hook for page save and decodes module options.
   */
  public function init() {
    $this->startTime = time();

    // enable LazyCron if it is configured
    if ($this->enableLazyCron && $this->modules->isInstalled('LazyCron')) {
      $this->addHook('LazyCron::every30Seconds', $this, 'executeByLazyCron');
    }
  }

/***********************************************************************
 * TASK MANAGEMENT
 **********************************************************************/

  /**
   * Create a task to execute it by calling a method in a module
   * 
   * @param $moduleName name of the module
   * @param $method method to call
   * @param $page page object argument to the method
   * @param $title human-readable title of the task
   * @param $taskData optional set of other arguments for the method
   * 
   * @returns task page object or NULL if creation failed.
   */
  public function createTask($moduleName, $method, $page, $title, $taskData=array()) {
    // remove the ProcessWire namespace prefix from the module's name
    $moduleName = str_replace('ProcessWire\\', '', $moduleName);
    // TODO better validation. $moduleName could be NULL
    if (!$this->modules->isInstalled($moduleName) || ($page instanceof NullPage)) {
      $this->error("Error creating new task '{$title}' for '{$page->title}' executed by {$moduleName}->{$method}.");
      return NULL;
    }
    $p = $this->wire(new Page());
    if (!is_object($p)) {
      $this->error("Error creating new page for task '{$title}'.");
      return NULL;
    }

    $p->template = $this->taskTemplate;
    $p->of(false);
    // set module and method to call on task execution
    $taskData['module'] = $moduleName;
    $taskData['method'] = $method;
    // set page id
    $taskData['pageid'] = $page->id;
    // set initial number of processed and maximum records
    $taskData['records_processed'] = 0;
    if (!isset($taskData['max_records'])) $taskData['max_records'] = 0;
    // set task status
    $taskData['task_done'] = 0;
    // check and adjust dependencies
    if (isset($taskData['dep'])) {
      if (is_array($taskData['dep'])) foreach ($taskData['dep'] as $key => $dep) {
        if (is_numeric($dep)) break; // it's OK
        if ($dep instanceof Page) $taskData['dep'][$key] = $dep->id; // replace it with its ID
        else {
          $this->warning("Removing invalid dependency from task '{$task->title}'.");
          unset($taskData['dep'][$key]); // remove invalid dependencies
        }
      } else if (!is_numeric($taskData['dep'])) {
        $this->warning("Removing invalid dependency from task '{$task->title}'.");
        unset($taskData['dep']);
      }
    }
    $p->task_data = json_encode($taskData);
    // unique signature for the task (used in comparisons)
    $p->signature = md5($p->task_data); // this should not change (task_data may)

    $p->log_messages = '';

    // tasks are hidden pages directly below their object (or any other page they belong to)
    $p->parent = $page;
    $p->title = $title;
    $p->addStatus(Page::statusHidden);
    $p->progress = 0;
    $p->task_state = self::taskWaiting;
    $p->task_running = 0;

    // suspend this task and warn the user if the same task already exists
    $op = $page->child("template={$this->taskTemplate},signature={$p->signature},include=hidden");
    if (!($op instanceof NullPage)) {
      $this->warning("The same task '{$op->title}' exists for '{$page->title}' and executed by {$moduleName}->{$method}.");
      $p->task_state = self::taskWaiting;
    }

    $p->save();
    $this->message("Created task '{$title}' for '{$page->title}' executed by {$moduleName}->{$method}().", Notice::debug);
    return $p;
  }

  /**
   * Return tasks matching a selector.
   * 
   * @param $selector ProcessWire selector except that integer values match task_state
   * @returns WireArray of tasks
   */
  public function getTasks($selector='') {
    if (is_integer($selector)) $selector = 'task_state='.$selector;
    $selector .= ($selector == '' ? 'template='.$this->taskTemplate : ',template='.$this->taskTemplate);
    $selector .= ',include=hidden'; // task pages are hidden by default
    // $this->message($selector, Notice::debug);
    return $this->pages->find($selector);
  }


  /**
   * Return a task with a given Page id
   * 
   * @param $selector ProcessWire selector
   * @returns WireArray of tasks
   */
  public function getTaskById($taskId) {
    return $this->pages->get($taskId);
  }

  /**
   * Check whether two tasks are equal or not
   * 
   * @param $task1 first task object
   * @param $task1 second task object
   * @returns true if they were at the time of their creation :)
   */
  public function checkEqual($task1, $task2) {
    return $task1->signature == $task2->signature;
  }

  /**
   * Check if the task is active
   * 
   * @param $task Page object of the task
   * @return false if the task is no longer active
   */
  public function isActive($task) {
    return ($task->task_state == self::taskActive);
  }

  /**
   * Activate a task (set it to ready to run state).
   * The task will be executed by the executeTask() method later on.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $activateAnyway activate the task no matter its status (Finished, Failed etc.)
   * @returns true on success
   */
  public function activateTask(Page $task, $activateAnyway = true) {
    $taskData = json_decode($task->task_data, true);
    if (!$this->checkTaskDependencies($task, $taskData)) {
      $this->warning("Task '{$task->title}' cannot be activated because one of its dependencies is not met.");
      return false;
    }
    if (!$activateAnyway && $task->task_state != self::taskWaiting) {
      $this->warning("Task '{$task->title}' cannot be activated because it is not ready to run.");
      return false;
    }
    $task->setAndSave('task_state', self::taskActive);
    $this->message("Task '{$task->title}' has been activated.", Notice::debug);
    return true;
  }

  /**
   * Activate a set of tasks (set them to ready to run state).
   * 
   * @param $taskSet Page, Page ID or array of Pages/IDs
   * @param $activateAnyway activate the task no matter its status (Finished, Failed etc.)
   * @returns true on success
   */
  public function activateTaskSet($taskSet, $activateAnyway = true) {
    if ($taskSet instanceof Page) return $this->activateTask($taskSet, $activateAnyway);
    if (is_integer($taskSet)) return $this->activateTask($this->getTaskById($taskSet), $activateAnyway);
    if (!is_array($taskSet)) {
      $this->error('Invalid arguments provided to activateTaskSet().');
      return false;
    }
    $ret = true;
    foreach ($taskSet as $task) {
      $ret &= $this->activateTaskSet($task, $activateAnyway);
    }
    return $ret;
  }

  /**
   * Stop (suspend or kill) a task (set it to non-running state).
   * Note: if a task is already running it may need some time to stop and reset.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $kill kill the task?
   * @param $reset reset the task's progress? (reset is set to true when kill is true)
   */
  public function stopTask(Page $task, $kill = false, $reset = false) {
    if ($kill) {
      $task->setAndSave('task_state', self::taskKilled);
      // TODO $task->log .= "The task has been terminated by ....\n";
      $this->message("Task '{$task->title}' has been killed.", Notice::debug);
      $reset = 1;
    } else {
      $task->setAndSave('task_state', self::taskWaiting);
      $this->message("Task '{$task->title}' has been suspended.", Notice::debug);
    }
    if ($reset) {
      $this->resetProgress($task);
    }
    return true;
  }

  /**
   * Trash a task (and set it to killed state).
   * 
   * @param $task ProcessWire Page object of a task
   */
  public function trashTask(Page $task) {
    $task->setAndSave('task_state', self::taskKilled);
    $task->trash();
    $this->message("Task '{$task->title}' has been thrashed.", Notice::debug);
    return true;
  }

  /**
   * Add a follow-up task to the task.
   * Follow-up tasks will be automagically activated when this task is finished.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $nextTask Page object of the follow-up task
   */
  public function addNextTask(Page $task, $nextTask) {
    if (!$nextTask instanceof Page) {
      $this->error('Invalid next task provided to addNextTask().');
      return false;
    }

    $taskData = json_decode($task->task_data, true);
    if (!isset($taskData['next_task'])) {
      $taskData['next_task'] = $nextTask->id;
    } else if (is_integer($taskData['next_task'])) {
      $tasks = array($taskData['next_task'], $nextTask->id);
      $taskData['next_task'] = $tasks;
    } else if (is_array($taskData['next_task'])) {
      $taskData['next_task'][] = $nextTask->id;
    } else {
      $this->error("Failed to add a follow-up task to '{$task->title}'.");
      return false;
    }

    $task->setAndSave('task_data', json_encode($taskData));

    $this->message("Added '{$nextTask->title}' as a follow-up task to '{$task->title}'.", Notice::debug);
    return true;
  }


  /**
   * Add a dependency to the task.
   * Follow-up tasks will be automagically activated when this task is finished.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $nextTask Page object of the follow-up task
   */
  public function addDependency(Page $task, $otherTask) {
    if (!$otherTask instanceof Page) {
      $this->error('Invalid dependency provided to addDependency().');
      return false;
    }

    $taskData = json_decode($task->task_data, true);
    if (!isset($taskData['dep'])) {
      $taskData['dep'] = $otherTask->id;
    } else if (is_integer($taskData['dep'])) {
      $tasks = array($taskData['dep'], $otherTask->id);
      $taskData['dep'] = $tasks;
    } else if (is_array($taskData['dep'])) {
      $taskData['dep'][] = $otherTask->id;
    } else {
      $this->error("Failed to add a dependency to '{$task->title}'.");
      return false;
    }

    $task->setAndSave('task_data', json_encode($taskData));

    $this->message("Added '{$otherTask->title}' as a dependency to '{$task->title}'.", Notice::debug);
    return true;
  }


/***********************************************************************
 * TASK DATA AND PROGRESS MANAGEMENT
 **********************************************************************/
  /**
   * Recalculate and save progress.
   * Save and clear log messages, save task_data.
   * Also check task's state and events if requested.
   * 
   * @param $task Page object of the task
   * @param $taskData assoc array of task data
   * @param $updateState if true task_state will be updated from the database
   * @param $checkEvents if true runtime events (e.g. OS signals) will be processed
   */
  public function saveProgress($task, $taskData, $updateState=true, $checkEvents=true) {
    if ($taskData['max_records']) // report progress if max_records is calculated
      $task->setAndSave('progress', round(100 * $taskData['records_processed'] / $taskData['max_records'], 2));
    $task->setAndSave('task_data', json_encode($taskData));
    // store and clear messages
    foreach(wire('notices') as $notice) $task->log_messages .= $notice->text."\n";
    $task->save('log_messages');
    wire('notices')->removeAll();

    // check and handle signals (handler is defined in executeTask())
    // signal handler will change (and save) the task's status if the task was interrupted
    if ($checkEvents) $this->checkEvents($task, $taskData);

    if ($updateState) {
      // update the task's state from the database (others may have changed it)
      $task2 = $this->wire('pages')->getById($task->id, array(
        'cache' => false, // don't let it write to cache
        'getFromCache' => false, // don't let it read from cache
        'getOne' => true, // return a Page instead of a PageArray
      ));
      $task->task_state = $task2->task_state;
    }
  }

  /**
   * Save the task's progress IF a milestone is reached.
   * Tasks should call this regularly to allow their monitoring.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $taskData assoc array of task data
   * @param $updateState if true task_state will be updated from the database
   * @param $checkEvents if true runtime events (e.g. OS signals) will be processed
   * @returns true if milestone is reached (caller should set a new milestone)
   */
  public function saveProgressAtMilestone(Page $task, $taskData, $updateState=true, $checkEvents=true) {

    // return if there is no milestone or it is not reached
    if (!isset($taskData['milestone']) || $taskData['milestone'] > $taskData['records_processed'])
      return false;

    // save the progress
    // this may alter the task's state (if updateState or checkEvents is true
    $this->saveProgress($task, $taskData, $updateState, $checkEvents);

    return true;
  }

  /**
   * Reset the task's progress and clear log messages.
   * 
   * @param $task Page object of the task
   */
  public function resetProgress($task) {
    // decode the task data into an associative array
    $taskData = json_decode($task->task_data, true);
    // an reinitialize it
    $taskData['records_processed'] = 0;
    unset($taskData['milestone']);
    $taskData['task_done'] = 0;
    $task->setAndSave('progress', 0);
    $task->setAndSave('task_data', json_encode($taskData));
    $task->setAndSave('log_messages', '');
  }

  /**
   * Get a summary of log messages.
   * 
   * @param $task Page object of the task
   */
  public function getLogSummary($task, $getErrors = true, $getWarnings = false) {
    if (isset($task->progress) && $task->progress) $ret = $task->progress . '%';
    else $ret = '';
    if ($getErrors) {
      $num = preg_match_all('|\bERROR[: ]|i', $task->log_messages);
      if ($num) $ret .= ' '.$num;
      else $ret .= ' No';
      $ret .= ' error(s)';
    }
    $ret = ltrim($ret);
    if ($getWarnings) {
      $num = preg_match_all('|\bWARNING[: ]|i', $task->log_messages);
      if ($num) {
        $ret .= ' '.$num.' warning(s)';
      }
    }
    return ltrim($ret);
  }

/***********************************************************************
 * EXECUTING TASKS
 **********************************************************************/

  /**
   * Select and execute a tasks using LazyCron
   * This is automatically specified as a LazyCron callback if it is enabled in Tasker.
   * 
   * @param $e HookEvent
   */
  public function executeByLazyCron(HookEvent $e) {
    if ($this->pages->count("template={$this->taskTemplate},task_running=1,include=hidden")>2) return;

    // find a ready-to-run but not actually running task to execute
    $selector = "template={$this->taskTemplate},task_state=".self::taskActive.",task_running=0,include=hidden";
    $task = $this->pages->findOne($selector);
    if ($task instanceof NullPage) return;

    // set up runtime parameters
    $params = array();
    $params['timeout'] = $this->startTime + $this->lazyCronTimeout;
    $params['memory_limit'] = self::getSafeMemoryLimit();
    $params['invoker'] = 'LazyCron';

    if ($this->config->debug) echo "LazyCron invoking Tasker to execute '{$task->title}'.<br />\n";

    while (!($task instanceof NullPage) && !$this->executeTaskNow($task, $params)) { // if can't exec this
      // find the next candidate
      if ($this->config->debug) echo "Failed to execute '{$task->title}'. Tasker is trying to find another candidate.<br />\n";
      $selector .= ",id!=".$task->id;
      $task = $this->pages->findOne($selector);
    }
    // TODO this dumps nothing if the task has been finished, dump its log messages instead?
    echo '<ul class="NoticeMessages">';
    foreach(wire('notices') as $notice) {
      $text = wire('sanitizer')->entities($notice->text);
      echo "<li>$text</li>\n";
    }
    echo '</ul>';
  }


  /**
   * Execute a task using the command line (e.g. Unix Cron)
   * This is called by the runByCron.sh shell script.
   */
  public function executeByCron() {
    // silently exit if cron is disabled on the module's setting page
    if (!$this->enableCron) return;
    // Limit the number of running tasks. TODO make this configurable
    if ($this->pages->count("template={$this->taskTemplate},task_running=1,include=hidden")>3) { echo "Maximum number of running tasks reached.\n"; return; }
    // find a ready-to-run but not actually running task to execute
    $selector = "template={$this->taskTemplate},task_state=".self::taskActive.",task_running=0";
    $task = $this->pages->get($selector);
    if ($task instanceof NullPage) return;

    // set up runtime parameters
    $params = array();
    $params['timeout'] = $this->startTime + 18000; // TODO: better handle mysql wait_timeout
    $params['memory_limit'] = self::getSafeMemoryLimit();
    $params['invoker'] = 'Cron';

    if ($this->config->debug) echo "Cron invoking Tasker to execute '{$task->title}'.\n";

    while (!($task instanceof NullPage) && !$this->executeTaskNow($task, $params)) { // if can't exec this
      // find the next candidate if cron is still enabled
      if (!$this->enableCron) break;
      if ($this->config->debug) {
        echo "Failed to execute '{$task->title}'. Tasker is trying to find another candidate.\n";
        // Dump out messages on task failure
        foreach(wire('notices') as $notice) {
          echo wire('sanitizer')->entities($notice->text) . "\n";
        }
      }
      $selector .= ",id!=".$task->id;
      $task = $this->pages->findOne($selector);
    }
    echo $task->log_messages."\n";
  }


  /**
   * Start executing a task (pre-flight checks).
   * This should be called by HTTP API routers or other modules.
   * Calls executeTaskNow() if everything is fine.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $params runtime parameters for task execution
   */
  public function executeTask(Page $task, $params=array()) {
    // check task template
    if ($task->template != $this->taskTemplate) {
      $this->warning("Page '{$task->title}' has incorrect template.");
      return false;
    }

    // check if the task is already running
    if ($task->task_running) {
      $this->warning("Task '{$task->title}' is already running. Will not execute again.");
      return false;
    }

    // set who is executing the task
    if (!isset($params['invoker'])) {
      $params['invoker'] = $this->user->name;
    }

    // set the timeout
    if (!isset($params['timeout'])) {
      $params['timeout'] = $this->startTime + $this->ajaxTimeout;
    }

    // set the memory limit
    if (!isset($params['memory_limit'])) {
      $params['memory_limit'] = self::getSafeMemoryLimit();
    }

    return $this->executeTaskNow($task, $params);
  }


  /**
   * Execute a task right now (internal)
   * 
   * @param $task ProcessWire Page object of a task
   * @param $params runtime parameters for task execution
   * @returns false on exec error
   */
  private function executeTaskNow(Page $task, $params) {
    if (!$this->allowedToExecute($task, $params)) return false;

    // decode the task data into an associative array
    $taskData = json_decode($task->task_data, true);

    // before the first execution check the requirements and dependencies
    if (!$taskData['records_processed']) {
      if (!$this->checkTaskRequirements($task, $taskData)) {
        $task->setAndSave('task_state', self::taskFailed);
        return false;
      }
      if (!$this->checkTaskDependencies($task, $taskData)) {
        $task->setAndSave('task_state', self::taskWaiting);
        return false;
      }
    }

    // determine the function to be called
    if ($taskData['module'] !== null) {
      $function = array($this->modules->get($taskData['module']), $taskData['method']);
    } else {
      $function = $taskData['method'];
    }

    // the Page object for the task
    $page = $this->pages->get($taskData['pageid']);

    // turn on/off debugging according to the user's setting
    $olddebug = $this->config->debug;
    $this->config->debug = $this->debug;

    // note that the task is actually running now
    $this->message("------------ Task '{$task->title}' started/continued at ".date(DATE_RFC2822).' ------------', Notice::debug);
    $this->message("Tasker is executing '{$task->title}' requested by {$params['invoker']}.", Notice::debug);
    $task->setAndSave('task_running', 1);

    // pass over the task object to the function
    $params['task'] = $task;

    // set a signal handler to handle stop requests
    $itHandler = function ($signo) use ($task) {
      $this->messages('Task was suspended by user request.');
      $task->task_state = self::taskWaiting; // the task will be stopped
      return true;
    };
    pcntl_signal(SIGTERM, $itHandler);
    pcntl_signal(SIGINT, $itHandler);

    // if we have a timeout value then setup an alarm clock
    if ($params['timeout'] > 0) {
      pcntl_signal(SIGALRM, function ($signo) use ($task) {
        $task->task_state = self::taskFailed; // the task will be stopped
        $this->message('Either the limit is too low (check Tasker config) or the task\'s code does not check the limit properly.');
        throw new \Exception('time limit expired.', $this->exTimeout);
      });
      pcntl_alarm($params['timeout'] - time());
    }

    // set a custom PHP error handler for WARNINGS and ERRORS
    set_error_handler(function($errno, $errstr, $errfile, $errline, array $errcontext) use ($task) {
      $this->message("ERROR: {$errstr}[{$errno}] in {$errfile} at line {$errline}.");
      return true; // bypass PHP error handling for warnings
    });
    //}, E_WARNING|E_ERROR);

    // TODO this does not really work.....
    // set a custom PHP shutdown function for fatal errors
    // This puffer will be freed on error (to handle memory exhaustion fatal errors)
    $this->puffermem = str_repeat('?', 1024 * 1024);
    register_shutdown_function(function() use ($task) {
      $this->puffermem = null; // free up some memory
      $task->task_state = self::taskFailed; // the task will be stopped
      $this->message("ERROR: {$task->title} encountered a fatal error and it is stopped.");
      return true;
    });

    // instruct trackException() to re-throw exceptions
    $oldAllowExceptions = $this->config->allowExceptions;
    $this->config->allowExceptions = true;

    // capture output while the task runs
    ob_start();
    
    // use innodb transactions
    if ($this->database->supportsTransaction()) {
      $this->database->beginTransaction();
    }

    try {
      $res = $function($page, $taskData, $params);
      ob_end_flush();
    } catch (\Exception $e) {
      $res = false;
      $this->message('ERROR: ' . $e->getMessage());
      $this->message(ob_get_contents());
      $this->message($e->getTraceAsString());

      // TODO logging to file?
      ob_end_clean();
    }

    // Check the DB connection (handle MySQL gone away and similar DB errors)
    try {
      // Execute a simple command with no inputs
      // From the PW API: "If connection is lost, this will restore it automatically."
      $this->database->pdo()->query('SHOW DATABASES');
    } catch (\Exception $e) {
      $res = false;
      $this->message('ERROR: Fatal DB exception: ' . $e->getMessage());
      // Dump out the logs
      foreach(wire('notices') as $notice) echo $notice->text."\n";
      // and exit right now
      exit();
    }

    if ($this->database->supportsTransaction()) {
      if ($res) {
        $this->database->commit();
      } else {
        $this->database->rollback();
      }
    }

    // restore old exception config
    $this->config->allowExceptions = $oldAllowExceptions;

    // restore the original error handler
    restore_error_handler();

    // check result status and set task state accordingly
    if ($res === false) {
      $this->message("Task '{$task->title}' failed.", Notice::debug);
      $task->setAndSave('task_state', self::taskFailed);
    } else {
      if ($taskData['task_done']) {
        $this->message("Task '{$task->title}' finished.", Notice::debug);
        $task->setAndSave('task_state', self::taskFinished);
        if (isset($taskData['next_task'])) {
          // activate the next tasks that are waiting for this one
          $this->activateTaskSet($taskData['next_task'], false /* don't activate if it is already finished */);
        }
      }
    }

    // save task data (don't update state and don't check for events)
    $this->saveProgress($task, $taskData, false, false);

    // the task is no longer running
    $task->setAndSave('task_running', 0);

    // restore the original debug setting
    $this->config->debug = $olddebug;

    return $res;
  }

  /**
   * Check if a task can run (or continue running)
   * 
   * @param $task ProcessWire Page object of a task
   * @param $params runtime parameters including time and memory limit
   * @returns true if the task can execute
   */
  public function allowedToExecute(Page $task, $params) {
    // check whether the task is still active (not stopped by others)
    if (!$this->isActive($task)) {
      $this->message("Task '{$task->title}' is stopped because it is not active.", Notice::debug);
      return false;
    }

    // suspend the task if the allowed execution time is over
    // TODO try to estimate a single round (now 2 sec is assumed)
    if ($params['timeout'] && ($params['timeout'] - 2 < time())) {
      $this->message("Task '{$task->title}' is suspended due to time limits.", Notice::debug);
      return false;
    }

    // suspend the task if the allowed memory limit is reached
    // TODO try to find a better constant multiplier
    if (isset($params['memory_limit'])
        && memory_get_usage() >= $params['memory_limit'] * 0.8){
      $this->message("Task '{$task->title}' is suspended due to memory limits.", Notice::debug);
      return false;
    }

    return true;
  }

  /**
   * Check whether a task meets its execution requirements
   * 
   * @param $task ProcessWire Page object of a task
   * @param $taskData array of task data
   * 
   * @returns true if everything is fine
   */
  public function checkTaskRequirements(Page $task, $taskData) {

    $this->message("Checking requirements for '{$task->title}'.", Notice::debug);

    // check if module or function exists
    $module = $taskData['module'];
    $method = $taskData['method'];
    if ($module !== null) {
      // check if module exists and working
      if (!$this->modules->isInstalled($module)) {
        $this->error("Error executing task '{$task->title}': module not found.");
        return false;
      }
      $module = $this->modules->get($taskData['module']);
      if (!method_exists($module, $method)) {
        $this->error("Error executing task '{$task->title}': method '{$method}' not found on '{$taskData['module']}'.");
        return false;
      }
    } else {
      if (!function_exists($method)) {
        $this->error("Error executing task '{$task->title}': function '{$method}' not found.");
        return false;
      }
    }

    // check if page exists
    $page = $this->pages->get($taskData['pageid']);
    if ($page instanceof NullPage) {
      $this->error("Error executing task '{$task->title}': input page not found.");
      return false;
    }

    return true;
  }

  /**
   * Check whether a task's execution dependencies are met.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $taskData array of task data
   * 
   * @returns true if everything is fine
   */
  public function checkTaskDependencies(Page $task, $taskData) {
    if (!isset($taskData['dep'])) return true;

    $this->message("Checking dependencies for '{$task->title}'.", Notice::debug);

    // may depend on a single task
    if (is_numeric($taskData['dep'])) {
      $depTask = $this->getTaskById($taskData['dep']);
      if ($depTask->id!=0 && !$depTask->isTrash() && ($depTask->task_state != self::taskFinished)) {
        $this->message("'{$task->title}' is waiting for '{$depTask->title}' to finish.", Notice::debug);
        return false;
      }
    }

    // may depend on several other tasks
    $ret = true;
    if (is_array($taskData['dep'])) foreach ($taskData['dep'] as $taskId) {
      $depTask = $this->getTask($taskId);
      if ($depTask->id!=0 && !$depTask->isTrash() && ($depTask->task_state != self::taskFinished)) {
        $this->message("'{$task->title}' is waiting for '{$depTask->title}' to finish.", Notice::debug);
        $ret = false;
      }
    }

    return $ret;
  }

  /**
   * Check whether any event happened during taks execution.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $taskData array of task data
   * 
   * @returns true if everything is fine
   */
  public function checkEvents(Page $task, $taskData) {
    // check for Unix signals
    pcntl_signal_dispatch();
    // TODO check and handle other events 
  }

  /**
   * Return the byte value of a php ini settings.
   * Taken from http://php.net/manual/en/function.ini-get.php
   *
   * @param $val string value that may contain G M or K modifiers
   * @returns byte value
   */
  protected static function getSafeMemoryLimit() {
    $size_str = trim(ini_get('memory_limit'));
    switch(substr($size_str, -1)) {
        case 'M': case 'm': return (int)$size_str * 1048576 - self::mem_thr;
        case 'K': case 'k': return (int)$size_str * 1024 - self::mem_thr;
        case 'G': case 'g': return (int)$size_str * 1073741824 - self::mem_thr;
        default: return $size_str;
    }
  }


/***********************************************************************
 * TASK PROFILING
 **********************************************************************/
  /**
   * Reset the profiler timer
   */
  public function profilerReset() {
    global $utimer;
    $utimer = microtime(true);
  }

  /**
   * Return the relative timestamp string
   * 
   * @returns timestamp string
   */
  public function profilerGetTimestamp() {
    global $utimer;

    if (!$this->profiling) return '';
    return '['.round(microtime(true) - $utimer, 2) . '] ';
  }
}
