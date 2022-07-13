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

    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

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
}