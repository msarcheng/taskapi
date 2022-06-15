<?php

require_once('Task.php');

try {
    $task = new Task(
        1,
        "Title here",
        "Description",
        "01/01/2020 12:00",
        "N"
    );
    header('Content-type: application/json;charset=utf8');
    echo json_encode($task->returnTaskAsArray());

} catch (TaskException $e) {
    echo "Error: ".$e->getMessage();
}
