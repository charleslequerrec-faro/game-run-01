<?php
/*
 * Fast Fox! : classement commun.
 * Les scores sont gardés dans scores.txt, dans le même dossier que ce fichier.
 *
 * GET  scores.php       -> renvoie les 20 meilleurs scores
 * POST scores.php       -> enregistre un score, renvoie les 20 meilleurs scores
 *      corps JSON : {"n":"Prénom","c":0,"m":1234,"k":56}
 *      n = nom (12 caractères max), c = personnage (0 à 5),
 *      m = mètres parcourus, k = pièces ramassées. Le total est calculé ici : m + k * 10.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$FILE  = __DIR__ . '/scores.txt';     // le fichier des scores
$RATE  = __DIR__ . '/limite.txt';     // envois des 60 dernières secondes, effacés au fur et à mesure
$KEEP  = 50;                          // scores gardés dans le fichier
$SHOW  = 20;                          // scores renvoyés au jeu
$WAIT  = 5;                           // secondes minimum entre deux envois d'une même adresse IP

function read_scores($fp) {
  rewind($fp);
  $raw = stream_get_contents($fp);
  $list = json_decode($raw ?: '[]', true);
  return is_array($list) ? $list : [];
}
/* noms grossiers : le nom est remplacé par « Anonyme », le score est gardé */
function clean_name($name) {
  $t = mb_strtolower($name, 'UTF-8');
  $t = strtr($t, ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c',
                  '0'=>'o','1'=>'i','3'=>'e','4'=>'a','5'=>'s','7'=>'t','@'=>'a','$'=>'s','€'=>'e']);
  $joined = preg_replace('/[^a-z]/', '', $t);              // « m.e.r.d.e » -> « merde »
  $words  = preg_split('/[^a-z]+/', $t, -1, PREG_SPLIT_NO_EMPTY);
  // mots repérés même collés à d'autres lettres
  $inside = ['merde','putain','salope','salaud','encule','enculer','connard','connasse','batard','bordel','pouffiasse','nique','niquer','ntm','fdp','tg',
             'fuck','shit','bitch','asshole','dick','pussy','cunt','nazi','hitler','pedo','porn','porno','sexe','sex'];
  // mots courts repérés seulement seuls, pour ne pas bloquer « Constance » ou « Pascal »
  $alone  = ['con','cons','conne','cul','bite','pute','putes','teub','zizi','chier','gay','pd'];
  foreach ($inside as $w) { if ($w !== 'tg' && $w !== 'ntm' && $w !== 'fdp' && strpos($joined, $w) !== false) return 'Anonyme'; }
  foreach ($words as $w) { if (in_array($w, $alone, true) || in_array($w, ['tg','ntm','fdp'], true)) return 'Anonyme'; }
  return $name;
}
function public_list($list, $n) {
  $out = [];
  foreach (array_slice($list, 0, $n) as $s) {
    $out[] = ['id' => $s['id'], 'n' => $s['n'], 'd' => $s['d'], 'c' => $s['c']];
  }
  return $out;
}

$fp = fopen($FILE, 'c+');
if (!$fp) { http_response_code(500); echo json_encode(['error' => 'fichier inaccessible']); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  flock($fp, LOCK_SH);
  $list = read_scores($fp);
  flock($fp, LOCK_UN); fclose($fp);
  echo json_encode(['top' => public_list($list, $SHOW)]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['error' => 'données invalides']); exit; }

/* vérifications : nom, personnage, distance et pièces plausibles */
$name = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['n'] ?? ''))));
$name = mb_substr($name, 0, 12, 'UTF-8');
$name = clean_name($name);
$char = (int)($in['c'] ?? -1);
$m    = (int)($in['m'] ?? -1);
$k    = (int)($in['k'] ?? -1);
if ($name === '' || $char < 0 || $char > 5 || $m < 0 || $m > 200000 || $k < 0 || $k > 20000) {
  http_response_code(400); echo json_encode(['error' => 'score refusé']); exit;
}

/* une même connexion ne peut pas envoyer plus d'un score toutes les $WAIT secondes.
   L'empreinte de l'adresse IP n'est gardée que 60 secondes, dans limite.txt, jamais avec les scores. */
$rp = fopen($RATE, 'c+');
if ($rp) {
  flock($rp, LOCK_EX);
  $seen = json_decode(stream_get_contents($rp) ?: '{}', true);
  if (!is_array($seen)) $seen = [];
  $now = time();
  foreach ($seen as $h => $t0) { if ($now - $t0 > 60) unset($seen[$h]); }
  $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d') . __FILE__);
  if (isset($seen[$ip]) && $now - $seen[$ip] < $WAIT) {
    flock($rp, LOCK_UN); fclose($rp); fclose($fp);
    http_response_code(429); echo json_encode(['error' => 'trop rapide']); exit;
  }
  $seen[$ip] = $now;
  ftruncate($rp, 0); rewind($rp); fwrite($rp, json_encode($seen)); fflush($rp);
  flock($rp, LOCK_UN); fclose($rp);
}

flock($fp, LOCK_EX);
$list = read_scores($fp);
/* les anciens scores perdent leur empreinte IP */
foreach ($list as &$s0) { unset($s0['ip']); }
unset($s0);

$id = bin2hex(random_bytes(6));
$list[] = ['id' => $id, 'n' => $name, 'd' => $m + $k * 10, 'c' => $char, 't' => time()];
usort($list, function ($a, $b) { return $b['d'] <=> $a['d']; });
$list = array_slice($list, 0, $KEEP);

ftruncate($fp, 0); rewind($fp);
fwrite($fp, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN); fclose($fp);

echo json_encode(['id' => $id, 'top' => public_list($list, $SHOW)]);
