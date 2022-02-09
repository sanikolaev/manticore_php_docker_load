#!/usr/bin/php
<?php
ini_set('memory_limit', 1024*1024*1024);

if (count($argv) < 4) die("Usage: ".__FILE__." <batch size> <concurrency> <docs>\n");

require_once 'vendor/autoload.php';

$t = microtime(true);
$c = 0;
$m = @mysqli_connect('manticoresearch', '', '', '', 9306);
if (mysqli_connect_error()) die("Cannot connect to Manticore\n");
mysqli_query($m, "drop table if exists user");
mysqli_query($m, "create table user(name text, email string, description text, age int, active bit(1))");

$faker = Faker\Factory::create();

$batches = [];
echo "preparing...\n";
$error = false;
$cache_file_name = '/tmp/json_user_'.$argv[1].'_'.$argv[3];
$c = 0;
if (!file_exists($cache_file_name)) {
    $batches = [];
    while ($c < $argv[3]) {
      $ar = [$faker->name(), $faker->email(), $faker->text(), rand(10,90), rand(0,1)];
      $batch[] = '{"insert": {"index": "user", "doc":  {"name":"'.$ar[0].'","email":"'.$ar[1].'","description":"'.$ar[2].'","age":'.$ar[3].',"active":'.$ar[4].'}}}';
      $c++;
      if (floor($c/1000) == $c/1000) echo "\r".($c/$argv[3]*100)."%       ";
        if (count($batch) == $argv[1]) {
          $batches[] = implode("\n", $batch);
          $batch = [];
        }
    }
    if ($batch) $batches[] = implode("\n", $batch);
    file_put_contents($cache_file_name, serialize($batches));
} else {
    echo "found in cache\n";
    $batches = unserialize(file_get_contents($cache_file_name));
}

echo "querying...\n";
$t = microtime(true);

$mh = curl_multi_init();
$active = 0;
$c = 0;

while(true) {
  if ($active < $argv[2] and count($batches) > 0) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://manticoresearch:9312/bulk");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-ndjson']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, array_shift($batches));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_multi_add_handle($mh, $ch);
    $status = curl_multi_exec($mh, $active);
  }
  $status = curl_multi_exec($mh, $active);
  curl_multi_select($mh, 0.000001);
  if ($active == 0 and count($batches) == 0) break;
}
echo "finished inserting\n";
echo "Total time: ".(microtime(true) - $t)."\n";
echo round($argv[3] / (microtime(true) - $t))." docs per sec\n";
