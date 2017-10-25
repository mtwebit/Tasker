# Tasker
Task management for ProcessWire

## Purpose
The module allows the execution of long-running jobs in ProcessWire.  
It provides a Javascript-based frontend to monitor their progress (using a JQuery progressbar and a debug log area), and a simple backend scheduler to create and execute them in steps that can be performed within PHP's max_execution_time() limit.  

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
Tasker can execute tasks using its Javascript frontend, LazyCron or host-based tools like Unix cron.  
It calculates the remaining execution time, sets the timeout value and calls the specified function.  
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
$taskData is a persistent storage for tasks during their execution. It may contain any kind of data that can be saved using json_encode().  
In order to calculate task progress you may want to use records_processed and max_records array members. You can estimate max_records somehow (e.g. by couting pages, file records etc.) and count records_processed during execution.  

### Monitoring tasks
Tasks have various states and Tasker performs state transitions according to their lifecycle.  
TaskerAdmin provides an admin interface for Tasker where you can monitor tasks, their state and progress, and you can also perform state transitions.  
You can also create a template page for tasks if you like and display their progress and data.

## Installation
Tasker requires a Task template type to store task data.
TODO atm this should be created manually with the following fields: title(Text), task_data(TextArea), task_state(Integer:0..10), task_running(Integer:0..1), progress(Integer:0..100) and signature(Text).
These fields will be automagically managed by Tasker.

## Advanced topics
### Task dependencies
You can specify execution dependencies between tasks. Simply add a 'dep' option when creating a task.  
```php
$params['dep'] = $othertask;
$task = $tasker->createTask($class, $method, $dictPage, 'Task depends on '.$othertask->title, $params);
```
### Reporting progress when max_exec_time is 0
When your PHP script can run forever (e.g. executed by Unix cron) you should report progress back to Tasker sometime.   
The Page object of the task (that is performed by your script) is available in 'task' element of $taskData.   
```php
if (!$options['timeout'] && ... some time passed / some records processed ... {
  $tasker->setProgress($taskData['task'], $taskData['progress']);
}
```
