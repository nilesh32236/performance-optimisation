<?php
$comments = json_decode(file_get_contents("php://stdin"), true);
print_r($comments);
