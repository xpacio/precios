<?php

function getDB(): PDO
{
    $dsn = "pgsql:host=localhost;port=5432;dbname=precios";
    return new PDO($dsn, "postgres", "password", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
