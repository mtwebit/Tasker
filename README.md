# Tasker
Task management for ProcessWire

## Purpose
This ProcessWire module allows the execution of long-running (>> max_execution_time) jobs in ProcessWire.  
It provides a simple interface to create tasks (stored as PW pages), to set and query their state (Active, Waiting, Suspended etc.), and to execute them via Cron, LazyCron or HTTP calls.  
The TaskerAdmin module provides a Javascript-based frontend to list tasks, to change their state and to monitor task progress (using a JQuery progressbar and a debug log area). It also allows the on-line execution of tasks using periodic HTTP calls performed by frontend Javascript code.

## How does it work
See the [Wiki](https://github.com/mtwebit/Tasker/wiki)

## Installation
Tasker requires a Task template type to store task data.  
Atm TODO this should be created manually with the following fields: title(Text), task_data(TextArea), task_state(Integer:0..10), task_running(Integer:0..1), progress(Integer:0..100), log_messages(TextArea) and signature(Text).  
These fields will be automagically managed by Tasker.

## Examples
The [DataSet](https://github.com/mtwebit/DataSet/) module performs long-running page imports and deletions using Tasker.  
Check createTasksOnPageSave(), import() and purge() for more details.  
  
The [MarkupPdfPager](https://github.com/mtwebit/MarkupPdfPager) module performs pdf transformations and indexing using Tasker.
