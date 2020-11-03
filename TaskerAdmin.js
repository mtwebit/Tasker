/*
 * Tasker module - javascript frontend
 * 
 * Performs API calls to query task status / execute command etc.
 * 
 * Copyright 2017-2020 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

// Execute a command on a given task using backend API calls
function TaskerAction(taskId, command) {
  taskerAdminAPIUrl = ProcessWire.config.tasker.apiUrl,
  taskdiv = $("div[taskid='" + taskId + "']"),
  taskdiv.children("ul.actions").hide();
  taskdiv.children("i.fa").hide();
  taskdiv.children('span.TaskTitle').prepend('<b>Calling ' + command + ':</b> ');

  performAPICall(taskerAdminAPIUrl + '/?id=' + taskId + '&cmd=' + command, statusCallback, taskdiv);
}

// perform a HTTP request to the TaskerAdmin API and display the results
function performAPICall(url, callback, taskdiv) {
  var msgText = taskdiv.children("span.TaskTitle"),
      timeout = ProcessWire.config.tasker.timeout + 500, // add some extra time
      unloading = false;

  // signal if the user is leaving the page
  $(window).bind('beforeunload', function() { unloading = true; });

  // send the HTTP request
  $.ajax({
    dataType: "json",
    url: url,
    success: callback,
    timeout: timeout,
    error: function(jqXHR, status, errorThrown) {
      taskdiv.children("ul.actions").remove(); // remove the action buttons on error
      if (status == 'timeout') {
        msgText.append(" <b>ERROR:</b> Request timeout. Please check the backend for more info.");
      } else if (unloading) {
        msgText.append(" Cancelling request...");
      } else {
        msgText.append(" <b>ERROR:</b> Invalid response from the server: " + status);
      }
    }
  });
}

// callback for HTTP requests
function statusCallback(data) {
  var taskId = data['taskid'],
      taskdiv = $("div[taskid='" + taskId + "']"),
      msgText = taskdiv.children("span.TaskTitle"),
      progressbar = taskdiv.children("div.progressbar");

  if (data['status_html'] == '') {
    // Task not found (e.g. trashed), remove it from the tasklist
    taskdiv.remove();
    return;
  }

  // replace the task div with the new HTML content
  taskdiv.replaceWith(data['status_html']);

  if (!data['status']) { // return status is not OK
    if (progressbar.length) progressbar.remove();
    return;
  }

  // update the variables from the newly received content
  taskdiv = $("div[taskid='" + taskId + "']");
  progressbar = taskdiv.children("div.progressbar");

  if (!progressbar.length) return;

  run = taskdiv.attr('run'),
  repeatTime = taskdiv.attr('repeatTime');

  progressLabel = progressbar.children('div.progress-label');

  // initialize a progressbar for the task
  progressbar.progressbar({
    value: false,
    change: function() {
      progressLabel.text(progressbar.progressbar( "value" ) + "% done.");
    },
  });

  progressLabel.text("Querying task's status...");

  progressbar.progressbar("value", data['progress']);

// TODO don't execute the task if it is stopped by another request

  if (run) { // we're executing the task and it is still active
    if (repeatTime > 0) {
      setTimeout(function() {
        performAPICall(ProcessWire.config.tasker.apiUrl + '/?cmd=run&id=' + taskId, statusCallback, taskdiv);
      }, 1000 * Number(repeatTime));
    } else {
      performAPICall(ProcessWire.config.tasker.apiUrl + '/?cmd=run&id=' + taskId, statusCallback, taskdiv);
    }
  }
}
