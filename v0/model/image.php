<?php

class ImageException extends Exception { }

class Image {

    private $_id;
    private $_title;
    private $_filename;
    private $_mimetype;
    private $_taskid;
    private $_uploadFolderLocation;

    public function __construct(
        $id,
        $title,
        $filename,
        $mimetype,
        $taskid
    ) {
        $this->setId($id);
        $this->setTitle($title);
        $this->setFilename($filename);
        $this->setMimeType($mimetype);
        $this->setTaskId($taskid);
        $this->_uploadFolderLocation = "../../../taskimages/";
    }

    public function getId()
    {
        return $this->_id;
    }

    public function getTitle()
    {
        return $this->_title;
    }

    public function getFilename()
    {
        return $this->_filename;
    }

    public function getFileExtension()
    {
        $filenameParts = explode(".", $this->_filename);
        $lastArrayElement = count($filenameParts) - 1;
        $fileExtension = $filenameParts[$lastArrayElement];
        return $fileExtension;
    }

    public function getMimeType()
    {
        return $this->_mimetype;
    }

    public function getTaskId()
    {
        return $this->_taskid;
    }

    public function getUploadFolderLocation()
    {
        return $this->_uploadFolderLocation;
    }

    public function getImageURL()
    {
        $httpOrHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $url = "/v0/tasks/" . $this->getTaskId() . "/images/" . $this->getId();

        return $httpOrHttps . "://" . $host . $url;
    }

    /**
     * Start of SETTERS
     */

    public function setId(int $id)
    {
        if (
            ($id !== null)
            && (
                !is_numeric($id)
                || $id <= 0
                || $id > 9223372036854775807
                || $this->_id !== null
            )
        ) {
            throw new ImageException("Image ID Error");
        }

        $this->_id = $id;
    }

    public function setTitle(string $title)
    {
        if (
            strlen($title) < 1
            || strlen($title) > 255
        ) {
            throw new ImageException("Image title error");
        }
        $this->_title = $title;
    }

    public function setFilename(string $filename)
    {
        // '+' in reges means then
        if (
            strlen($filename) < 1
            || strlen($filename) > 30
            || preg_match("/^[a-zA-Z0-9_-]+(.jpg|.gif|.png)$/", $filename) != 1
        ) {
            throw new ImageException("Image filename error - must be 1 and 30 characters and only be .jpg .png .gif");
        }
        $this->_filename = $filename;
    }

    public function setMimeType(string $mimetype)
    {
        if (
            strlen($$mimetype) < 1
            || strlen($$mimetype) > 255
        ) {
            throw new ImageException("Image mimetype error");
        }
        $this->_mimetype = $mimetype;
    }

    public function setTaskId(int $taskid)
    {
        if (
            ($taskid !== null)
            && (
                !is_numeric($taskid)
                || $taskid <= 0
                || $taskid > 9223372036854775807
                || $this->_id !== null
            )
        ) {
            throw new ImageException("Image TaskID Error");
        }

        $this->_taskid = $taskid;
    }

    public function returnImageAsArray()
    {
        $image = [];
        $image['id'] = $this->getId();
        $image['title'] = $this->getTitle();
        $image['filename'] = $this->getFilename();
        $image['mimetype'] = $this->getMimeType();
        $image['taskid'] = $this->getTaskId();
    }

}