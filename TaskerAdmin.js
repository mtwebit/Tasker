/*
 * Tasker module - javascript frontend
 * 
 * Performs API calls to query task status / execute command etc.
 * 
 * Copyright 2017-2019 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

/*
$(document).ready(function() {
  if ( $("#tasker").length) tasker_client();
});

function tasker_client() {
  tasker = $("#tasker");

  // for each running task create and display a progressbar
  tasker.children('div[run]').each(function() {
    var progressbar = $(this).children('div.progress-bar').first(),
        progressLabel = progressbar.children('div').first(),
        taskId = $(this).attr('taskid');

    if (!progressbar) return;

    // create a progressbar for the task
    progressbar.progressbar({
      value: false,
      change: function() {
        progressLabel.text(progressbar.progressbar( "value" ) + "% done.");
      },
    });

    // call the API with the specified command
    progressLabel.text("Querying status...");
    performApiCall(ProcessWire.config.tasker.apiUrl + '/?cmd=run&id=' + taskId, statusCallback, progressbar);
  });
}
*/

// Execute a command on a given task using backend API calls
function TaskerAction(taskId, command) {
  taskerAdminApiUrl = ProcessWire.config.tasker.apiUrl,
  taskdiv = $("div[taskid='" + taskId + "']"),
  taskdiv.children("ul.actions").hide();
  taskdiv.children("i.fa").hide();
  taskdiv.children('span.TaskTitle').prepend('<b>Calling ' + command + ':</b> ');
  performApiCall(taskerAdminApiUrl + '/?id=' + taskId + '&cmd=' + command, statusCallback);
}

// perform a HTTP AJAX (JSON) request and handle errors if necessary
function performApiCall(url, callback, progressbar) {
  var debuglog = $("#tasker").next("div.NoticeMessages"),
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
      var progressLabel = progressbar.children('div').first(),
      taskdiv = progressbar.parent();
      taskdiv.children("ul.actions").remove(); // remove the action buttons on error
      if (status == 'timeout') {
        progressLabel.text("Request timeout. Please check the backend for more info.");
      } else if (unloading) {
        progressLabel.text("Cancelling request...");
      } else {
        progressLabel.text("Error receiving response from the server: " + status);
      }
    }
  });
}

// callback for HTTP requests
function statusCallback(data) {
  var taskId = data['taskid'],
      debuglog = $("#tasker").next("div.NoticeMessages"),
      taskdiv = $("div[taskid='" + taskId + "']"),
      progressbar = $("div[taskid='" + taskId + "'] > div"),
      progressLabel = progressbar.children('div').first(),
      run = taskdiv.attr('run'),
      repeatTime = taskdiv.attr('repeatTime');

  if (!data['status']) { // return status is not OK
    progressbar.progressbar("value", false);
    progressLabel.text("Error: " + data["result"]);
    if (debuglog.length) debuglog.append(data['log']);
    taskdiv.children("span.TaskTitle").append(' <b>ERROR: Command failed</b>');
    return;
  }

  if (data['status_html'] == '') {
    // Task not found (e.g. trashed), remove it from the tasklist
    taskdiv.remove();
    return;
  }

  // replace the task div with the new html code
  taskdiv.replaceWith(data['status_html']);

  if (debuglog.length) {
    debuglog.html(data['log']);
  }

  if (!progressbar) return;

  // create a progressbar for the task
  progressbar.progressbar({
    value: false,
    change: function() {
      progressLabel.text(progressbar.progressbar( "value" ) + "% done.");
    },
  });

  progressbar.progressbar("value", data['progress']);

  if (run) { // we're executing the task
    if (repeatTime > 0) {
      setTimeout(function() {
        performApiCall(ProcessWire.config.tasker.apiUrl + '/?cmd=run&id=' + taskId, statusCallback, progressbar);
      }, 1000 * Number(repeatTime));
    } else {
      performApiCall(ProcessWire.config.tasker.apiUrl + '/?cmd=run&id=' + taskId, statusCallback, progressbar);
    }
  }
}
