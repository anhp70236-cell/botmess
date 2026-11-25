<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$BASE=3490;
$f=__DIR__.'/_data/views.php';
$G="<?php http_response_code(404);exit;?>\n";
$ua=$_SERVER['HTTP_USER_AGENT']??'';
$bot=($ua==='')||preg_match('/bot|crawl|spider|slurp|curl|wget|python|java|go-http|headless|preview|fetch|monitor|scan|axios|postman|discord|telegram|whatsapp|facebook|twitter|okhttp|libwww/i',$ua);
$post=($_SERVER['REQUEST_METHOD']??'')==='POST';
$fp=@fopen($f,'c+');
if(!$fp){echo json_encode(['ok'=>false,'views'=>$BASE]);exit;}
flock($fp,LOCK_EX);
$raw=stream_get_contents($fp);
$d=null;
if($raw!==''&&($p=strpos($raw,"\n"))!==false)$d=json_decode(substr($raw,$p+1),true);
if(!is_array($d))$d=['count'=>0,'salt'=>bin2hex(random_bytes(8)),'seen'=>[]];
$ip=$_SERVER['HTTP_CF_CONNECTING_IP']??($_SERVER['REMOTE_ADDR']??'');
$id=substr(hash('sha256',$d['salt'].$ip.$ua),0,16);
$counted=false;
if($post&&!$bot){
  if(!isset($_COOKIE['ob_v'])&&!isset($d['seen'][$id])){$d['count']++;$counted=true;}
  $d['seen'][$id]=time();
  setcookie('ob_v','1',['expires'=>time()+31536000,'path'=>'/ios_b/','samesite'=>'Lax','httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])]);
  ftruncate($fp,0);rewind($fp);fwrite($fp,$G.json_encode($d));fflush($fp);
}
flock($fp,LOCK_UN);fclose($fp);
echo json_encode(['ok'=>true,'views'=>$BASE+(int)$d['count'],'counted'=>$counted]);
