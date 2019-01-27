<?php
require('mpo.php');
//default values
$filename_out = "out.mpo";

//CLI options
$options = getopt("l:r:o:");
$filename_left = $options["l"];
$filename_right = $options["r"];
$filename_out = $options["o"];

$img_data_left = file_get_contents($filename_left);
$img_data_right = file_get_contents($filename_right);

to_mpo($img_data_left, $img_data_right, $filename_out);

?>
