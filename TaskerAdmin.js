/*
 * Tasker module - javascript frontend
 * 
 * Performs periodic API calls to query task status / execute a task.
 * 
 * Copyright 2017 Tamas Meszaros <mt+github@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */


$(document).ready(function() {
  if ( $("#tasker").length) tasker_client();
});

function tasker_client() {
  var tasker = $("#tasker"),
      adminUrl = tasker.attr("adminurl");

  // for each active task
  tasker.children('div').each(function() {
    var progressbar = $(this).children('div').first(),
        progressLabel = progressbar.children('div').first(),
        taskId = $(this).attr('taskid'),
        command = $(this).attr('command');

    // create a progressbar for the task
    progressbar.progressbar({
      value: false,
      change: function() {
        progressLabel.text(progressbar.progressbar( "value" ) + "% done.");
      },
    });

    // call the API with the specified command
    progressLabel.text("Starting up...");
    performApiCall(adminUrl + '/?cmd=' + command + '&id=' + taskId, statusCallback, progressbar);
  });

  // callback for HTTP calls
  function statusCallback(data) {
    var taskId = data['taskid'],
        debuglog = $("#tasker").next("div.NoticeMessages"),
        taskdiv = $("#tasker > div[taskid='" + taskId + "']"),
        progressbar = $("#tasker > div[taskid='" + taskId + "'] > div"),
        progressLabel = progressbar.children('div').first(),
        command = taskdiv.attr('command'),
        repeatTime = taskdiv.attr('repeatTime');

    if (data['status']) { // return status is OK
      if (debuglog.length) {
        debuglog.html(data['log']);
      }
      progressbar.progressbar("value", data['progress']);
      if (data['task_state'] == 1) { // the task is active
        // if requested wait a little bit before executing the next task status request
        if (command == 'status' && repeatTime > 0) setTimeout(
          function() {
            performApiCall(adminUrl + '/?cmd=' + command + '&id=' + taskId, statusCallback, progressbar);
          }, 1000 * Number(repeatTime));
        else {
          performApiCall(adminUrl + '/?cmd=' + command + '&id=' + taskId, statusCallback, progressbar);
        }
      } else {
        taskdiv.children("i.fa").remove();
        taskdiv.children("ul.actions").remove(); // remove the action buttons on error
        progressLabel.text("The task is " + data['task_state_info'] + ".");
      }
    } else { // return status is not OK
      progressbar.progressbar("value", false);
      progressLabel.text("Error: " + data["result"]);
      if (debuglog.length) debuglog.append("ERROR: " + data['log']);
    }
  }
}

// perform a HTTP AJAX (JSON) request and handle errors if necessary
function performApiCall(url, callback, progressbar) {
  var debuglog = $("#tasker").next("div.NoticeMessages"),
      timeout = $("#tasker").attr('timeout'),
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
