<?php
require 'vendor/autoload.php';
require 'src/webscraper.php';

//Defining Long Options
$longopts  = array(
    "startDate::",
    "endDate::",
    "concurrency::",
    "maxResultsPerAuthor::",
    "wait::",
);

// We use null for short options since we don't have short options
$config = getopt(null, $longopts);
$grbj = new Scrapper();
foreach($config as $attribute => $value) {
    $grbj->$attribute = $value;
}
print_r($grbj->getData());
