<?php

require_once('db.php');
require_once('../model/Response.php');

//DB
try {

    $writeDB = DB::connectWriteDb();
    $readDB = DB::connectReadDb();

} catch (PDOException $px) {
    error_log("Connection error - ".$px, 0);
    $response = new Responses();
    $response->setHttpStatusCode(500)
        ->setSuccess(false)
        ->addMessage("Database connection error")
        ->send();
    exit;
}
