# Tasker
Task management for ProcessWire

## Purpose
The module allows the execution of long-running jobs in ProcessWire using a simple backend scheduler (powered by LazyCron or Unix cron). It executes long tasks in steps that can be performed within PHP's max_execution_time() limit.  
The TaskerAdmin module provides a Javascript-based frontend to monitor task progress (using a JQuery progressbar and a debug log area). It also allows the on-line execution of tasks using periodic backend calls performed by frontend Javascript code.

## How does it work

### Creating a task
Instead of executing a long job (e.g. deleting a large number of pages) you can create a task.
```php
$tasker = wire('modules')->getModule('Tasker');
$task = $tasker->createTask($class, $method, $page, 'Task title', $arguments);
```
With createTask you specify the class and function to call when executing the task, its page argument and a human-readable title. After the title you can optionally provide an array of other arguments to the task and to Tasker.  
Tasker will create a hidden Task page below $page and save all this data into it.  

### Executing a task
Tasker can execute tasks using its Javascript frontend (TaskerAdmin), LazyCron or host-based tools like Unix cron.  
It selects the next task that is ready to run, calculates the remaining execution time, sets the timeout value and calls the specified function.  
```php
$class->{$method}($dictPage, $taskData, $params);
```
where the $params array may contain run-time configuration info like the time when execution should be stopped.  

### Performing a task
Your method (that performs the task) should monitor its execution time and save its progress into $taskData before stopping.  
It can report progress back using 'task_done' and 'progress' elements in $taskData.  
```php
public function purgeDictionary($dictPage, &$taskData, $options) {
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
$taskData is a persistent storage for tasks during their execution. It may contain any kind of data that can be saved using json_encode(). For example, you can store the task's progress in an 'offset' member if needed.  
In order to calculate task progress you may want to use records_processed and max_records array members. You can estimate max_records somehow (e.g. by couting pages, file records etc.) and count records_processed during execution.  

### Monitoring tasks
Tasks have various states and Tasker performs state transitions according to their lifecycle.  
TaskerAdmin provides an admin interface for Tasker where you can monitor tasks, their state and progress, and you can also perform state transitions.  
You can also create a template page for tasks if you like and display their progress and data.

## Installation
Tasker requires a Task template type to store task data.
TODO atm this should be created manually with the following fields: title(Text), task_data(TextArea), task_state(Integer:0..10), task_running(Integer:0..1), progress(Integer:0..100) and signature(Text).
These fields will be automagically managed by Tasker.

## Examples
The [DictionarySupport](https://github.com/mtwebit/DictionarySupport/) module performs long-running imports and deletions using Tasker.

## Advanced topics
### Task dependencies
You can specify execution dependencies between tasks. Simply add a 'dep' option when creating a task.  
```php
$params['dep'] = $othertask;
$task = $tasker->createTask($class, $method, $dictPage, 'Task depends on '.$othertask->title, $params);
```
### Ignoring time limit
Task may decide to ignore max time limit.
TODO handle these with PHP's builtin error handlers.

### Reporting progress when max_exec_time is 0
When your task can run forever (e.g. executed by Unix cron) you should report its progress back to Tasker.   
```php
if (!$options['timeout'] && ... some time passed / some records processed ... {
  $tasker->setProgress($taskData['task'], $taskData['progress']);
}
```
### Testing and debugging tasks
You should test your task using TaskerAdmin's Javascript execution and monitoring frontend.  
When a task fails to execute you can query the JSON API directly by adding "api/" to the end of the TaskerAdmin URL (and before the id and cmd arguments). Example:
```
https://example.org/processwire/page/tasks/api?id=60343&cmd=exec
```
