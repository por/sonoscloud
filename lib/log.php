<?php

function logMsg($msg) {
  $msg = is_string($msg) ? $msg : var_export($msg, true);
  error_log($msg . "\r\n", 3, "/tmp/SonosAPI.log");
}

?>
