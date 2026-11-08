<?php
/**
 * Test: App-Passwort statt Login-Passwort (simulierte Nextcloud, ohne WordPress).
 * Aufruf: php tests/php/test-app-password.php
 */
define('ABSPATH','/'); define('SPS_VERSION','t');
$R = dirname( __DIR__, 2 );
class WP_Error { public $c,$m,$d; function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} function get_error_code(){return $this->c;} function get_error_message(){return $this->m;} function get_error_data(){return $this->d;} }
function is_wp_error($x){return $x instanceof WP_Error;}
$META=[]; $LOG=[];
function get_user_meta($u,$k,$s=true){global $META; return $META[$u][$k] ?? '';}
function update_user_meta($u,$k,$v){global $META; $META[$u][$k]=$v;}
function delete_user_meta($u,$k){global $META; unset($META[$u][$k]);}
function current_time($x){return '2026-11-08 16:00:00';}
function wp_generate_password(){return 'gen'.mt_rand(1000,9999);}
function get_userdata($id){ return (object)['ID'=>$id,'user_email'=>'kunde@example.org','display_name'=>'Kunde','first_name'=>'','last_name'=>'','user_login'=>'k']; }
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function wp_json_encode($x){return json_encode($x);}
function add_action(){} function add_filter(){} function get_option($k,$d=null){return $d;}
class SPS_Settings { static function get_setting($k,$d=''){ return ['nextcloud_url'=>'https://nc','service_account'=>'sps_service','app_password'=>'svc','nc_sync_auto_groups'=>'Hauseigner','nc_db_host'=>''][$k] ?? $d; } static function encrypt($v){return 'enc:'.$v;} static function decrypt($v){return substr($v,4);} }
class SPS_Diagnostics { static function log($m,$l='info',$c=[]){ global $LOG; $LOG[]="[$l] $m"; } }
// Fake Nextcloud
$NC = ['users'=>['kunde@example.org'=>'altpw'], 'tokens'=>[] , 'calls'=>[]];
function ocs($code,$st,$data=[]){ return ['code'=>$code,'body'=>json_encode(['ocs'=>['meta'=>['statuscode'=>$st,'message'=>$code==401?'Unauthorised':''],'data'=>$data]])]; }
function wp_remote_request($url,$a){ global $NC;
  [$u,$p] = explode(':', base64_decode(substr($a['headers']['Authorization'],6)),2);
  $path = parse_url($url,PHP_URL_PATH); $NC['calls'][]="$a[method] $path ($u)";
  $isSvc = ($u==='sps_service'&&$p==='svc');
  if (strpos($path,'core/getapppassword')!==false){ if(($NC['users'][$u]??null)!==$p) return ocs(401,997); $t='tok'.count($NC['tokens']); $NC['tokens'][$t]=$u; return ocs(200,200,['apppassword'=>$t]); }
  if (strpos($path,'core/apppassword')!==false && $a['method']==='DELETE'){ if(isset($NC['tokens'][$p])){unset($NC['tokens'][$p]); return ocs(200,200);} return ocs(401,997); }
  if (!$isSvc) return ocs(401,997);
  if (preg_match('#cloud/users/([^/?]+)$#',$path,$m) && $a['method']==='GET'){ $id=rawurldecode($m[1]); return isset($NC['users'][$id])?ocs(200,100,['id'=>$id]):ocs(404,998); }
  if (preg_match('#cloud/users/([^/?]+)$#',$path,$m) && $a['method']==='PUT'){ parse_str($a['body'],$b); $NC['users'][rawurldecode($m[1])]=$b['value']; return ocs(200,100); }
  if (preg_match('#cloud/users$#',$path)&&$a['method']==='POST'){ $b=json_decode($a["body"],true); if(!$b) parse_str($a["body"],$b); $NC['users'][$b['userid']]=$b['password']; return ocs(200,100); }
  return ocs(200,100,['groups'=>[]]);
}
require "$R/modules/nextcloud/class-sps-nc-client.php";
require "$R/modules/auth/class-sps-nc-user-sync.php";
$S = SPS_NC_User_Sync::get_instance();
$ok=0;$f=0; function check($n,$c){global $ok,$f; echo ($c?"ok  ":"FAIL")." $n\n"; $c?$ok++:$f++;}

// A: Bestandskonto mit gültigem Login-Passwort → Tausch gegen App-Passwort
$META[1]=['nextcloud_username'=>'kunde@example.org','nextcloud_password_enc'=>'enc:altpw','nextcloud_linked'=>'1'];
$S->sync_user(1);
check('A: Typ app', $META[1]['nextcloud_password_type']==='app');
check('A: App-Passwort gespeichert', $META[1]['nextcloud_password_enc']==='enc:tok0');
// Kunde ändert Passwort → Client mit App-Passwort funktioniert weiter (Fake: Token bleibt)
$NC['users']['kunde@example.org']='neuesPW';
$c = new SPS_NC_Client('',1); check('A: Client nutzt App-Passwort, nicht Service', !$c->is_service_account() && $c->get_username()==='kunde@example.org');
// B: Erzwungener Sync (Account-Sync) bei gültigem App-Passwort → keine Passwortänderung (keine Mail an den Kunden)
$n=count($NC['calls']); $S->sync_user(1,true); $later=array_slice($NC['calls'],$n);
check('B: kein Passwort gesetzt', !preg_grep('#^PUT .*cloud/users/#',$later));
check('B: App-Passwort bleibt', $META[1]['nextcloud_password_enc']==='enc:tok0' && isset($NC['tokens']['tok0']));
// B2: Erzwungener Sync bei unerkannt ungültigem Login-Passwort → einmal ersetzen, danach App-Passwort
$META[4]=['nextcloud_username'=>'kunde@example.org','nextcloud_password_enc'=>'enc:falsch','nextcloud_linked'=>'1'];
$n=count($NC['calls']); $S->sync_user(4,true); $later=array_slice($NC['calls'],$n);
check('B2: genau ein Passwort gesetzt', 1===count(preg_grep('#^PUT .*cloud/users/#',$later)));
check('B2: danach App-Passwort', ($META[4]['nextcloud_password_type']??'')==='app');
// C: veraltetes Login-Passwort → stale, keine Endlosschleife
$META[2]=['nextcloud_username'=>'kunde@example.org','nextcloud_password_enc'=>'enc:falsch','nextcloud_linked'=>'1'];
$r=$S->sync_user(2); check('C: stale markiert', $META[2]['nextcloud_password_type']==='stale' && $r['success']);
$n=count($NC['calls']); $S->sync_user(2); $later=array_slice($NC['calls'],$n);
check('C: kein erneuter Tauschversuch', !preg_grep('/getapppassword/',$later));
// D: neues Konto
unset($NC['users']['kunde@example.org']); $META[3]=[];
$S->sync_user(3); check('D: neues Konto bekommt App-Passwort', ($META[3]['nextcloud_password_type']??'')==='app' && strpos($META[3]['nextcloud_password_enc'],'enc:tok')===0);
// E: User-Agent/Name
check('E: Gerätename gesetzt', SPS_NC_Client::APP_PASSWORD_NAME !== '');
echo "\n$ok ok, $f fehlgeschlagen\n";
exit( $f ? 1 : 0 );
