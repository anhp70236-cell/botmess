<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$ID='1299694183099142160';
$dir=__DIR__.'/_data'; if(!is_dir($dir))@mkdir($dir,0775,true);

function jget($u){
  $r=null;
  if(function_exists('curl_init')){
    $c=curl_init($u);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>7,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_USERAGENT=>'iosmods-profile/2.0',CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $r=curl_exec($c); curl_close($c);
  } else {
    $ctx=stream_context_create(['http'=>['timeout'=>7,'header'=>"User-Agent: iosmods-profile/2.0\r\nAccept: application/json\r\n"]]);
    $r=@file_get_contents($u,false,$ctx);
  }
  return $r?json_decode($r,true):null;
}
function cached($file,$ttl,$fn){
  if(is_file($file)&&time()-filemtime($file)<$ttl){$j=json_decode(@file_get_contents($file),true);if(is_array($j))return $j;}
  $v=$fn();
  if(is_array($v)){@file_put_contents($file,json_encode($v),LOCK_EX);return $v;}
  if(is_file($file)){$j=json_decode(@file_get_contents($file),true);if(is_array($j))return $j;}
  return null;
}

$ly=cached("$dir/presence.json",10,function()use($ID){$j=jget("https://api.lanyard.rest/v1/users/$ID");return(is_array($j)&&!empty($j['success']))?$j['data']:null;});
$pf=cached("$dir/profile.json",900,function()use($ID){$j=jget("https://dcdn.dstn.to/profile/$ID");return(is_array($j)&&isset($j['user']))?$j:null;});

if($ly===null&&$pf===null){echo json_encode(['ok'=>false]);exit;}

$lu=$ly['discord_user']??[]; $pu=$pf['user']??[]; $up=$pf['user_profile']??[];
$img=function($base,$h,$q){return $h?("https://cdn.discordapp.com/$base/$h.".(strpos($h,'a_')===0?'gif':'webp')."?size=$q"):null;};

$decoAsset=$lu['avatar_decoration_data']['asset']??($pu['avatar_decoration_data']['asset']??null);
$bc=$pu['banner_color']??null;
if(!$bc&&isset($up['accent_color']))$bc=sprintf('#%06x',(int)$up['accent_color']);
if(!$bc&&!empty($up['theme_colors'][0]))$bc=sprintf('#%06x',(int)$up['theme_colors'][0]);
if($bc&&!preg_match('/^#[0-9a-f]{6}$/i',$bc))$bc=null;

$badges=[];
foreach(($pf['badges']??[])as $b){
  $ic=$b['icon']??($b['image']??($b['asset']??null));
  if(!$ic)continue;
  if(preg_match('#^https?://#',$ic))$u=$ic;
  elseif(preg_match('#^[0-9a-f]{16,}$#i',$ic))$u='https://cdn.discordapp.com/badge-icons/'.$ic.'.png';
  else $u='https://cdn.discordapp.com/'.ltrim($ic,'/');
  $badges[]=['name'=>$b['description']??($b['id']??''),'icon'=>$u];
}

$custom=null;$act=null;
foreach(($ly['activities']??[])as $a){
  if(($a['type']??-1)==4){
    $e=$a['emoji']??null;$eo=null;
    if($e){$eo=['name'=>$e['name']??'','url'=>!empty($e['id'])?("https://cdn.discordapp.com/emojis/{$e['id']}.".(!empty($e['animated'])?'gif':'webp')."?size=32"):null];}
    $custom=['text'=>$a['state']??'','emoji'=>$eo];
  } elseif($act===null){
    $act=['type'=>$a['type']??0,'name'=>$a['name']??'','details'=>$a['details']??null];
  }
}

$tag=null;$g=$lu['primary_guild']??null;
if($g&&!empty($g['identity_enabled'])&&!empty($g['tag'])){
  $tag=['text'=>$g['tag'],'badge'=>(!empty($g['badge'])&&!empty($g['identity_guild_id']))?"https://cdn.discordapp.com/clan-badges/{$g['identity_guild_id']}/{$g['badge']}.png?size=32":null];
}

$dev=[];
if(!empty($ly['active_on_discord_mobile']))$dev[]='Mobile';
if(!empty($ly['active_on_discord_desktop']))$dev[]='Desktop';
if(!empty($ly['active_on_discord_web']))$dev[]='Web';

echo json_encode([
  'ok'=>true,
  'username'=>$lu['username']??($pu['username']??'ios_b'),
  'name'=>$lu['global_name']??($pu['global_name']??($lu['username']??'ob')),
  'avatar'=>$img("avatars/$ID",$lu['avatar']??($pu['avatar']??null),256),
  'deco'=>$decoAsset?"https://cdn.discordapp.com/avatar-decoration-presets/$decoAsset.png?passthrough=true":null,
  'banner'=>$img("banners/$ID",$pu['banner']??null,600),
  'banner_color'=>$bc,
  'badges'=>$badges,
  'tag'=>$tag,
  'status'=>$ly['discord_status']??'offline',
  'devices'=>$dev,
  'custom'=>$custom,
  'activity'=>$act
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
