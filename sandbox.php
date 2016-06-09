<?php

require 'vendor/autoload.php';

$sbGroupId = 16966080;
$publicGrp = 9639669;

$bot = '209d05186d7640f0608ee51a10';

$c = new GroupMeApi\Client('tQdn8W7f4dUtSWQ0UsHKRgGYcnpIFwPHhefpM3pJ');

$group = 16966080;
$msg = "Hello and welcome @moonwatcher de! This is a sample message from a little script I am testing.";
$user = "moonwatcher";

$bid = $c->getBotIdInGroup('Watchcat', $sbGroupId);

print_r($bid);

$c = NULL;

?>