generate a Multiple Picture Object files (.MPO) 3D picture from two jpeg files.

This software implement in PHP the CIPA's MPO official reference document
available at the following URL: http://www.cipa.jp/std/documents/download_e.html?DC-007_E

Nintendo 3DS Users can see the generated test 3D file here
on github by clicking the out.MPO file then "View Raw".

# Code comment and annotations

The code is documented and annoted with the chapter and paragraph references to the CIPA manual.

The adresses where the data will be written in the file created with the sample left.jpg and right.jpg are annoted in the comments.
(e.g @0x42). Theses values may be differant with another input files.

# Usage

## Command line

This PHP script can be used with a Command Line Interface by calling cli.php with the php binary : 

* Command line arguments:

<pre>
	-l FILENAME : left jpg file (mandatory argument)
	-r FILENAME : right jpg file (mandatory argument)
	-o FILENAME : output MPO file name to produce (defaults to out.mpo)
</pre>

* example :

```
 php cli.php -l left.jpg -r right.jpg -o out.MPO
```

## Web server
It also can be used with a web server like this:

 Assuming the requets has a file_left and a file_right parameters from an HTML form with two file inputs.

```PHP
<?php
require($_SERVER['DOCUMENT_ROOT'].'mpo.php');
try {
    $filename_left = $_FILES['file_left']['tmp_name'];
    $filename_right = $_FILES['file_right']['tmp_name'];
    $img_data_left = file_get_contents($filename_left);
    $img_data_right = file_get_contents($filename_right);
    $filename_out = 'images/tmp/'.uniqid().'.MPO';
    to_mpo($img_data_left, $img_data_right, $filename_out);
    echo '{"response" : "ok", "outfile" : "'.$filename_out.'"}';

} catch (Exception $e) {
    $res = sprintf('{"response" : "error", "msg" : "%s"}', e->getMessage());
}
?>
```

# Testing

I used an hexadecimal editor (EMACS hexl-mode) to check the generated file if the data where correct

# 3DS limitations

The 3DS can not read 3D pictures with a width superior at 700px.
