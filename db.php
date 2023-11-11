<?php

$config = [
    'db' => [
        'host' => 'localhost',
        'name' => 'parser_db',
        'user' => 'parseropt',
        'password' => 'parserpdo',
        'charset' => 'utf8',
    ],
];

$db = new PDO ('mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['name'], $config['db']['user'], $config['db']['password']);
$db->query("SET character_set_connection = '" . $config['db']['charset'] . "';");
$db->query("SET character_set_client = '" . $config['db']['charset'] . "';");
$db->query("SET character_set_results = '" . $config['db']['charset'] . "';");

?>
