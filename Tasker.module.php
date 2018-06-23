<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * Tasker module - configuration
 * 
 * Allows modules to execute long-running tasks (i.e. longer than PHP's max_exec_time).
 * It supports Unix Cron, Javascript and a LazyCron scheduling of tasks.
 * 
 * Copyright 2017 Tamas Meszaros <mt+github@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

class Tasker extends WireData implements Module {
  // time when this module was instantiated
  private $startTime;
  // task states
  const taskUnknown = 0;
  const taskActive = 1;
  const taskWaiting = 2;
  const taskSuspended = 3;
  const taskFinished = 4;
  const taskKilled = 5;
  const taskFailed = 6;

/***********************************************************************
 * MODULE SETUP
 **********************************************************************/

 /**
   * Called only when this module is installed
   * 
   * Creates new custom database table for storing import configuration data.
   */
  public function ___install() {
    // TODO create a task template if it does not exists
  }


  /**
   * Called only when this module is uninstalled
   * 
   * Drops database table created during installation.
   */
  public function ___uninstall() {
    // TODO delete the task template??
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
    $taskData['max_records'] = 0;
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

    // check if the same task already exists
    $op = $page->child("template={$this->taskTemplate},signature={$p->signature},include=hidden");
    if (!($op instanceof NullPage)) {
      $this->message("Task '{$op->title}' already exists for '{$page->title}' and executed by {$moduleName}->{$method}.", Notice::debug);
      unset($p);
      return $op;
    }

    // tasks are hidden pages directly below their object (or any other page they belong to)
    $p->parent = $page;
    $p->title = $title;
    $p->addStatus(Page::statusHidden);
    $p->progress = 0;
    $p->task_state = self::taskWaiting;
    $p->task_running = 0;
    $p->save();
    $this->message("Created task '{$title}' for '{$page->title}' executed by {$moduleName}->{$method}().", Notice::debug);
    return $p;
  }

  /**
   * Trash a task (and set it to killed state).
   * 
   * @param $task ProcessWire Page object of a task
   */
  public function trashTask(Page $task) {
    $task->task_state = self::taskKilled;
    $task->trash();
    $this->message("Task '{$task->title}' has been removed.", Notice::debug);
    return true;
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
   * @returns true on success
   */
  public function activateTask(Page $task) {
    $taskData = json_decode($task->task_data, true);
    if (!$this->checkTaskDependencies($task, $taskData)) {
      $this->warning("Task '{$task->title}' cannot be activated since one of its dependencies is not met.");
      return false;
    }
    $task->task_state = self::taskActive;
    $task->save('task_state');
    $this->message("Task '{$task->title}' has been activated.", Notice::debug);
    return true;
  }

  /**
   * Activate a set of tasks (set them to ready to run state).
   * 
   * @param $taskSet Page, Page ID or array of Pages/IDs
   * @returns true on success
   */
  public function activateTaskSet($taskSet) {
    if ($taskSet instanceof Page) return $this->activateTask($taskSet);
    if (is_integer($taskSet)) return $this->activateTask($this->getTaskById($taskSet));
    if (!is_array($taskSet)) {
      $this->error('Invalid arguments provided to activateTaskSet().');
      return false;
    }
    $ret = true;
    foreach ($taskSet as $task) {
      $ret &= $this->activateTaskSet($task);
    }
    return $ret;
  }

  /**
   * Stop (suspend or kill) a task (set it to non-running state).
   * This only sets the state. The task will stop after finishing the actual step.
   * Progress will also be saved.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $kill kill the task?
   */
  public function stopTask(Page $task, $kill = false) {
    if ($kill) {
      $task->task_state = self::taskKilled;
      // TODO $task->log .= "The task has been terminated by ....\n";
      $this->message("Task '{$task->title}' has been killed.", Notice::debug);
    } else if ($task->progress == 0) {
      $task->task_state = self::taskWaiting;
      $this->message("Task '{$task->title}' has been reset", Notice::debug);
    } else {
      $task->task_state = self::taskSuspended;
      $this->message("Task '{$task->title}' has been suspended.", Notice::debug);
    }
    $task->save('progress');
    $task->save('task_state');
    return true;
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
   * Save progress and actual task_data.
   * Save and clear log messages.
   * Also checks task's state and events if requested.
   * 
   * @param $task Page object of the task
   * @param $taskData assoc array of task data
   * @param $updateState if true task_state will be updated from the database
   * @param $checkEvents if true runtime events (e.g. OS signals) will be processed
   * @return false if the task is no longer active
   */
  public function saveProgress($task, $taskData, $updateState=true, $checkEvents=true) {
    if ($taskData['max_records']) // report progress if max_records field is aready calculated (or used at all)
      $task->progress = round(100 * $taskData['records_processed'] / $taskData['max_records'], 2);
    $task->save('progress');
    $task->task_data = json_encode($taskData);
    $task->save('task_data');
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
   * Check if a task milestone has been reached and save progress if yes.
   * Task progress should be saved at certain time points in order to monitor them.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $taskData assoc array of task data
   * @param $updateState if true task_state will be updated from the database
   * @param $checkEvents if true runtime events (e.g. OS signals) will be processed
   * @returns true if milestone is reached
   */
  public function saveProgressAtMilestone(Page $task, $taskData, $updateState=true, $checkEvents=true) {
    if ($task->modified > time() - $this->ajaxTimeout) return false;
    return $this->saveProgress($task, $taskData, $updateState, $checkEvents);
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

    $task->task_data = json_encode($taskData);
    $task->save('task_data');

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

    $task->task_data = json_encode($taskData);
    $task->save('task_data');

    $this->message("Added '{$otherTask->title}' as a follow-up task to '{$task->title}'.", Notice::debug);
    return true;
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
    // find a ready-to-run but not actually running task to execute
    $selector = "template={$this->taskTemplate},task_state=".self::taskActive.",task_running=0,include=hidden";
    $task = $this->pages->findOne($selector);
    if ($task instanceof NullPage) return;

    // set up runtime parameters
    $params = array();
    $params['timeout'] = $this->startTime + $this->lazyCronTimeout;
    $params['invoker'] = 'LazyCron';

    if ($this->config->debug) echo "LazyCron invoking Tasker to execute '{$task->title}'.<br />\n";

    while (!($task instanceof NullPage) && !$this->executeTaskNow($task, $params)) { // if can't exec this
      // find a next candidate
      if ($this->config->debug) echo "Could not execute '{$task->title}'. Tasker is trying to find another candidate.<br />\n";
      $selector .= ",id!=".$task->id;
      $task = $this->pages->findOne($selector);
    }
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
    if (!$this->enableCron) return;
    // find a ready-to-run but not actually running task to execute
    $selector = "template={$this->taskTemplate},task_state=".self::taskActive.",task_running=0,include=hidden";
    $task = $this->pages->findOne($selector);
    if ($task instanceof NullPage) return; // nothing to do

    // set up runtime parameters
    $params = array();
    $params['timeout'] = 0;
    $params['invoker'] = 'Cron';

    if ($this->config->debug) echo "Cron invoking Tasker to execute '{$task->title}'.\n";

    while (!($task instanceof NullPage) && !$this->executeTaskNow($task, $params)) { // if can't exec this
      // find a next candidate
      if ($this->config->debug) echo "Could not execute '{$task->title}'. Tasker is trying to find another candidate.\n";
      $selector .= ",id!=".$task->id;
      $task = $this->pages->findOne($selector);
    }
    foreach(wire('notices') as $notice) {
      echo $notice->text."\n";
    }
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

    // check task state
    if ($task->task_state != self::taskActive) {
      $this->warning("Task '{$task->title}' is not active.");
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

    return $this->executeTaskNow($task, $params);
  }


  /**
   * Execute a task right now (internal)
   * 
   * @param $task ProcessWire Page object of a task
   * @param $params runtime parameters for task execution
   */
  private function executeTaskNow(Page $task, $params) {
    // check if we have time to do anything or not
    if ($params['timeout'] && $params['timeout'] <= time()) {
      $this->message("No time left to execute the task '{$task->title}'.");
      return false;
    }

    // decode the task data into an associative array
    $taskData = json_decode($task->task_data, true);

    // for the first execution check the requirements and dependencies
    if (!$taskData['records_processed']) {
      if (!$this->checkTaskRequirements($task, $taskData)) {
        $task->task_state = self::taskFailed;
        $task->save('task_state');
        return false;
      }
      if (!$this->checkTaskDependencies($task, $taskData)) {
        $task->task_state = self::taskSuspended;
        $task->save('task_state');
        return false;
      }
    }

    // determine the function to be called
    if ($taskData['module'] !== null) {
      $function = array($this->modules->get($taskData['module']), $taskData['method']);
    } else {
      $function = $taskData['method'];
    }

    // the the Page object for the task
    $page = $this->pages->get($taskData['pageid']);

    // note that the task is actually running now
    $this->message("Tasker is executing '{$task->title}' requested by {$params['invoker']}.", Notice::debug);
    $task->task_running = 1;
    $task->save();

    // pass over the task object to the function
    $params['task'] = $task;

    // set a signal handler to handle stop requests
    $itHandler = function ($signo) use ($task) {
      $this->messages('Task was killed by user request');
      $task->task_state = self::taskSuspended; // the task will be stopped
      return;
    };
    pcntl_signal(SIGTERM, $itHandler);
    pcntl_signal(SIGINT, $itHandler);

    // execute the function
    $res = $function($page, $taskData, $params);

    // check result status and set task state accordingly
    if ($res === false) {
      $this->message("Task '{$task->title}' failed.", Notice::debug);
      $task->task_state = self::taskFailed;
      $task->save('task_state');
    } else if ($taskData['task_done']) {
      $this->message("Task '{$task->title}' finished.", Notice::debug);
      $task->task_state = self::taskFinished;
      $task->save('task_state');
      if (isset($taskData['next_task'])) {
        // activate the next tasks that are waiting for this one
        $this->activateTaskSet($taskData['next_task']);
      }
    }

    // save task data (don't update state and don't check for events)
    $this->saveProgress($task, $taskData, false, false);

    // the task is no longer running
    $task->task_running = 0;
    $task->save('task_running');

    return $res;
  }

  /**
   * Check whether a task is ready for execution.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $taskData array of task data
   * 
   * @returns true if everything is fine
   */
  public function checkTaskRequirements(Page $task, $taskData) {
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
   * Check whether dependencies are met.
   * 
   * @param $task ProcessWire Page object of a task
   * @param $taskData array of task data
   * 
   * @returns true if everything is fine
   */
  public function checkTaskDependencies(Page $task, $taskData) {
    if (!isset($taskData['dep'])) return true;

    // $this->message("Checking dependencies for '{$task->title}'.", Notice::debug);

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
}
