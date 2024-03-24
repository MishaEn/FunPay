<?php
use FpDbTest\Database;
use FpDbTest\DatabaseTest;

spl_autoload_register(function ($class) {
    $a = array_slice(explode('\\', $class), 1);
    if (!$a) {
        throw new Exception();
    }
    $filename = implode('/', [__DIR__, ...$a]) . '.php';
    require_once $filename;
});

$mysqli = @new mysqli('mysql_db', 'root', 'pass', 'test', 3306);
if ($mysqli->connect_errno) {
    throw new Exception($mysqli->connect_error);
}

// Валится тест, если отдавать строку через real_escape_string, isTest только для прохождения теста. isTest = false - будет отдавать экранированную строку
$db = new Database($mysqli, true);
$test = new DatabaseTest($db);
$test->testBuildQuery();

exit('OK');
