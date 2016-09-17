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


$stmt2 = $pdo->query("SELECT count(*) as count FROM entry");
$r = $stmt2->fetch(PDO::FETCH_ASSOC);

$redis->set('entry_count', $r['count']);




$stmt3 = $pdo->query("SELECT keyword, description FROM entry");
$keywords = $redis->zRevRange('zkeywords', 0, -1);

while($t = $stmt3->fetch(PDO::FETCH_ASSOC)){
    $kw2sha = [];
    for ($i = 0; !empty($kwtmp = array_slice($keywords, 500 * $i, 500)); $i++) {
        $re = implode('|', array_map(function ($keyword) { return quotemeta($keyword); }, $kwtmp));
        preg_replace_callback("/($re)/", function ($m) use (&$kw2sha) {
            $kw = $m[1];
            return $kw2sha[] = $kw;
        }, $t['description']);
    }
    foreach($kw2sha as $kw){
      $redis->zAdd('zkey_'.urlencode($t['keyword']), mb_strlen($kw), $kw);
    }
}



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


