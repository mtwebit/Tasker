# Tasker
Task management for ProcessWire

## Purpose
The module allows the execution of long-running (>> max_execution_time) jobs in ProcessWire.  
It provides a simple interface to create tasks (stored as PW pages), to set and query their state (Active, Waiting, Suspended etc.), and to execute them via Cron, LazyCron or HTTP calls.  
The TaskerAdmin module provides a Javascript-based frontend to list tasks, to change their state and to monitor task progress (using a JQuery progressbar and a debug log area). It also allows the on-line execution of tasks using periodic HTTP calls performed by frontend Javascript code.

## How does it work

### Creating a task
Instead of executing a long job (e.g. creating a large number of pages during an import) you can create a task.
```php
$tasker = wire('modules')->getModule('Tasker');
$task = $tasker->createTask($class, $method, $page, 'Task title', $arguments);
```
With createTask you specify the class and function to call when executing the task, its page argument and a human-readable title. You can optionally provide an array of other parameters to the task.  
Tasker will create a hidden Task page below $page and save all this data into it.  

### Executing a task
After a task is created you can activate it.  
```php
$tasker->activateTask($task);
```
Tasker will automatically execute active tasks using one of its schedulers: Unix cron, LazyCron or TaskerAdmin's REST API + JS client.  
Cron executes tasks in one step (no time limit) the other two will break down long tasks into steps that can be fit into max_execution_time().  
The scheduler calculates the remaining execution time, sets a timeout value for the task and calls the specified function.  
```php
$class->{$method}($page, $taskData, $params);
```
where the $params array may contain run-time configuration info like the time when execution should be stopped.  

### Performing a task
Tasker provides a persistent storage for saving task's data: $taskData.  
Your method (that performs the task) should monitor its execution time and save its progress into $taskData before stopping.  
Example:
```php
public function longTask($page, &$taskData, $params) {
  $taskData['task_done'] = 0; // 0/1
  ...
  if ($options['timeout'] && $options['timeout'] <= time()) { // time is over
    $taskData['progress'] = 35;  // 0...100%
    return true;
  }
  ...
  if (... some error happens...) {
    $taskData['progress'] = 75;
    return false;
  }
  ...
  $taskData['progress'] = 100;
  $taskData['task_done'] = 1;
  return true;
```
$taskData may contain any kind of data that can be saved using json_encode(). For example, you can store an 'offset' member when processing a large file.  
In order to calculate task progress you may want to use 'records_processed' and 'max_records' array members. You can estimate 'max_records' somehow (e.g. by couting pages, file records etc.) and count 'records_processed' during execution.  
You should report progress back to Tasker periodically in order to update the progress bar on the UI.  
```php
if ($tasker && ($taskData['records_processed'] % 200)) {
  $taskData['progress'] = round(100 * $taskData['records_processed'] / $taskData['max_records'], 2);
  if (!$tasker->saveProgress($task, $taskData)) { // returns false if the task is no longer active
    $this->message('The task is no longer active.', Notice::debug);
    $taskData['task_done'] = 0;
    return true;
  }
```
In addition to saving task's progress the saveProgress() method also stores PW notices in the task's log and handles external events (like KILL signals). It will also reload the task's state from the database and return false if the task is no longer active (e.g. suspended by the user).

### Task lifecycle and states
Tasks have various states and Tasker performs state transitions according to their lifecycle.  
TaskerAdmin provides an admin interface for Tasker where you can monitor tasks, their state and progress, and you can also perform state transitions.  
Using the Tasker API or TaskerAdmin GUI you can activate, stop, suspend, reset, kill and trash tasks.

## Installation
Tasker requires a Task template type to store task data.  
Atm TODO this should be created manually with the following fields: title(Text), task_data(TextArea), task_state(Integer:0..10), task_running(Integer:0..1), progress(Integer:0..100), log(TextArea) and signature(Text).  
These fields will be automagically managed by Tasker.  
You can also create a template for task pages and display them if you like.

## Examples
The [DictionarySupport](https://github.com/mtwebit/DictionarySupport/) module performs long-running imports and deletions using Tasker.  
Check createTasksOnPageSave(), import() and purge() for more details.

## Advanced topics
### Task dependencies
You can specify execution dependencies between tasks. Simply add a 'dep' option when creating a task.  
```php
$params['dep'] = $othertask; // you can also add 
$task = $tasker->createTask($class, $method, $page, 'This task depends on '.$othertask->title, $params);
```
or
```php
$tasker->addDependency($task, $otherTask);
```
Dependencies are automatically handled by Tasker. You cannot activate a task when they are not met.  

It is also possible to specify follow-up tasks that will be activated when the task is finished.  
```php
$tasker->addNextTask($task, $nextTask);
```
Follow-up tasks (IDs) are stored in $taskData['next_task'].

### Ignoring time limit
Task may decide to ignore max time limit.
TODO handle these with PHP's builtin error handlers.

### Testing and debugging tasks
You should test your task using TaskerAdmin's Javascript execution and monitoring frontend.  
When a task fails to execute you can query the JSON API directly by adding "api/" to the end of the TaskerAdmin URL (and before the id and cmd arguments). Example:
```
https://example.org/processwire/page/tasks/api?id=60343&cmd=exec
```
ProcessWire notices are saved into $task->log.  
If a task function returns false Tasker will set the taks status to Failed.
