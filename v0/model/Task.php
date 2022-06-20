<?php

class TaskException extends Exception{}

class Task
{
    private $id;
    private $title;
    private $description;
    private $deadline;
    private $completed;

    public function __construct(
        $id,
        $title,
        $description,
        $deadline,
        $completed
    ) {
        $this->setId($id);
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setDeadline($deadline);
        $this->setCompleted($completed);
    }

    public function getId()
    {
        return $this->id;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getDeadline()
    {
        return $this->deadline;
    }

    public function getCompleted()
    {
        return $this->completed;
    }

    public function setId($id)
    {
        if (
            ($id !== null)
            && (!is_numeric($id)
                || $id <= 0
                || $id > 9223372036854775807
                || !is_null($this->id)
            )
        ) {
            throw new TaskException("Task ID error");
        }

        $this->id = $id;
    }

    public function setTitle(string $title)
    {
        if (strlen($title) < 0 || strlen($title) > 255) {
            throw new TaskException("Task title error");
        }
        $this->title = $title;
    }

    public function setDescription($description)
    {
        if (
            ($description !== null)
            && (strlen($description) > 16777215)
        ) {
            throw new TaskException("Task description error");
        }
        $this->description = $description;
    }

    public function setDeadline($deadline)
    {
        if (($deadline !== null) && date_format(date_create_from_format('d/m/Y H:i:s', $deadline), 'd/m/Y H:i:s') != $deadline) {
            throw new TaskException("Task deadline error");
        }
        $this->deadline = $deadline;
    }

    public function setCompleted($completed)
    {
        if (strtoupper($completed) !== 'Y' && strtoupper($completed) !== 'N') {
            throw new TaskException("Task completed must be Y or N");
        }
        $this->completed = $completed;
    }

    public function returnTaskAsArray()
    {
        $task = [];
        $task['id'] = $this->getId();
        $task['title'] = $this->getTitle();
        $task['description'] = $this->getDescription();
        $task['deadline'] = $this->getDeadline();
        $task['completed'] = $this->getCompleted();
        return $task;
    }
}
