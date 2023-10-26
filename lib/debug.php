<?php
 class Debugger {
     static function debug($content)
     {
         $path = "/home/tdong6/debug.txt";
         // open with write and append
         ob_start();
         var_dump($content);
         $output = ob_get_clean();
         $handle = fopen($path, "a");
         fwrite($handle, $output . "\n");
         fclose($handle);
     }
}