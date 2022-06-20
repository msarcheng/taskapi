<?php

require_once('db.php');
require_once('../model/Task.php');
require_once('../model/Response.php');

try {

    $writeDB = DB::connectWriteDb();
    $readDB = DB::connectReadDb();

} catch (PDOException $e) {
    error_log("Connection error - ".$ex, 0);
    $response = new Responses();
    $response->setHttpStatusCode(500)
        ->setSuccess(false)
        ->addMessage("Database connection error")
        ->send();
    exit;
}

if (array_key_exists("taskid", $_GET)) {

    $taskid = $_GET['taskid'];

    if ($taskid === '' || !is_numeric($taskid)) {
        $response = new Responses();
        $response->setHttpStatusCode(400)
            ->setSuccess(false)
            ->addMessage("Task ID cannot be blank or must be numeric")
            ->send();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare(
                'SELECT id,
                        title,
                        description,
                        DATE_FORMAT(deadline, "%d/%m/%Y %H:%i:%s") as deadline,
                        completed
                 FROM tbltasks
                 WHERE id = :taskid
                '
            );
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->execute();
            $rowCount = $query->rowCount();

            if ($rowCount === 0){
                $response = new Responses();
                $response->setHttpStatusCode(404)
                    ->setSuccess(false)
                    ->addMessage("Task not found.")
                    ->send();
                exit;
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task(
                    $row['id'],
                    $row['title'],
                    $row['description'],
                    $row['deadline'],
                    $row['completed']
                );
                $taskArray[] = $task->returnTaskAsArray();
            }

            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;

            //Response
            $response = new Responses();
            $response->setHttpStatusCode(200)
                ->setSuccess(true)
                ->toCache(true)
                ->setData($returnData)
                ->send();
            exit;


        } catch (PDOException $e) {
            error_log("Database error - ".$e, 0);
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage("Failed to get task.")
                ->send();
            exit;

        } catch (TaskException $te) {
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage($te->getMessage())
                ->send();
            exit;
        }


    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        try {
            $query = $writeDB->prepare('
                DELETE
                FROM tbltasks
                WHERE id = :taskid
            ');
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Responses();
                $response->setHttpStatusCode(404)
                    ->setSuccess(false)
                    ->addMessage("Task not found.")
                    ->send();
                exit;
            }

            //When successful
            $response = new Responses();
            $response->setHttpStatusCode(200)
                ->setSuccess(true)
                ->addMessage("Task deleted.")
                ->send();
            exit;

        } catch (PDOException $pe) {
            error_log("Database error - ".$e, 0);
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage("Failed to delete a task.")
                ->send();
            exit;
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

    } else {
        $response = new Responses();
        $response->setHttpStatusCode(405)
            ->setSuccess(false)
            ->addMessage("Request method not allowed.")
            ->send();
        exit;
    }

}