<?php

require_once('Response.php');

$response = new Response();

$response->setSuccess(false)
    ->setHttpStatusCode(404)
    ->addMessage("Test message 1")
    ->addMessage("Test message 2")
    ->send();