#!/usr/bin/php
<?php
ini_set('memory_limit', 1024*1024*1024);

if (count($argv) < 4) die("Usage: ".__FILE__." <batch size> <concurrency> <docs>\n");

require_once 'vendor/autoload.php';

// This function waits for an idle mysql connection for the $query, runs it and exits
function process($query) {
    global $all_links;
    global $requests;
    foreach ($all_links as $k=>$link) {
        if (@$requests[$k]) continue;
        mysqli_query($link, $query, MYSQLI_ASYNC);
        @$requests[$k] = microtime(true);
        return true;
    }
    do {
        $links = $errors = $reject = array();
        foreach ($all_links as $link) {
            $links[] = $errors[] = $reject[] = $link;
        }
        $count = @mysqli_poll($links, $errors, $reject, 0, 1000);
        if ($count > 0) {
            foreach ($links as $j=>$link) {
                $res = @mysqli_reap_async_query($links[$j]);
                foreach ($all_links as $i=>$link_orig) if ($all_links[$i] === $links[$j]) break;
                if ($link->error) {
                    echo "ERROR: {$link->error}\n";
                    if (!mysqli_ping($link)) {
                        echo "ERROR: mysql connection is down, removing it from the pool\n";
                        unset($all_links[$i]); // remove the original link from the pool
                        unset($requests[$i]); // and from the $requests too
                    }
                    return false;
                }
                if ($res === false and !$link->error) continue;
                if (is_object($res)) {
                    mysqli_free_result($res);
                }
                $requests[$i] = microtime(true);
		mysqli_query($link, $query, MYSQLI_ASYNC); // making next query
                return true;
            }
        };
    } while (true);
    return true;
}

$all_links = [];
$requests = [];
$c = 0;
for ($i=0;$i<$argv[2];$i++) {
  $m = @mysqli_connect('manticoresearch', '', '', '', 9306);
      if (mysqli_connect_error()) die("Cannot connect to Manticore\n");
      $all_links[] = $m;
  }

// init
mysqli_query($all_links[0], "drop table if exists user");
mysqli_query($all_links[0], "create table user(name text, email string, description text, age int, active bit(1))");

$batch = [];
$query_start = "insert into user(id, name, email, description, age, active) values ";

$faker = Faker\Factory::create();

echo "preparing...\n";
$error = false;
$cache_file_name = '/tmp/'.md5($query_start).'_'.$argv[1].'_'.$argv[3];
$csv_file_name = '/tmp/csv_'.$argv[3];
$c = 0;
if (!file_exists($cache_file_name) or !file_exists($csv_file_name)) {
    $batches = [];
    while ($c < $argv[3]) {
      $ar = [addslashes($faker->name()), addslashes($faker->email()), addslashes($faker->text()), rand(10,90), rand(0,1)];
      file_put_contents($csv_file_name, ($c+1).",".$ar[0].",".$ar[1].",".$ar[2].",".$ar[3].",".$ar[4]."\n", FILE_APPEND);
      $batch[] = "(0,'".$ar[0]."','".$ar[1]."','".$ar[2]."',".$ar[3].",".$ar[4].")";
      $c++;
      if (floor($c/1000) == $c/1000) echo "\r".($c/$argv[3]*100)."%       ";
        if (count($batch) == $argv[1]) {
          $batches[] = $query_start.implode(',', $batch);
          $batch = [];
        }
    }
    if ($batch) $batches[] = $query_start.implode(',', $batch);
    file_put_contents($cache_file_name, serialize($batches));
} else {
    echo "found in cache\n";
    $batches = unserialize(file_get_contents($cache_file_name));
}

echo "querying...\n";

$t = microtime(true);

foreach ($batches as $batch) {
  if (!process($batch)) die("ERROR\n");
}

// wait until all the workers finish
do {
  $links = $errors = $reject = array();
  foreach ($all_links as $link)  $links[] = $errors[] = $reject[] = $link;
  $count = @mysqli_poll($links, $errors, $reject, 0, 100);
} while (count($all_links) != count($links) + count($errors) + count($reject));

echo "finished inserting\n";
echo "Total time: ".(microtime(true) - $t)."\n";
echo round($argv[3] / (microtime(true) - $t))." docs per sec\n";
