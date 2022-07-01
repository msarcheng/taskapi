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

//Session CRUD
if (array_key_exists("sessionid", $_GET)) {
} elseif (empty($_GET)) {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response = new Responses();
        $response->setHttpStatusCode(405)
            ->setSuccess(false)
            ->addMessage("Request method not allowed")
            ->send();
        exit;
    }

    //Prevent a brute force attack
    sleep(1);

    // Trigger error if wrong content
    if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
        $response = new Responses();
        $response->setHttpStatusCode(400)
            ->setSuccess(false)
            ->addMessage("Content type error is not set to JSON")
            ->send();
        exit;
    }

    //Check and get the JSON
    $rawPostData = file_get_contents('php://input');
    if (!$jsonData = json_decode($rawPostData)) {
        $response = new Responses();
        $response->setHttpStatusCode(400)
            ->setSuccess(false)
            ->addMessage("Request body is not valid JSON")
            ->send();
        exit;
    }

    //Valid JSON get
    if (
        !isset($jsonData->username)
        || !isset($jsonData->password)
    ) {
        $response = new Responses();
        $response->setHttpStatusCode(400)
            ->setSuccess(false);
        (!isset($jsonData->username) ? $response->addMessage("Username not supplied") : false);
        (!isset($jsonData->password) ? $response->addMessage("Password not supplied") : false);
        $response->send();
        exit;
    }

    if (
        strlen($jsonData->username) < 1
        || strlen($jsonData->username) > 255
        || strlen($jsonData->password) < 1
        || strlen($jsonData->password) > 255
    ) {
        $response = new Responses();
        $response->setHttpStatusCode(400)
            ->setSuccess(false);
        (!isset($jsonData->username) < 1 ? $response->addMessage("Username cannot be blank") : false);
        (!isset($jsonData->username) > 255 ? $response->addMessage("Username cannot greater than 255 character") : false);
        (!isset($jsonData->password) < 1 ? $response->addMessage("Password cannot be blank") : false);
        (!isset($jsonData->password) > 255 ? $response->addMessage("Password cannot greater than 255 character") : false);
        $response->send();
        exit;
    }

    try {
        $username = $jsonData->username;
        $password = $jsonData->password;

        $query = $writeDB->prepare(
            'SELECT id,
                    fullname,
                    username,
                    password,
                    useractive,
                    loginattempts
             FROM tblusers
             WHERE username = :username'
        );
        $query->bindParam(':username', $username, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        //No user exists: Unauthorized
        if ($rowCount === 0) {
            $response = new Responses();
            $response->setHttpStatusCode(401)
                ->setSuccess(false)
                ->addMessage("Password is incorrect")
                ->send();
            exit;
        }

        //If user exists
        $row = $query->fetch(PDO::FETCH_ASSOC);

        $returned_id = $row['id'];
        $returned_fullname = $row['fullname'];
        $returned_password = $row['password'];
        $returned_username = $row['username'];
        $returned_useractive = $row['useractive'];
        $returned_loginattempts = $row['loginattempts'];

        //Validations - check if user is active and not lock out
        if ($returned_useractive !== 'Y') {
            $response = new Responses();
            $response->setHttpStatusCode(401)
                ->setSuccess(false)
                ->addMessage("User account not active")
                ->send();
            exit;
        }

        if ($returned_loginattempts >= 3) {
            $response = new Responses();
            $response->setHttpStatusCode(401)
                ->setSuccess(false)
                ->addMessage("User account account is currently locked out")
                ->send();
            exit;
        }

        //Throw error if password unhashed dones not match
        if (!password_verify($password, $returned_password)) {
            $query = $writeDB->prepare(
                'UPDATE tblusers
                 SET loginattempts = loginattempts+1
                 WHERE id = :id'
            );
            $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
            $query->execute();

            $response = new Responses();
            $response->setHttpStatusCode(401)
                ->setSuccess(false)
                ->addMessage("Username or password is incorrect")
                ->send();
            exit;
        }

        //When password does match;
        //Access token and refresh token
        //Using openssl_random_pseudo_bytes(24) to create ssl with 24 characters
        //Convert to binary since its bytes.
        $accessToken = base64_encode(
            bin2hex(
                openssl_random_pseudo_bytes(24)
            ).time()
        );

        $refreshToken = base64_encode(
            bin2hex(
                openssl_random_pseudo_bytes(24)
            ).time()
        );

        $access_expiry_token = 1200;    //1Hr
        $refresh_expiry_token = 1209600; //14 days

        //Lets reset the login attempt back to zero
        try {
            $writeDB->beginTransaction(); //Start database transaction

            $query = $writeDB->prepare(
                'UPDATE tblusers
                 SET loginattempts = 0
                 WHERE id = :id'
            );
            $query->bindParam(':id', $id, PDO::PARAM_STR);
            $query->execute();

            //Insert to table sessions
            $query2 = $writeDB->prepare(
                'INSERT INTO tblsessions
                    (
                        userid,
                        accesstoken,
                        accesstokenexpiry,
                        refreshtoken,
                        refreshtokenexpiry
                     )
                 VALUES
                    (
                        :userid,
                        :accesstoken,
                        date_add(NOW(), INTERVAL :accesstokenexpiry SECOND),
                        :refreshtoken,
                        date_add(NOW(), INTERVAL :refreshtokenexpiry SECOND)
                    )'
            );
            $query2->bindParam(':userid', $returned_id, PDO::PARAM_INT);
            $query2->bindParam(':accesstoken', $accessToken, PDO::PARAM_STR);
            $query2->bindParam(':accesstokenexpiry', $access_expiry_token, PDO::PARAM_STR);
            $query2->bindParam(':refreshtoken', $refreshToken, PDO::PARAM_STR);
            $query2->bindParam(':refreshtokenexpiry', $refresh_expiry_token, PDO::PARAM_STR);

            $query2->execute();

            $lastSessionId = $writeDB->lastInsertId();

            $writeDB->commit(); //Commit the transaction.

            //Return data
            $returnData = [];
            $returnData['session_id'] = intval($lastSessionId);
            $returnData['access_token'] = $accessToken;
            $returnData['access_token_expires_in'] = $access_expiry_token;
            $returnData['refresh_token'] = $refreshToken;
            $returnData['refresh_token_expires_in'] = $refresh_expiry_token;

            //If successfull.
            $response = new Responses();
            $response->setHttpStatusCode(201)
                ->setSuccess(true)
                ->setData($returnData)
                ->addMessage("Session created")
                ->send();
            exit;

        } catch (PDOException $pe) {
            $writeDB->rollBack();   //Rollback if any failed.
            $response = new Responses();
            $response->setHttpStatusCode(500)
                ->setSuccess(false)
                ->addMessage("There was an issue logging in")
                ->send();
            exit;
        }

    } catch (PDOException $pe) {
        $response = new Responses();
        $response->setHttpStatusCode(500)
            ->setSuccess(false)
            ->addMessage("There was an issue logging")
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
