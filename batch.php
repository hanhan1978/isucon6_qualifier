<?php

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$redis->flushAll();

ini_set('log_errors','On');
ini_set('error_log','/tmp/error_batch.log');


$pdo = get_db();

$stmt = $pdo->query("SELECT keyword FROM entry");

while($u = $stmt->fetch(PDO::FETCH_ASSOC)){
    //echo $u['keyword']." ".mb_strlen($u['keyword']).PHP_EOL;
    $redis->zAdd('zkeywords', mb_strlen($u['keyword']), $u['keyword']);
}
$zkeys = $redis->zRevRange('zkeywords', 0, -1);




function get_db(){
  $host = '127.0.0.1';
  $dbname = 'isuda';
  $user = 'isucon';
  $pass = 'isucon';
  $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8",$user,$pass, array(PDO::ATTR_EMULATE_PREPARES => false));
  $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
  return $pdo;
}


