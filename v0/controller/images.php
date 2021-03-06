<?php
/**
 * Images Controller
 */
require_once('db.php');
require_once('../model/Response.php');
require_once('../model/image.php');

function sendResponse(
    $statusCode,
    $success,
    $message = null,
    $toCache = false,
    $data = null
) {
    $response = new Responses();
    $response->setHttpStatusCode($statusCode)
        ->setSuccess($success);
    if ($message != null)     {
        $response->addMessage($message);
    }
    $response->toCache($toCache);
    if ($data != null)     {
        $response->setData($data);
    }
    $response->send();
    exit;
}

function uploadImageRoute(
    $readDB,
    $writeDB,
    $taskid,
    $returned_userid
) {
    try {
        if (!isset($_SERVER['CONTENT_TYPE']) || strpos($_SERVER['CONTENT_TYPE'], "multipart/form-data; boundary=") === false) {
            sendResponse(400, false, "Content type header not set to multipart/form-data with a boundary");
        }
        $query = $readDB->prepare(
            'SELECT id
             FROM tbltasks
             WHERE id = :taskid
                AND userid = :userid'
        );
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            sendResponse(404, false, "Task not found");
        }

        if (!isset($_POST['attributes'])) {
            sendResponse(400, false, "Attributes missing from the body");
        }

        if (!$jsonImageAttributes = json_decode($_POST['attributes'])) {
            sendResponse(400, false, "Attributes field is not valid JSON");
        }

        if (
            !isset($jsonImageAttributes->title)
            || !isset($jsonImageAttributes->filename)
            || $jsonImageAttributes->title == ''
            || $jsonImageAttributes->filename == ''
        ) {
            sendResponse(400, false, "Title and Filename are mandatory");
        }

        if (strpos($jsonImageAttributes->filename, ".") > 0) {
            sendResponse(400, false, "Filename must not contain file extension");
        }

        if (
            !isset($_FILES['imagefile'])
            || $_FILES['imagefile']['error'] !== 0
        ) {
            sendResponse(500, false, "Image file upload unsuccessful - make sure you selected a file");
        }

        $imageFileDetails = getimagesize($_FILES['imagefile']['tmp_name']);

        if (
            isset($_FILES['imagefile']['size'])
            && $_FILES['imagefile']['size'] > 5242880
        ) {
            sendResponse(400, false, "File must be under 5MB");
        }

        $allowedImageFileTypes = [
            'image/jpeg',
            'image/gif',
            'image/png'
        ];

        if (!in_array($imageFileDetails['mime'], $allowedImageFileTypes)) {
            sendResponse(400, false, "File type not supported");
        }

        $fileExtension = "";
        switch($imageFileDetails['mime']) {
            case "image/jpeg":
                $fileExtension = ".jpg";
                break;
            case "image/gif":
                $fileExtension = ".gif";
                break;
            case "image/png":
                $fileExtension = ".png";
                break;
            default:
                break;
        }

        if ($fileExtension == "") {
            sendResponse(400, false, "No valid file extension found for mimetype");
        }

        $image = new Image(
            null,
            $jsonImageAttributes->title,
            $jsonImageAttributes->filename.$fileExtension,
            $imageFileDetails['mime'],
            $taskid
        );

        $title = $image->getTitle();
        $newFilename = $image->getFilename();
        $mimetype = $image->getMimeType();

        $query = $readDB->prepare(
            'SELECT a.id AS id
             FROM tblimages a
             INNER JOIN tbltasks b
                ON a.taskid = b.id
             WHERE b.id = :taskid
                AND b.userid = :userid
                AND a.filename = :filename'
        );

        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->bindParam(':filename', $newFilename, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount !== 0) {
            sendResponse(409, false, "A file with that filename already existx for this task - try a different filename");
        }

        $writeDB->beginTransaction();
        $query = $writeDB->prepare(
            'INSERT INTO tblimages (title, filename, mimetype, taskid)
            VALUES (:title, :filename, :mimetype, :taskid)'
        );
        $query->bindParam(':title', $title, PDO::PARAM_STR);
        $query->bindParam(':filename', $newFilename, PDO::PARAM_STR);
        $query->bindParam(':mimetype', $mimetype, PDO::PARAM_STR);
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0) {
            if ($writeDB->inTransaction()) {
                $writeDB->rollBack();
            }
            sendResponse(500, false, "Failed to upload image");
        }

        $lastImageId = $writeDB->lastInsertId();

        $query = $writeDB->prepare(
            'SELECT a.id AS id,
                    a.title AS title,
                    a.filename AS filename,
                    a.mimetype AS mimetype,
                    a.taskid AS taskid
             FROM tblimages a
             INNER JOIN tbltasks b
                ON a.taskid = b.id
             WHERE a.id = :imageid
                AND b.id = :taskid
                AND b.userid = :userid'
        );
        $query->bindParam(':imageid', $lastImageId, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0) {
            if ($writeDB->inTransaction()) {
                $writeDB->rollBack();
            }
            sendResponse(500, false, "Failed to retrieved image attributes after upload - try uploading image again");
        }

        $imageArray = [];

        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image(
                $row['id'],
                $row['title'],
                $row['filename'],
                $row['mimetype'],
                $row['taskid']
            );
            $imageArray[] = $image->returnImageAsArray();
        }

        $image->saveImageFile($_FILES['imagefile']['tmp_name']);

        $writeDB->commit();

        sendResponse(201, true, "Image uploaded successfully", false, $imageArray);

    } catch (PDOException $pe) {
        error_log("Database connection failed". $pe, 0);

        if ($writeDB->inTransaction()){
            $writeDB->rollBack();
        }

        sendResponse(500, false, "Failed to upload the image");

    } catch (ImageException $ie) {
        sendResponse(500, false, $ie);
    }
}

function getImageAttributesRoute($readDB, $taskid, $imageid, $returned_userid) {
    try {
        $query = $readDB->prepare(
            'SELECT a.id AS id,
                    a.title AS title,
                    a.filename AS filename,
                    a.mimetype AS mimetype,
                    a.taskid AS taskid
             FROM tblimages a
             INNER JOIN tbltasks b
                ON a.taskid = b.id
             WHERE a.id = :imageid
                AND b.id = :taskid
                AND b.userid = :userid'
        );

        $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);

        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0){
            sendResponse(404, false, "Image not found");
        }

        $imageArray = [];
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image(
                $row['id'],
                $row['title'],
                $row['filename'],
                $row['mimetype'],
                $row['taskid']
            );

            $imageArray[] = $image->returnImageAsArray();
        }

        sendResponse(200, true, null, true, $imageArray);

    } catch (PDOException $pe) {
        error_log("Database query error:".$pe, 0);
        sendResponse(500, false, "Failed to get image attributes");
    } catch (ImageException $ie) {
        sendResponse(500, false, $ie->getMessage());
    }
}

function getImageRoute($readDB, $taskid, $imageid, $returned_userid) {
    try {

        $query = $readDB->prepare(
            'SELECT a.id AS id,
                    a.title AS title,
                    a.filename AS filename,
                    a.mimetype AS mimetype,
                    a.taskid AS taskid
             FROM tblimages a
             INNER JOIN tbltasks b
                ON a.taskid = b.id
             WHERE a.id = :imageid
             AND b.id = :taskid
             AND b.userid = :userid'
        );
        $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            sendResponse(404, false, "Image not found");
        }

        $image = null;

        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image(
                $row['id'],
                $row['title'],
                $row['filename'],
                $row['mimetype'],
                $row['taskid']
            );
        }

        if ($image == null) {
            sendResponse(500, false, "Image file not found");
        }

        $image->returnImageFile();


    } catch (ImageException $ie) {
        sendResponse(
            500,
            false,
            $ie->getMessage()
        );
     } catch (PDOException $pe) {
        error_log("Connection error".$pe, 0);
        sendResponse(
            500,
            false,
            "Database connection error"
        );
     }
}

function updateImageAttributesRoute($writeDB, $taskid, $imageid, $returned_userid) {
    try {
        if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
            sendResponse(400, false, "Content type header not set to JSON");
        }

        $rawPatchData = file_get_contents('php://input');

        if (!$jsonData = json_decode($rawPatchData)) {
            sendResponse(400, false, "Request body is not valid JSON");
        }

        $title_updated = false;
        $filename_updated = false;

        $queryFields = "";

        //tblimages - a
        if (isset($jsonData->title)) {
            $title_updated = true;
            $queryFields .= "a.title = :title, ";
        }

        //Filename should not contain any dot or extensions.
        if (isset($jsonData->filename)) {
            if (strpos($jsonData->filename, ".") !== false) {
                sendResponse(400, false, "Filename cannot contain any dots or file extensions");
            }
            $filename_updated = true;
            $queryFields .= "a.filename = :filename, ";
        }

        //When applying query we need to remove the last comma in the end
        $queryFields =rtrim($queryFields, ", ");

        if ($title_updated === false && $filename_updated === false) {
            sendResponse(400, false, "No image fields provided");
        }

        //Using transactions fro PDO again
        $writeDB->beginTransaction();

        $query = $writeDB->prepare(
            'SELECT a.id AS id,
                    a. title AS title,
                    a.filename AS filename,
                    a.mimetype AS mimetype,
                    a.taskid AS taskid
             FROM tblimages a
             INNER JOIN tbltasks b
                ON a.taskid = b.id
             WHERE a.id = :imageid
             AND a.taskid = :taskid
             AND b.userid = :userid'
        );
        $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            if ($writeDB->inTransaction()) {
                $writeDB->rollBack();
            }
            sendResponse(404, false, "No image found to update");
        }

        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image(
                $row['id'],
                $row['title'],
                $row['filename'],
                $row['mimetype'],
                $row['taskid']
            );
        }

        $queryString = "UPDATE tblimages a
                        INNER JOIN tbltasks b
                            ON a.taskid = b.id
                        SET " . $queryFields . "
                        WHERE a.taskid = b.id
                        AND a.id = :imageid
                        AND a.taskid = :taskid
                        AND b.userid = :userid";

        $query = $writeDB->prepare($queryString);

        //Change to $var === true only if does not function
        if ($title_updated) {
            $image->setTitle($jsonData->title);     //Set title for update
            $up_title =$image->getTitle();          //Get the title for update
            $query->bindParam(":title", $up_title, PDO::PARAM_STR);
        }

        //Change to $var === true only if does not function
        if ($filename_updated) {
            $originalFilename = $image->getFilename();

            $image->setFilename($jsonData->filename . "." . $image->getFileExtension());     //Set filename for update

            //Updated filename
            $up_filename =$image->getFilename();
            $query->bindParam(":filename", $up_filename, PDO::PARAM_STR);
        }

        $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            if ($writeDB->inTransaction()) {
                $writeDB->rollBack();
            }

            sendResponse(400, false, "Image attributes not updated - the given values may be the same as the stored values");
        }

        $query = $writeDB->prepare(
            'SELECT a.id AS id,
                    a.title AS title,
                    a.filename AS filename,
                    a.mimetype AS mimetype,
                    a.taskid AS taskid
             FROM tblimages a
             INNER JOIN tbltasks b
                ON a.taskid = b.id
             WHERE a.id = :imageid
             AND b.id = :taskid
             AND b.userid = :userid'
        );
        $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            if ($writeDB->inTransaction()) {
                $writeDB->rollBack();
            }
            sendResponse(404, false, "No Image Found");
        }

        //Put it back in model and store in JSON as response
        $imageArray = [];

        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image(
                $row['id'],
                $row['title'],
                $row['filename'],
                $row['mimetype'],
                $row['taskid']
            );

            $imageArray[] = $image->returnImageAsArray();
        }

        //Change to $var === true only if does not function
        if ($filename_updated) {
            $image->renameImageFile($originalFilename, $up_filename);
        }

        $writeDB->commit();

        sendResponse(200, true, "Image attributes updated", false, $imageArray);

    } catch (ImageException $ie) {
        sendResponse(400, false, $ie->getMessage());

     } catch (PDOException $pe) {
        error_log("Connection error".$pe, 0);

        //rollback
        if ($writeDB->inTransaction()) {
            $writeDB->rollBack();
        }

        sendResponse(500, false, $pe." -Failed to update image attributes -check your data for errors");
     }
}

function deleteImageRoute($writeDB, $taskid, $imageid, $returned_userid) {
    try {
        $writeDB->beginTransaction();

        $query = $writeDB->prepare(
            'SELECT a.id AS id,
                    a.title AS title,
                    a.filename AS filename,
                    a.mimetype AS mimetype,
                    a.taskid AS taskid
             FROM tblimages a
             INNER JOIN tbltasks b
                ON a.taskid = b.id
             WHERE a.id = :imageid
             AND b.id = :taskid
             AND b.userid = :userid'
        );
        $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            if ($writeDB->inTransaction()) {
                $writeDB->rollBack();
            }
            sendResponse(404, false, "No Image Found");
        }

        $image = null;
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image(
                $row['id'],
                $row['title'],
                $row['filename'],
                $row['mimetype'],
                $row['taskid']
            );
        }

        if ($image == null) {
            $writeDB->rollBack();
            sendResponse(500, false, "Failed to get Image");
        }

        $query = $writeDB->prepare(
            'DELETE tblimages
             FROM tblimages
             INNER JOIN tbltasks
                ON tblimages.taskid = tbltasks.id
             WHERE tblimages.id = :imageid
             AND tbltasks.id = :taskid
             AND tbltasks.userid = :userid'
        );

        $query->bindParam(":imageid", $imageid, PDO::PARAM_INT);
        $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
        $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            $writeDB->rollBack();
            sendResponse(404, false, "Image not found");
        }

        $image->deleteImageFile();

        $writeDB->commit();

        sendResponse(200, true, "Image deleted");

    } catch (ImageException $ie) {
        sendResponse(400, false, $ie->getMessage());

    } catch (PDOException $pe) {
        error_log("Connection error".$pe, 0);

        //rollback
        $writeDB->rollBack();

        sendResponse(500, false, $pe." -Failed to update image attributes -check your data for errors");
     }
}

 function checkAuthStatusAndReturnUserId($writeDB) {
    /**
     * Begin Auth Script
     * get http token from header
     */
    $httpAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_AUTHORIZATION'] = '';
    if (
        !isset($_SERVER['HTTP_AUTHORIZATION'])
        || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1
    ) {
        $message = null;
        if(!isset($_SERVER['HTTP_AUTHORIZATION'])){
            $message = "Access token missing from the header";
        } else {
            if (strlen($_SERVER['HTTP_AUTHORIZATION']) <  1) {
                $message = "Access token cannot be blank";
            }
        }
        sendResponse(401, false, $message);

        // $response = new Responses();
        // $response->setHttpStatusCode(401)
        //     ->setSuccess(false);
        // (!isset($httpAuth) ? $response->addMessage("Access token missing from the header") : false);
        // (empty($httpAuth) ? $response->addMessage("Access token is in the header but not supplied") : false);
        // (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access token cannot be blank") : false);
        // $response->send();
        // exit;
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

            sendResponse(401, false, "Invalid Access Token");
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);

        $returned_userid = $row['userid'];
        $returned_accesstokenexpiry = $row['accesstokenexpiry'];
        $returned_useractive = $row['useractive'];
        $returned_loginattempts = $row['loginattempts'];

        //Check if user is still active
        if ($returned_useractive !== 'Y') {
            sendResponse(401, false, "User account not active");
        }

        //Check if user has been locked out
        if ($returned_loginattempts >= 3) {
            sendResponse(401, false, "User account is currently locked out");
        }

        //Check if expiry time of refreshtoken is less than database time
        if (strtotime($returned_accesstokenexpiry) < time()) {
            sendResponse(401, false, "Access token expired");
        }

        return $returned_userid;

    } catch (PDOException $px) {
        sendResponse(500, false, "There was an issue authenticating - please try again");
    }
    //  End of Auth script
 }

 try {
    $writeDB = DB::connectWriteDb();
    $readDB = DB::connectReadDb();

 } catch (PDOException $pe) {
    error_log("Connection error".$pe, 0);
    sendResponse(
        500,
        false,
        "Database connection error"
    );
 }

 $returned_userid = checkAuthStatusAndReturnUserId($writeDB);

 if (
    array_key_exists("taskid", $_GET)
    && array_key_exists("imageid", $_GET)
    && array_key_exists("attributes", $_GET)
 ) {
    $taskid = $_GET['taskid'];
    $imageid = $_GET['imageid'];
    $attributes = $_GET['attributes'];

    if (
        $imageid == ''
        || !is_numeric($imageid)
        || $taskid == ''
        || !is_numeric($taskid)
    ) {
        sendResponse(
            400,
            false,
            "Image ID or Task ID cannot be blank and must be numberic"
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        getImageAttributesRoute($readDB, $taskid, $imageid, $returned_userid);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        updateImageAttributesRoute($writeDB, $taskid, $imageid, $returned_userid);

    } else {
        sendResponse(
            405,
            false,
            "Request method not allowed"
        );
    }

} elseif (
    array_key_exists("taskid", $_GET)
    && array_key_exists("imageid", $_GET)
) {
    $taskid = $_GET['taskid'];
    $imageid = $_GET['imageid'];

    if (
        $imageid == ''
        || !is_numeric($imageid)
        || $taskid == ''
        || !is_numeric($taskid)
    ) {
        sendResponse(
            400,
            false,
            "Image ID or Task ID cannot be blank and must be numberic"
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        getImageRoute($readDB, $taskid, $imageid, $returned_userid);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        deleteImageRoute($writeDB, $taskid, $imageid, $returned_userid);

    } else {
        sendResponse(405, false, "Request method not allowed");
    }
} elseif (
    array_key_exists("taskid", $_GET)
    && !array_key_exists("imageid", $_GET)
) {

    $taskid = $_GET['taskid'];
    if ($taskid == '' || !is_numeric($taskid)) {
        sendResponse(400, false, "Task ID cannot be blank and must be numberic");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        uploadImageRoute($readDB, $writeDB, $taskid, $returned_userid);

    } else {
        sendResponse(405, false, "Request method not allowed");
    }

} else {
    sendResponse(404, false, "Endpoint not found");
}

