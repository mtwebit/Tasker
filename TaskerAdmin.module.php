<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * Tasker admin module
 * 
 * Provides management interfaces (HTML and REST API) for Tasker.
 * 
 * Copyright 2017-2019 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

class TaskerAdmin extends Process implements Module {
  // the base URL of the module's admin page
  public $adminUrl;
  // human-readable task descriptions
  public $stateInfo = array(
    Tasker::taskUnknown => 'unknown',
    Tasker::taskActive => 'active',
    Tasker::taskWaiting => 'waiting',
    Tasker::taskFinished => 'finished',
    Tasker::taskKilled => 'killed',
    Tasker::taskFailed => 'failed',
  );

  /**
   * Called only when this module is installed
   * 
   * Creates the admin page.
   */
  public function ___install() {
    parent::___install(); // parent creates the admin page
  }

  /**
   * Called only when this module is uninstalled
   */
  public function ___uninstall() {
    parent::___uninstall(); // parent deletes the admin page
  }

  /**
   * Initialization
   */
  public function init() {
    parent::init();
    if (!$this->modules->isInstalled('Tasker')) {
      $this->error('Tasker module is missing.');
    }
    // set admin URL
    $this->adminUrl = wire('config')->urls->admin.'page/tasks/';
    // make this available for Javascript functions
    $this->config->js('tasker', [
        'adminUrl' => $this->adminUrl,
        'apiUrl' => $this->adminUrl.'api/',
        'timeout' => 1000*intval(ini_get('max_execution_time'))
      ]
    );
  }

/***********************************************************************
 * Process module endpoints
 * 
 * Module routing under <admin>/page/tasks
 *     JSON API   - loc: /api/?id=$taskId&cmd=$command - executeApi() - execute an api call or a task and return a JSON object
 *    
 * More info about routing:
 * https://processwire.com/talk/topic/7832-module-routing-executesomething-versus-this-input-urlsegment1-in-process-modules/
 **********************************************************************/
  /**
   * Main function for the admin menu interface
   */
  public function execute() {
    $out = '<b>Sort by: <a href="'.$this->page->url.'">Default</a> | ';
    $out .= '<a href="'.$this->page->url.'?sort=task_state">Status</a> | ';
    $out .= '<a href="'.$this->page->url.'?sort=-created">Created</a> | ';
    $out .= '<a href="'.$this->page->url.'?sort=-modified">Updated</a> | ';
    $out .= '<a href="'.$this->page->url.'?sort=progress">Progress</a></b>';

    list ($command, $taskId, $params) = $this->analyzeRequestURI($_SERVER['REQUEST_URI']);

    if (isset($params['sort'])) {
      $sortkey = wire('sanitizer')->selectorValue($params['sort']);
      $out .= $this->renderTaskList('sort=' . $sortkey);
    } else {
      $out .= $this->renderTaskList();
    }

    $out .= '<p><a href="'.$this->page->url.'">Refresh this list.</a></p>';
    return $out;
  }

  /**
   * HTTP JSON API endpoint
   * URI structure: .... api/?id=taskId&cmd=command[&arguments]
   */
  public function executeApi() {
    // response object (will be encoded in JSON form)
    $ret = array(
      'taskid' => 0, // text info about the task
      'taskinfo' => '', // text info about the task
      'task_state' => 0, // task numberic state
      'task_state_info' => '', // text info about the task's state
      'status'=> false, // return status
      'status_html' => '', // detailed task status and commands rendered in HTML
      'result' => '', // result from the task
      'progress' => 0, // percent completed
      'task_running' => 0, // is the task running?
      'log' => '', // log messages
      'debug' => $this->config->debug, // is debug mode active?
      );

    list ($command, $taskId, $params) = $this->analyzeRequestURI($_SERVER['REQUEST_URI']);
    if (!$command) {
      $ret['result'] = 'Invalid API request: '.$_SERVER['REQUEST_URI'];
      $ret['log'] = $this->getNotices();
      echo json_encode($ret);
      exit;
    }

    $tasker = wire('modules')->get('Tasker');

    // turn on/off debugging according to the user's setting
    $this->config->debug = $tasker->debug;

    if ($command == 'create') { // create a new task
      if (!isset($params['module']) || !($module = wire('modules')->get($params['module']))) {
        $ret['result'] = 'No valid module name found while creating a new task.';
        $ret['log'] = $this->getNotices();
        echo json_encode($ret);
        exit;
      }
      if (!isset($params['function']) || !method_exists($module, $params['function'])) {
        $ret['result'] = 'Method name is not specified or does not exists @ '.$params['module'].'.';
        $ret['log'] = $this->getNotices();
        echo json_encode($ret);
        exit;
      }
      if (!isset($params['pageId']) || !($pageObject = $this->pages->get($params['pageId'])) instanceof Page) {
        $ret['result'] = 'PageId is invalid or PW page does not exists.';
        $ret['log'] = $this->getNotices();
        echo json_encode($ret);
        exit;
      }
      if (!isset($params['title'])) {
        $ret['result'] = 'Task title is not set.';
        $ret['log'] = $this->getNotices();
        echo json_encode($ret);
        exit;
      }
      $task = $tasker->createTask($module, $params['function'], $pageObject, $params['title'], $params);
      $ret['result'] = 'Task creation failed.'; // default msg, task creation result will be checked later
      $command = 'status';  // execute a status command on the newly created task
    } else {
      $task = $tasker->getTaskById($taskId);
      $ret['result'] = 'No matching task found.';   // default msg, task's presence will be checked in the next if
    }
    if ($task == NULL || $task instanceof NullPage) {
      $ret['log'] = $this->getNotices();
      echo json_encode($ret);
      exit;
    }
    $ret['result'] = '';  // clear the default error msg

    // report back some info before we execute the command
    $ret['taskinfo'] = $task->title;
    $ret['taskid'] = $task->id;

    switch ($command) {
      case 'status':  // return the task's status
        $ret['status'] = true;
        $ret['result'] = $task->title;
        break;
      case 'restart': // reset progress then activate the task
        $tasker->resetProgress($task);
        $task->setAndSave('task_admin_running', 0);
      case 'activate':   // activate the task (will be run by Cron or LazyCron)
        $ret['status'] = true;
        $ret['result'] = $tasker->activateTask($task);
        $ret['task_running'] = $task->task_running;
        break;
      case 'run':     // execute the task right now
        if (false===($res = $tasker->executeTask($task, $params))) {
          $ret['result'] = 'Task execution failed.';
        } else {
          $ret['status'] = true;
          $ret['result'] = $res;
        }
        break;
      case 'start':
        // Does not run the task right now but instruct the JS backend to call 'run' commands
        if ($task->task_running) {
          $ret['result'] = 'Task is already running.';
          echo json_encode($ret);
          exit;
        }
        $ret['status'] = true;
        $ret['result'] = $tasker->activateTask($task);
        if ($ret['result']) {
          $task->setAndSave('task_admin_running', 1);
          // change the command to 'run' to activate the JS backend executor during tasklist rendering
          $command = 'run';
        }
        break;
      case 'reset':   // reset progress and logs
        $ret['status'] = true;
        $tasker->stopTask($task, false, true);
        $task->setAndSave('task_admin_running', 0);
        break;
      case 'suspend': // stop the task but keep progress
        $ret['status'] = true;
        $tasker->stopTask($task);
        $task->setAndSave('task_admin_running', 0);
        break;
      case 'kill':    // stop the running task
        $ret['status'] = true;
        $tasker->stopTask($task, true, true);
        $task->setAndSave('task_admin_running', 0);
        break;
      case 'trash':   // delete the task
        $ret['status'] = true;
        $tasker->trashTask($task, $params);
        break;
      default:
        $ret['result'] = 'Unknown command.';
        $ret['status_html'] = $this->renderTaskStatus($task);
        echo json_encode($ret);
        exit;
    }

    // report back some info after the command has been finished
    $ret['status_html'] = $this->renderTaskStatus($task, '', '', $command);
    $ret['progress'] = $task->progress;
    $ret['task_running'] = $task->task_running;
    $ret['task_state'] = $task->task_state;
    $ret['task_state_info'] = $this->stateInfo[$task->task_state];
    $ret['log'] .= "\n<ul class='NoticeMessages'>\n";
    // return the PW notice messages in the debug log
    foreach(wire('notices') as $msg) {
      if (strlen(trim($msg))) $ret['log'] .= "  <li>$msg</li>\n";
    }
    /* skip the full task log
    foreach (explode("\n", $task->log_messages) as $msg) {
      if (strlen(trim($msg))) $ret['log'] .= "  <li>$msg</li>\n";
    }
    */
    $ret['log'] .= "</ul>\n";
    echo json_encode($ret);
    exit; // don't output anything else
  }


/***********************************************************************
 * RENDERING FUNCTIONS
 **********************************************************************/
  /**
   * Render a HTML list of tasks
   * 
   * @param $selector optional pattern to select tasks (the template pattern will be added to it)
   * @param $liClass optional attributes for <li> tags. If null <li> is omitted.
   * @param $aClass optional attributes for <a> tags. If null <a> is omitted.
   * @param $jsCommand optional command that the Javascript functions will execute over the Tasker API
   * @returns html string to output, '' if no tasks have been found, false on error
   */
  public function renderTaskList($selector='sort=-task_running, sort=-created', $liClass='', $aClass='', $jsCommand='status') {
    $tasker = wire('modules')->get('Tasker');
    $tasks = $tasker->getTasks($selector);
    if (!count($tasks)) return '<p>No tasks found.</p>';

    // setting up a div field anchor for javascript
    // apiurl points to this module's api endpoint
    // timeout specifies the timeout in ms after which the JS routine will signal an error
    $out = '';

    // print out info about tasks and commands
    foreach ($tasks as $task) {
      $out .= $this->renderTaskStatus($task, $liClass, $aClass, $jsCommand);
    }

    $out = '<div id="tasker">'.$out."\n</div>\n";

    if ($jsCommand == 'run') { // show log messages if we're executing a single task
      $out .= "<div class='log NoticeMessages'></div>\n";
    }

    return $out;
  }

  /**
   * Render task status in HTML
   * 
   * @param $task Task Page object
   * @param $liClass optional attributes for <li> tags. If null <li> is omitted.
   * @param $aClass optional attributes for <a> tags. If null <a> is omitted.
   * @param $jsCommand optional command that the Javascript functions will execute over the Tasker API
   * @returns html string to output, '' if no tasks have been found, false on error
   */
  public function renderTaskStatus($task, $liClass='', $aClass='', $jsCommand='status') {
    $tasker = wire('modules')->get('Tasker');
    if ($task->isTrash()) return '';
    $out = "\n".'<div class="tasker-task" taskId="'.$task->id.'"';
    $logSummary = '';
    $taskState = $this->stateInfo[$task->task_state];
    $actions = array();

    // display info and set actions based on the task's state
    switch($task->task_state) {
    case Tasker::taskWaiting:
      $icon = 'fa-clock-o'; // TODO 'fa-hourglass' or 'fa-spinner' ?
      $actions = array('activate' => 'Activate');
      if ($this->enableWebRun) { // if Web-based task execution is enabled
        $actions['start'] = 'Run now';
      }
      if ($task->task_running) $taskState = 'preempting';
      break;
    case Tasker::taskActive:
      $icon = 'fa-rocket';
      $actions = array('suspend' => 'Deactivate');
      // if it is not already running and Web execution is enabled
      if (!$task->task_running && $this->enableWebRun) {
        if ($jsCommand == 'run') {
          $out .= ' run="1"';   // instruct the JS route to run the task
          $taskState = 'running';   // display the running state
        } else {
          $actions['start'] = 'Run now';  // add a run action
        }
      } else if ($task->task_running) $taskState = 'running';
      break;
    case Tasker::taskFinished:
      $icon = 'fa-check';
      $actions['reset'] = 'Reset';
      if ($jsCommand == 'run') $jsCommand='';  // clear the run command if the task is finished
      break;
    case Tasker::taskKilled:
      $icon = 'fa-hand-stop-o';
      if ($task->task_running) $taskState = 'killing';
      if ($jsCommand == 'run') $jsCommand='';  // clear the run command if the task is killed
      break;
    case Tasker::taskFailed:
      $icon = 'fa-warning';
      $actions = array('activate' => 'Try again');
      if ($jsCommand == 'run') $jsCommand='';  // clear the run command if the task is failed
      break;
    default:
      $icon = 'fa-question';
    }

    // Add a reset button if the task has made any progress
    if ($task->progress > 0) {
      $logSummary = ' ('.$tasker->getLogSummary($task, true, true).')';
      $actions['reset'] = 'Reset';
    }

    if (!$task->task_running) {
      // Any non-running task can be trashed
      $actions['trash'] = 'Trash';
    }

    // finish the starting div tag first then start building its inner html structure
    $out .= '>
        <i class="fa '.$icon.'"> '.$taskState.'</i>
        <i class="fa fa-angle-right"></i>
        <span class="TaskTitle" style="display: inline !important;">'.$task->title.$logSummary.'</span>
        <ul class="actions TaskActions">
        ';

    foreach ($actions as $cmd => $title) {
      if ($jsCommand == $cmd) continue; // skip the active command
      $out .= '<li style="display: inline !important;"'.$liClass.'>
               <span><a onclick="TaskerAction(\''.$task->id.'\', \''.$cmd.'\')"'.$aClass.'>'.$title."</a></span>\n</li>\n";
    }

    // insert a link to the task's edit page to show its details
    if ($task->editable()) {
      $out .= '<li style="display: inline !important;"'.$liClass.'><a href="'.$task->editUrl().'"'.$aClass.'>Details</a></li>';
    }

    $out .= "\n</ul>\n";

    if ($jsCommand == 'run') {  // if the task is running
      $out .= '<div class="progressbar"><div class="progress-label"></div></div>';
    }

    return $out . "\n</div>\n";
  }


/***********************************************************************
 * LOGGING AND UTILITY METHODS
 **********************************************************************/

  /**
   * Decompose an URI request
   * 
   * TODO: replace taskid/?x=command with command/?id=taskId
   * 
   * @param $request URI
   */
  public function analyzeRequestURI($request) {
    // match the module base URL / command / taskId ? arguments
    $uriparts = parse_url($request);
    $taskId = $command = false;
    $params = array();
    // TODO url segments?
    if (isset($uriparts['query'])) {
      parse_str($uriparts['query'], $query);
      foreach ($query as $key => $value) {
        if ($key == 'id') $taskId = $value;
        elseif ($key == 'cmd') $command = $value;
        else $params[$key] = urldecode($value);
      }
    }

    //$this->message("Command {$command} task {$taskId} with params ".print_r($params, true), Notice::debug);

    return array($command, $taskId, $params);
  }


  /**
   * Get notices
   * 
   * @return HTML-encoded list of system notices
   */
  public function getNotices() {
    $ret = '<ul class="NoticeMessages">';
    foreach(wire('notices') as $notice) {
      $class = $notice->className();
      $text = wire('sanitizer')->entities($notice->text);
      // $ret .= '<li class="'.$class.">$text</li>\n";
      $ret .= "<li>$text</li>\n";
    }
    $ret .= '</ul>';
    return $ret;
  }

}
