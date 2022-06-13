<?php

class Response
{
    /**
     * Variables for our API
     *  never cache tokens and other important stuff
     */
    private $_success;
    private $_httpStatusCode;
    private $_messages = [];
    private $_data;
    private $_toCache = false; //For caching
    private $_responseData = [];

    /**
     * Set success
     *
     * @param string $success
     */
    public function setSuccess($success)
    {
        $this->_success = $success;
        return $this;
    }

    public function setHttpStatusCode($httpStatusCode)
    {
        $this->_httpStatusCode = $httpStatusCode;
        return $this;
    }

    public function addMessage($message)
    {
        $this->_messages[] = $message;
        return $this;
    }

    public function setData($data)
    {
        $this->_data = $data;
        return $this;
    }

    public function toCache($toCache)
    {
        $this->_toCache = $toCache;
        return $this;
    }

    public function send()
    {
        header('Content-Type: application/json;charset=utf-8');

        if ($this->_toCache) {
            header('Cache-control: max-age=60'); //Cache the client max 60sec
        } else {
            header('Cache-control: no-cache; no-store');
        }

        /**
         * check if success is not a boolean
         * set http header to 500 as standard output
         * Otherwise, return a success with data.
         */
        if (
            !is_bool($this->_success !== false)
            || !is_numeric($this->_httpStatusCode)
        ) {
            http_response_code(500);
            $this->_responseData['statusCode'] = 500;
            $this->_responseData['success'] = false;
            $this->addMessage("Response creation error.");
            $this->_responseData['messages'] = $this->_messages;

        } else {
            http_response_code($this->_httpStatusCode);
            $this->_responseData['statusCode'] = $this->_httpStatusCode;
            $this->_responseData['success'] = $this->_success;
            $this->_responseData['messages'] = $this->_messages;
            $this->_responseData['data'] = $this->_data;
        }

        echo json_encode($this->_responseData);
    }
}