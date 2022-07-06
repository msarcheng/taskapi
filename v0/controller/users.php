<?php

require_once('db.php');
require_once('../model/Response.php');

//DB
try {
    $writeDB = DB::connectWriteDb();
} catch (PDOException $px) {
    error_log("Connection error - " . $px, 0);
    $response = new Responses();
    $response->setHttpStatusCode(500)
        ->setSuccess(false)
        ->addMessage("Database connection error")
        ->send();
    exit;
}

//Handle options request method for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    $response = new Responses();
    $response->setHttpStatusCode(200)
        ->setSuccess(true)
        ->send();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response = new Responses();
    $response->setHttpStatusCode(405)
        ->setSuccess(false)
        ->addMessage("Request Method not allowed")
        ->send();
    exit;
}

// Trigger error if wrong content
if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
    $response = new Responses();
    $response->setHttpStatusCode(400)
        ->setSuccess(false)
        ->addMessage("Content type error is not set to JSON")
        ->send();
    exit;
}

$rawPostData = file_get_contents('php://input');

if(!$jsonData = json_decode($rawPostData)) {
    $response = new Responses();
    $response->setHttpStatusCode(400)
        ->setSuccess(false)
        ->addMessage("Request body is not valid JSON")
        ->send();
    exit;
}

//Trigger error whenever anyone of the following is missing.
if(
    !isset($jsonData->fullname)
    || !isset($jsonData->username)
    || !isset($jsonData->password)
) {
    $response = new Responses();
    $response->setHttpStatusCode(400)
        ->setSuccess(false);
    (!isset($jsonData->fullname) ? $response->addMessage("Full name not supplied") : false);
    (!isset($jsonData->username) ? $response->addMessage("Username not supplied") : false);
    (!isset($jsonData->password) ? $response->addMessage("Password not supplied") : false);
    $response->send();
    exit;
}

//Trigger error if condition is not met.
if (
    strlen($jsonData->fullname) < 1
    || strlen($jsonData->fullname) > 255
    || strlen($jsonData->username) < 1
    || strlen($jsonData->username) > 255
) {
    $response = new Responses();
    $response->setHttpStatusCode(400)
        ->setSuccess(false);
    (!isset($jsonData->fullname) < 1 ? $response->addMessage("Full name cannot be blank") : false);
    (!isset($jsonData->fullname) > 255 ? $response->addMessage("Full name cannot greater than 255 character") : false);
    (!isset($jsonData->username) < 1 ? $response->addMessage("Username cannot be blank") : false);
    (!isset($jsonData->username) > 255 ? $response->addMessage("Username cannot greater than 255 character") : false);
    (!isset($jsonData->password) < 1 ? $response->addMessage("Password cannot be blank") : false);
    (!isset($jsonData->password) > 255 ? $response->addMessage("Password cannot greater than 255 character") : false);
    $response->send();
    exit;
}

//Trim whitespace
$fullname = trim($jsonData->fullname);
$username = trim($jsonData->username);
$password = $jsonData->password;

try {
    $query = $writeDB->prepare(
        'SELECT id
         FROM tblusers
         WHERE username = :username'
    );
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    //409 is a conflict
    if ($rowCount !== 0) {
        $response = new Responses();
        $response->setHttpStatusCode(409)
            ->setSuccess(false)
            ->addMessage("Username already exists")
            ->send();
        exit;
    }

    //Hash the password
    $hash_password = password_hash($password, PASSWORD_DEFAULT);

    $query = $writeDB->prepare(
        'INSERT INTO tblusers (fullname, username, password)
         VALUES (:fullname, :username, :password)'
    );
    $query->bindParam(':fullname', $fullname, PDO::PARAM_STR);
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->bindParam(':password', $hash_password, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount === 0) {
        $response = new Responses();
        $response->setHttpStatusCode(500)
            ->setSuccess(false)
            ->addMessage("Failed to create user")
            ->send();
        exit;
    }

    //Get the last saved ID
    $lastUserId = $writeDB->lastInsertId();

    $returnData = [];
    $returnData['user_id'] = $lastUserId;
    $returnData['fullname'] = $fullname;
    $returnData['username'] = $username;

    $response = new Responses();
    $response->setHttpStatusCode(201)
        ->setSuccess(true)
        ->addMessage("Successfully save new user.")
        ->setData($returnData)
        ->send();
    exit;

} catch (PDOException $pe) {
    error_log("Database quer error: ".$pe, 0);
    $response = new Responses();
    $response->setHttpStatusCode(500)
        ->setSuccess(false)
        ->addMessage("There was an issue creating a user account - please try again")
        ->send();
    exit;
}