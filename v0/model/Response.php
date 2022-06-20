<?php

class Responses
{
    /**
     * Variables for our API
     *  never cache tokens and other important stuff
     */
    private bool $_success;
    private int $_httpStatusCode;
    private array $_messages = [];
    private $_data;
    private bool $_toCache = false; //For caching
    private array $_responseData = [];

    /**
     * Set success code
     *
     * @param boolean $success
     */
    public function setSuccess(bool $success)
    {
        $this->_success = $success;
        return $this;
    }

    /**
     * Set Http status code
     *
     * @param integer $httpStatusCode
     */
    public function setHttpStatusCode(int $httpStatusCode)
    {
        $this->_httpStatusCode = $httpStatusCode;
        return $this;
    }

    /**
     * Add message for http response code 500
     *
     * @param string $message
     */
    public function addMessage(string $message)
    {
        $this->_messages[] = $message;
        return $this;
    }

    /**
     * Set data to be returned
     *
     * @param $data
     */
    public function setData($data)
    {
        $this->_data = $data;
        return $this;
    }

    /**
     * Cache data
     *
     * @param bool $toCache
     */
    public function toCache($toCache)
    {
        $this->_toCache = $toCache;
        return $this;
    }

    /**
     * Send method to get data from database
     * Cache client 60 seconds, else no-cache
     */
    public function send()
    {
        header('Content-Type: application/json;charset=utf-8');

        if ($this->_toCache) {
            header('Cache-control: max-age=60');
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