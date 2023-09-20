<?php
require('vendor/autoload.php');

use Dcblogdev\PdoWrapper\Database;

$options = [
    'host' => "localhost",
    'database' => "calendar",
    'username' => "calendar",
    'password' => "calendar@!"
];
$db = new Database($options);

$dir = "./";
