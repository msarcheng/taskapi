<?php

require_once('db.php');
require_once('../model/Task.php');
require_once('../model/Response.php');
require_once('../model/image.php');

function retrieveTaskImages(
    $dbConn,
    $taskid,
    $returned_userid
) {
    $imageQuery = $dbConn->prepare(
        'SELECT a.id AS id,
                a.title AS title,
                a.filename AS filename,
                a.mimetype AS mimetype,
                a.taskid AS taskid
         FROM tblimages a
         INNER JOIN tbltasks b
            ON a.taskid = b.id
         WHERE b.id = :taskid
         AND b.userid = :userid'
    );
    $imageQuery->bindParam(":taskid", $taskid, PDO::PARAM_INT);
    $imageQuery->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
    $imageQuery->execute();

    $imageArrays = [];
    while ($row = $imageQuery->fetch(PDO::FETCH_ASSOC)) {
        $image = new Image(
            $row['id'],
            $row['title'],
            $row['filename'],
            $row['mimetype'],
            $row['taskid']
        );
        $imageArrays[] = $image->returnImageAsArray();
    }

    return $imageArrays;
}

try {
    $writeDB = DB::connectWriteDb();
    $readDB = DB::connectReadDb();
} catch (PDOException $px) {
    error_log("Connection error - " . $px, 0);
    $response = new Responses();
    $response->setHttpStatusCode(500)
        ->setSuccess(false)
        ->addMessage("Database connection error")
        ->send();
    exit;
}

/**
 * Begin Auth Script
 * get http token from header
 */
$httpAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_AUTHORIZATION'] = '';
if (
    !isset($_SERVER['HTTP_AUTHORIZATION'])
    || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1
) {
    $response = new Responses();
    $response->setHttpStatusCode(401)
        ->setSuccess(false);
    (!isset($httpAuth) ? $response->addMessage("Access token missing from the header") : false);
    (empty($httpAuth) ? $response->addMessage("Access token is in the header but not supplied") : false);
    (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access token cannot be blank") : false);
    $response->send();
    exit;
}

$accessTokenAuth = $_SERVER['HTTP_AUTHORIZATION'];

try {
    $query = $writeDB->prepare(
        'SELECT a.userid AS userid,
                a.accesstokenexpiry AS accesstokenexpiry,
                b.useractive AS useractive,
                b.loginattempts AS loginattempts
         FROM tblsessions a
         JOIN tblusers b
            ON a.userid = b.id
         WHERE a.accesstoken = :accesstoken'
    );
    $query->bindParam(':accesstoken', $accessTokenAuth, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount === 0) {
        $response = new Responses();
        $response->setHttpStatusCode(401)
            ->setSuccess(false)
            ->addMessage("Invalid Access Token")
            ->send();
        exit;
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_userid = $row['userid'];
    $returned_accesstokenexpiry = $row['accesstokenexpiry'];
    $returned_useractive = $row['useractive'];
    $returned_loginattempts = $row['loginattempts'];

    //Check if user is still active
    if ($returned_useractive !== 'Y') {
        $response = new Responses();
        $response->setHttpStatusCode(401)
            ->setSuccess(false)
            ->addMessage("User account not active")
            ->send();
        exit;
    }

    //Check if user has been locked out
    if ($returned_loginattempts >= 3) {
        $response = new Responses();
        $response->setHttpStatusCode(401)
            ->setSuccess(false)
            ->addMessage("User account is currently locked out")
            ->send();
        exit;
    }

    //Check if expiry time of refreshtoken is less than database time
    if (strtotime($returned_accesstokenexpiry) < time()) {
        $response = new Responses();
        $response->setHttpStatusCode(401)
            ->setSuccess(false)
            ->addMessage("Access token expired")
            ->send();
        exit;
    }
} catch (PDOException $px) {
    $response = new Responses();
    $response->setHttpStatusCode(500)
        ->setSuccess(false)
        ->addMessage("There was an issue authenticating - please try again")
        ->send();
    exit;
}

//  End of Auth script

if (array_key_exists("taskid", $_GET)) {

    $taskid = $_GET['taskid'];  //GET taskid
    if ($taskid === '' || !is_numeric($taskid)) {
        $response = new Responses();
        $response->setHttpStatusCode(400)
            ->setSuccess(false)
            ->addMessage("Task ID cannot be blank or must be numeric")
            ->send();
        exit;
    }

    //Handle options request method for CORS
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        $response = new Responses();
        $response->setHttpStatusCode(200)
            ->setSuccess(true)
            ->send();
        exit;
    }

    //Get single task
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare(
                'SELECT
                    id,
                    title,
                    description,
                    DATE_FORMAT(
                        deadline,
                        "%d/%m/%Y %H:%i:%s"
                    ) as deadline,
                    completed
                 FROM
                    tbltasks
                 WHERE
                    id = :taskid
                 AND
                    userid = :userid'
            );
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
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

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $imageArrays = retrieveTaskImages($readDB, $taskid, $returned_userid);

                $task = new Task(
                    $row['id'],
                    $row['title'],
                    $row['description'],
                    $row['deadline'],
                    $row['completed'],
                    $imageArrays
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
            error_log("Database error - " . $e, 0);
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
        } catch (ImageException $ie) {
            sendResponse(500, false, $ie->getMessage());
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

        //Delete a task
        try {
            $imageSelectQuery = $readDB->prepare(
                'SELECT a.id AS id,
                        a.title AS title,
                        a.filename AS filename,
                        a.mimetype AS mimetype,
                        a.taskid AS taskid
                FROM tblimages a
                INNER JOIN tbltasks b
                    ON a.taskid = b.id
                WHERE b.id = :taskid
                AND b.userid = :userid'
            );
            $imageSelectQuery->bindParam(":taskid", $taskid, PDO::PARAM_INT);
            $imageSelectQuery->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
            $imageSelectQuery->execute();

            while ($imageRow = $imageSelectQuery->fetch(PDO::FETCH_ASSOC)) {
                $writeDB->beginTransaction();
                $image = new Image(
                    $imageRow['id'],
                    $imageRow['title'],
                    $imageRow['filename'],
                    $imageRow['mimetype'],
                    $imageRow['taskid'],
                );
                $imageID = $image->getId();

                $query = $writeDB->prepare(
                    'DELETE tblimages
                     FROM tblimages
                     INNER JOIN tbltasks
                      ON tblimages.taskid = tbltasks.id
                     WHERE tblimages.id = :imageid
                        AND tblimages.taskid = :taskid
                        AND tbltasks.userid = :userid'
                );
                $query->bindParam(":imageid", $imageID, PDO::PARAM_INT);
                $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
                $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
                $query->execute();

                $image->deleteImageFile();

                $writeDB->commit();
            }

            $query = $writeDB->prepare(
                'DELETE FROM tbltasks
                 WHERE id = :taskid
                 AND userid = :userid'
            );
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
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

            //After the data deletion, we need to delete the task folder also.
            $taskImageFolder = "../../../taskimages/" . $taskid;

            if (is_dir($taskImageFolder)) {
                rmdir($taskImageFolder);    //Remove the folder if it exists
            }

            //When successful
            $response = new Responses();
            $response->setHttpStatusCode(200)
                ->setSuccess(true)
                ->addMessage("Task deleted.")
                ->send();
            exit;
        } catch (ImageException $ie) {
            if (!$writeDB->inTransaction()) {
                $writeDB->rollBack();
            }

            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage($ie->getMessage())
                ->send();
            exit;

        } catch (PDOException $pe) {
            if (!$writeDB->inTransaction()) {
                $writeDB->rollBack();
            }

            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage("Failed to delete a task.")
                ->send();
            exit;
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        try {

            //Trigger error if wrong content
            // if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
            //     $response = new Responses();
            //     $response->setHttpStatusCode(400)
            //         ->setSuccess(false)
            //         ->addMessage("Content type error is not set to JSON")
            //         ->send();
            //     exit;
            // }

            $rawPatchData = file_get_contents('php://input');

            if (!$jsonData = json_decode($rawPatchData)) {
                $response = new Responses();
                $response->setHttpStatusCode(400)
                    ->setSuccess(false)
                    ->addMessage("Request body is not set to JSON")
                    ->send();
                exit;
            }

            $title_updated = false;
            $description_updated = false;
            $deadline_updated = false;
            $completed_updated = false;

            $queryFields = "";

            if (isset($jsonData->title)) {
                $title_updated = true;
                $queryFields .= "title = :title, ";
            }

            if (isset($jsonData->description)) {
                $description_updated = true;
                $queryFields .= "description = :description, ";
            }

            if (isset($jsonData->deadline)) {
                $deadline_updated = true;
                $queryFields .= "deadline = STR_TO_DATE(:deadline, '%d/%m/Y %H:%i:s'), ";
            }

            if (isset($jsonData->completed)) {
                $completed_updated = true;
                $queryFields .= "completed = :completed, ";
            }

            $queryFields = rtrim($queryFields, ", "); //Removed the last comma

            //Check if params are false
            if (
                $title_updated === false
                && $description_updated === false
                && $deadline_updated === false
                && $completed_updated === false
            ) {
                $response = new Responses();
                $response->setHttpStatusCode(400)
                    ->setSuccess(false)
                    ->addMessage("No task fields provided.")
                    ->send();
                exit;
            }

            //Retrieve the updated task
            $query = $writeDB->prepare(
                'SELECT id,
                        title,
                        description,
                        DATE_FORMAT(
                            deadline,
                            "%d/%m/%Y %H:%i:%s"
                        ) as deadline,
                        completed
                 FROM tbltasks
                 WHERE id = :taskid
                    AND userid = :userid'
            );
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();
            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Responses();
                $response->setHttpStatusCode(404)
                    ->setSuccess(false)
                    ->addMessage("Task not found to update.")
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
            }

            // $queryString = "UPDATE tbltasks SET ".$queryFields." WHERE id = :taskid AND userid = :userid";
            $query = $writeDB->prepare(
                "UPDATE tbltasks SET " . $queryFields . " WHERE id = :taskid AND userid = :userid"
            );

            if ($title_updated == true) {
                $task->setTitle($jsonData->title);
                $up_title = $task->getTitle();
                $query->bindParam(':title', $up_title, PDO::PARAM_STR);
            }

            if ($description_updated === true) {
                $task->setDescription($jsonData->description);
                $up_description = $task->getDescription();
                $query->bindParam(':description', $up_description, PDO::PARAM_STR);
            }

            if ($deadline_updated === true) {
                $task->setDeadline($jsonData->deadline);
                $up_deadline = $task->getDeadline();
                $query->bindParam(':deadline', $up_deadline, PDO::PARAM_STR);
            }

            if ($completed_updated === true) {
                $task->setCompleted($jsonData->completed);
                $up_completed = $task->getCompleted();
                $query->bindParam(':completed', $up_completed, PDO::PARAM_STR);
            }

            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Responses();
                $response->setHttpStatusCode(400)
                    ->setSuccess(false)
                    ->addMessage("Task not updated.")
                    ->send();
                exit;
            }

            $query = $writeDB->prepare(
                'SELECT
                    id,
                    title,
                    description,
                    DATE_FORMAT(
                        deadline,
                        "%d/%m/%Y %H:%i:%s"
                    ) as deadline,
                    completed
                 FROM
                    tbltasks
                 WHERE
                    id = :taskid
                 AND
                    userid = :userid'
            );
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();
            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Responses();
                $response->setHttpStatusCode(404)
                    ->setSuccess(false)
                    ->addMessage("No Task found after updated.")
                    ->send();
                exit;
            }

            $taskArray = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {

                $imageArrays = retrieveTaskImages($writeDB, $row['id'], $returned_userid);
                $task = new Task(
                    $row['id'],
                    $row['title'],
                    $row['description'],
                    $row['deadline'],
                    $row['completed'],
                    $imageArrays
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
                ->addMessage("Task updated")
                ->setData($returnData)
                ->send();
            exit;
        } catch (PDOException $e) {
            error_log("Database error - " . $e, 0);
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage("Failed to update task." . $e)
                ->send();
            exit;
        } catch (TaskException $te) {
            $response = new Responses();
            $response->setHttpStatusCode(400)
                ->setSuccess(false)
                ->addMessage($te->getMessage())
                ->send();
            exit;
        } catch (ImageException $ie) {
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage($ie->getMessage())
                ->send();
            exit;
        }
    } else {
        $response = new Responses();
        $response->setHttpStatusCode(405)
            ->setSuccess(false)
            ->addMessage("Request method not allowed.")
            ->send();
        exit;
    }
} elseif (array_key_exists("completed", $_GET)) {

    /**
     * route : /v0/tasks/complete       : v0/task.php?completed=Y
     *         /v0/tasks/incomplete     : v0/task.php?completed=N
     */
    $completed = $_GET['completed'];

    /**
     * completed must be Y or N
     */
    if (
        $completed !== 'Y'
        && $completed !== 'N'
    ) {
        $response = new Responses();
        $response->setHttpStatusCode(400)
            ->setSuccess(false)
            ->addMessage("Completed fitler must be Y or N")
            ->send();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare(
                'SELECT
                    id,
                    title,
                    description,
                    DATE_FORMAT(
                        deadline,
                        "%d/%m/%Y %H:%i:%s"
                    ) AS deadline,
                    completed
                FROM
                    tbltasks
                WHERE
                    completed = :completed
                AND
                    userid = :userid'
            );
            $query->bindParam(':completed', $completed, PDO::PARAM_STR);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taksArray = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $imageArray = retrieveTaskImages($readDB, $row['id'], $returned_userid);
                $task = new Task(
                    $row['id'],
                    $row['title'],
                    $row['description'],
                    $row['deadline'],
                    $row['completed'],
                    $imageArray
                );
                $taskArray[] = $task->returnTaskAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray ?? ["Nothing is completed yet"];

            //Response
            $response = new Responses();
            $response->setHttpStatusCode(200)
                ->setSuccess(true)
                ->toCache(true)
                ->setData($returnData)
                ->send();
            exit;
        } catch (PDOException $pe) {
            error_log("Database query error - " . $pe, 0);
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage("Failed to get tasks")
                ->send();
            exit;
        } catch (TaskException $te) {
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage($te->getMessage())
                ->send();
            exit;
        } catch (ImageException $ie) {
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage($ie->getMessage())
                ->send();
            exit;
        }
    } else {
        $response = new Responses();
        $response->setHttpStatusCode(405)
            ->setSuccess(false)
            ->addMessage("Request method not allowed")
            ->send();
        exit;
    }
} elseif (array_key_exists("page", $_GET)) {
    //Paginated output of task
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = $_GET['page']; //task.php?page=1

        if (
            empty($page)
            || !is_numeric($page)
        ) {
            $response = new Responses();
            $response->setHttpStatusCode(400)
                ->setSuccess(false)
                ->addMessage("Page number cannot be blank and must be numeric")
                ->send();
            exit;
        }

        $limitPerPage = 20; //Output limit.

        try {
            $query = $readDB->prepare(
                'SELECT COUNT(id) AS totalTasks
                 FROM tbltasks
                 WHERE userid = :userid'
            );
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $row = $query->fetch(PDO::FETCH_ASSOC);
            $taskCount = intval($row['totalTasks']);

            $numOfPages = ceil($taskCount / $limitPerPage);

            /**
             * We cannot display zero pages
             * we needed to display no task
             */
            if ($numOfPages === 0) {
                $numOfPages = 1;
            }

            /**
             * If the requested page is not in the number of pages then
             * show page not found.
             */
            if (
                $page > $numOfPages
                || $page === 0
            ) {
                $response = new Responses();
                $response->setHttpStatusCode(404)
                    ->setSuccess(false)
                    ->addMessage("Page not found")
                    ->send();
                exit;
            }

            /**
             * Offset
             */
            $offset = ($page == 1 ? 0 : ($limitPerPage * ($page - 1)));

            $query = $readDB->prepare(
                'SELECT id,
                        title,
                        description,
                        DATE_FORMAT(
                            deadline,
                            "%d/%m/%Y %H:%i:%s"
                        ) AS deadline,
                        completed
                 FROM tbltasks
                 WHERE userid = :userid
                 LIMIT :pglimit
                 OFFSET :offsets'
            );
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':pglimit', $limitPerPage, PDO::PARAM_INT);
            $query->bindParam(':offsets', $offset, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taskArray = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $imageArray = retrieveTaskImages($readDB, $row['id'], $returned_userid);
                $task = new Task(
                    $row['id'],
                    $row['title'],
                    $row['description'],
                    $row['deadline'],
                    $row['completed'],
                    $imageArray
                );
                $taskArray[] = $task->returnTaskAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['total_rows'] = $taskCount;
            $returnData['total_pages'] = $numOfPages;

            ($page < $numOfPages ? $returnData['has_next_page'] = true : $returnData['has_next_page'] = false);
            ($page > 1 ? $returnData['has_previous_page'] = true : $returnData['has_previous_page'] = false);

            $returnData['tasks'] = $taskArray;

            //Response
            $response = new Responses();
            $response->setHttpStatusCode(200)
                ->setSuccess(true)
                ->toCache(true)
                ->setData($returnData)
                ->send();
            exit;
        } catch (PDOException $pe) {
            error_log("Database query error - " . $pe, 0);
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage("Failed to get tasks")
                ->send();
            exit;
        } catch (TaskException $te) {
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage($te->getMessage())
                ->send();
            exit;
        } catch (ImageException $ie) {
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage($ie->getMessage())
                ->send();
            exit;
        }
    } else {
        $response = new Responses();
        $response->setHttpStatusCode(405)
            ->setSuccess(false)
            ->addMessage("Request method not found")
            ->send();
        exit;
    }
} elseif (empty($_GET)) {
    //tasks
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare(
                'SELECT
                        id,
                        title,
                        description,
                        DATE_FORMAT(
                            deadline,
                            "%d/%m/%Y %H:%i:%s"
                        ) AS deadline,
                        completed
                    FROM
                        tbltasks
                    WHERE userid = :userid'
            );
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taksArray = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $imageArray = retrieveTaskImages($readDB, $row['id'], $returned_userid);
                $task = new Task(
                    $row['id'],
                    $row['title'],
                    $row['description'],
                    $row['deadline'],
                    $row['completed'],
                    $imageArray
                );
                $taskArray[] = $task->returnTaskAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray ?? [];

            //Response
            $response = new Responses();
            $response->setHttpStatusCode(200)
                ->setSuccess(true)
                ->toCache(true)
                ->setData($returnData)
                ->send();
            exit;
        } catch (PDOException $pe) {
            error_log("Database query error - " . $pe, 0);
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage("Failed to get task")
                ->send();
            exit;
        } catch (TaskException $te) {
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage($te->getMessage())
                ->send();
            exit;
        } catch (ImageException $ie) {
            sendResponse(500, false, $ie->getMessage());
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            //Trigger error if wrong content
            // if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
            //     $response = new Responses();
            //     $response->setHttpStatusCode(400)
            //         ->setSuccess(false)
            //         ->addMessage("Content type error is not set to JSON")
            //         ->send();
            //     exit;
            // }

            //read the content of the input of the body being sent
            $rawPostData = file_get_contents('php://input');

            //Decode the data if its a valid JSON.
            if (!$jsonData = json_decode($rawPostData)) {
                //Trigger if data is not in JSON format.
                $response = new Responses();
                $response->setHttpStatusCode(400)
                    ->setSuccess(false)
                    ->addMessage("Request body is not valid JSON")
                    ->send();
                exit;
            }

            //Check if data exists
            if (
                !isset($jsonData->title)
                || !isset($jsonData->completed)
            ) {
                $response = new Responses();
                $response->setHttpStatusCode(400)
                    ->setSuccess(false);
                (!isset($jsonData->title) ? $response->addMessage("Title field is mandatory and must be provided") : false);
                (!isset($jsonData->completed) ? $response->addMessage("Completed field is mandatory and must be provided") : false);
                $response->send();
                exit;
            }

            $newTask = new Task(
                null,
                $jsonData->title,
                (isset($jsonData->description) ? $jsonData->description : null),
                (isset($jsonData->deadline) ? $jsonData->deadline : null),
                (isset($jsonData->completed) ? $jsonData->completed : null)
            );

            $title = $newTask->getTitle();
            $description = $newTask->getDescription();
            $deadline = $newTask->getDeadline();
            $completed = $newTask->getCompleted();

            //Create the query.
            $query = $writeDB->prepare(
                'INSERT INTO tbltasks
                        (title,
                        description,
                        deadline,
                        completed,
                        userid)
                    VALUES
                        (:title,
                        :description,
                        STR_TO_DATE(:deadline, \'%d/%m/%Y %H:%i:%s\'),
                        :completed,
                        :userid)
                '
            );
            $query->bindParam(':title', $title, PDO::PARAM_STR);
            $query->bindParam(':description', $description, PDO::PARAM_STR);
            $query->bindParam(':deadline', $deadline, PDO::PARAM_STR);
            $query->bindParam(':completed', $completed, PDO::PARAM_STR);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            //Make sure it is successfull
            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Responses();
                $response->setHttpStatusCode(500)
                    ->setSuccess(false)
                    ->addMessage("Failed to save new task")
                    ->send();
                exit;
            }

            /**
             * Fetch data we saved to verify what we saved.
             * Using PHP reserved keyword = lastInsertId()
             */
            $lastTaskId = $writeDB->lastInsertId();

            $query = $writeDB->prepare(
                'SELECT id,
                            title,
                            description,
                            DATE_FORMAT(
                                deadline,
                                "%d/%m/%Y %H:%i:%s"
                            ) AS deadline,
                            completed
                    FROM tbltasks
                    WHERE id = :taskid
                    AND userid =:userid'
            );
            $query->bindParam(':taskid', $lastTaskId, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $taskRowCount = $query->rowCount();

            if ($taskRowCount === 0) {
                $response = new Responses();
                $response->setHttpStatusCode(500)
                    ->setSuccess(false)
                    ->addMessage("Failed to retrieve task after creation")
                    ->send();
                exit;
            }

            $tasksArrayRetrieve = [];
            $returnData = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task(
                    $row['id'],
                    $row['title'],
                    $row['description'],
                    $row['deadline'],
                    $row['completed']
                );
                $tasksArrayRetrieve[] = $task->returnTaskAsArray();
            }

            $returnData['rows_returned'] = $taskRowCount;
            $returnData['tasks'] = $tasksArrayRetrieve;

            $response = new Responses();
            $response->setHttpStatusCode(201)
                ->setSuccess(true)
                ->addMessage("Task created")
                ->setData($returnData)
                ->send();
            exit;
        } catch (TaskException $te) {
            $response = new Responses();
            $response->setHttpStatusCode(400)
                ->setSuccess(false)
                ->addMessage($te->getMessage())
                ->send();
            exit;
        } catch (PDOException $pe) {
            error_log("Database query error - " . $pe, 0);
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage("Failed to insert task into databse - check submitted data for errors")
                ->send();
            exit;
        }
    } else {
        $response = new Responses();
        $response->setHttpStatusCode(405)
            ->setSuccess(false)
            ->addMessage("Request method not allowed")
            ->send();
        exit;
    }
} else {
    $response = new Responses();
    $response->setHttpStatusCode(404)
        ->setSuccess(false)
        ->addMessage("Endpoint not found")
        ->send();
    exit;
}
