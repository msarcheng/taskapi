<?php

class DB
{
    private static $writeDBConnection;
    private static $readDBConnection;

    public static function connectWriteDb()
    {
        if(self::$writeDBConnection === null) {
            self::$writeDBConnection = new PDO(
                'mysql:host=localhost;dbname=tasksdb;charset=utf8',
                'marvs',
                'marvs'
            );
            self::$writeDBConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$writeDBConnection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            return self::$writeDBConnection;
        }
    }

    public static function connectReadDb()
    {
        if(self::$readDBConnection === null) {
            self::$readDBConnection = new PDO(
                'mysql:host=localhost;dbname=tasksdb;charset=utf8',
                'marvs',
                'marvs'
            );
            self::$readDBConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$readDBConnection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            return self::$readDBConnection;
        }
    }
}