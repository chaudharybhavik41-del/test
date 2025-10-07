<?php
/** PATH: /public_html/tools/_verify_config.php */
header('Content-Type: text/plain; charset=utf-8');

$cfg = __DIR__ .'/../config.php'; 
if (!is_file($cfg)) { echo "includes/config.php MISSING\n"; exit; }

$txt = file_get_contents($cfg);
echo "has_short_open_tag? ", (strpos($txt, '<?php') === false && strpos($txt, '<?') !== false ? "YES (BAD)\n" : "no\n");

require_once $cfg;

$need = ['DB_HOST','DB_NAME','DB_USER','DB_PASS'];
foreach ($need as $c) {
  echo $c, ': ', defined($c) ? 'OK' : 'MISSING', "\n";
}
