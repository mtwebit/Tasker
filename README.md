# Tasker
Task management for ProcessWire

## Purpose
The module allows the execution of long-running jobs in ProcessWire.  
It provides a Javascript-based frontend to monitor their progress, and a simple backend scheduler to create and execute them in steps that can be performed within PHP's max_execution_time() limit.  

## How does it work

### Creating a task
Instead of executing a long job (e.g. deleting a large number of pages) you can create a task.
```php
$tasker = wire('modules')->getModule('Tasker');
$task = $tasker->createTask($class, $method, $dictPage, 'Purge the dictionary');
```
With createTasks you specify the class and function to call when executing the task, its argument (e.g. the root page) and a human-readable title. Optionally you can also provide an array of arguments to the task.  
Tasker will create a hidden Task page below $dictPage and save all this info into it.  

### Executing a task
Tasker works using LazyCron or host-based tools like Unix cron.  
In every minute or so it checks the active tasks and selects one for execution. It calculates the remaining execution time, sets the timeout value and calls the specified function.
```php
$class->{$method}($dictPage, $taskData, $options)
```
where the $options array contains run-time configuration info like the time when execution should be stopped.

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
$taskData may contain any kind of data that can be saved using json_encode().  
In order to calculate task progress you may want to use maxRecordNumber and recordsProcessed array members, estimate maxRecordNumber somehow (e.g. by dry-running the task) and count recordsProcessed.  

### Monitoring tasks
Tasks also have various states and Tasker performs state transitions according to their lifecycle.  
TaskerAdmin provides an admin interface for Tasker where you can monitor tasks, their state and progress, and you can also perform state transitions.  
You can also create a template page for tasks if you like and display their progress and data.

## Installation
Tasker requires a Task template type to store task data.
TODO atm this should be created manually with the following fields: title(Text), task_data(TextArea), task_state(Integer:0..10), task_running(Integer:0..1), progress(Integer:0..100) and signature(Text).
