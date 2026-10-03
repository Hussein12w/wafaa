<?php
require __DIR__.'/config.php';
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE);exit;}
$dir=__DIR__.'/data';
if(!is_dir($dir))@mkdir($dir,0755,true);
try{
  $db=new PDO('sqlite:'.$dir.'/quiz.sqlite');
  $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $db->exec("CREATE TABLE IF NOT EXISTS questions(id INTEGER PRIMARY KEY AUTOINCREMENT,text TEXT,options TEXT,correct INTEGER,level INTEGER,created INTEGER);
  CREATE TABLE IF NOT EXISTS results(id INTEGER PRIMARY KEY AUTOINCREMENT,sid TEXT,name TEXT,qid INTEGER,level INTEGER,ok INTEGER,ts INTEGER);");
}catch(Exception $e){out(['error'=>'Database error. Make sure PHP SQLite (pdo_sqlite) is enabled and data/ is writable.'],500);}
$a=$_GET['a']??'';
$post=$_SERVER['REQUEST_METHOD']==='POST';
if($post&&stripos($_SERVER['CONTENT_TYPE']??'','application/json')===false)out(['error'=>'bad request'],400);
$b=$post?(json_decode(file_get_contents('php://input'),true)?:[]):[];

function state($db,$sid){
  $s=$db->prepare("SELECT DISTINCT qid FROM results WHERE sid=? AND ok=1");$s->execute([$sid]);
  $done=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
  $s=$db->prepare("SELECT COUNT(*) FROM results r WHERE sid=? AND ok=1 AND NOT EXISTS(SELECT 1 FROM results x WHERE x.sid=r.sid AND x.qid=r.qid AND x.id<r.id)");
  $s->execute([$sid]);$score=(int)$s->fetchColumn();
  $qs=$db->query("SELECT id,text,options,level FROM questions ORDER BY level,id")->fetchAll(PDO::FETCH_ASSOC);
  $cur=null;foreach($qs as $q){if(!in_array((int)$q['id'],$done)){$cur=$q;break;}}
  $st=['score'=>$score,'empty'=>count($qs)==0,'finished'=>count($qs)>0&&!$cur,'question'=>null];
  if($cur){
    $lv=(int)$cur['level'];$tot=0;$dn=0;
    foreach($qs as $q)if((int)$q['level']==$lv){$tot++;if(in_array((int)$q['id'],$done))$dn++;}
    $st['level']=$lv;$st['levelTotal']=$tot;$st['levelDone']=$dn;
    $st['question']=['id'=>(int)$cur['id'],'text'=>$cur['text'],'options'=>json_decode($cur['options'],true)];
  }
  return $st;
}
function admin(){if(empty($_SESSION['admin']))out(['error'=>'auth'],401);}

switch($a){
case 'state':
  $sid=substr(preg_replace('/[^a-z0-9]/i','',$_GET['sid']??''),0,32);
  if(!$sid)out(['error'=>'sid'],400);
  out(state($db,$sid));
case 'answer':
  $sid=substr(preg_replace('/[^a-z0-9]/i','',$b['sid']??''),0,32);
  $name=mb_substr(trim(strip_tags($b['name']??'')),0,60);
  if(!$sid||$name==='')out(['error'=>'bad'],400);
  $st=state($db,$sid);$qid=(int)($b['qid']??0);
  if(!$st['question']||$st['question']['id']!==$qid)out(['ok'=>false,'stale'=>true,'state'=>$st]);
  $q=$db->prepare("SELECT correct,level FROM questions WHERE id=?");$q->execute([$qid]);$q=$q->fetch(PDO::FETCH_ASSOC);
  $ok=(int)($b['choice']??-1)===(int)$q['correct'];
  $db->prepare("INSERT INTO results(sid,name,qid,level,ok,ts) VALUES(?,?,?,?,?,?)")->execute([$sid,$name,$qid,$q['level'],$ok?1:0,time()]);
  $new=state($db,$sid);
  out(['ok'=>$ok,'levelComplete'=>$ok&&(!$new['question']||$new['level']>(int)$q['level']),'state'=>$new]);
case 'login':
  usleep(700000);
  if(hash_equals(ADMIN_PASSWORD,(string)($b['password']??''))){session_regenerate_id(true);$_SESSION['admin']=1;out(['ok'=>true]);}
  out(['error'=>'Wrong password'],401);
case 'logout':$_SESSION=[];session_destroy();out(['ok'=>true]);
case 'admin_data':
  admin();
  $r=$db->query("SELECT sid,MAX(name) name,COUNT(*) attempts,SUM(ok) correct,MAX(CASE WHEN ok=1 THEN level END) level,MAX(ts) last FROM results GROUP BY sid ORDER BY correct DESC")->fetchAll(PDO::FETCH_ASSOC);
  foreach($r as &$x){$s=$db->prepare("SELECT COUNT(*) FROM results r WHERE sid=? AND ok=1 AND NOT EXISTS(SELECT 1 FROM results x WHERE x.sid=r.sid AND x.qid=r.qid AND x.id<r.id)");$s->execute([$x['sid']]);$x['score']=(int)$s->fetchColumn();}
  $qs=$db->query("SELECT q.id,q.text,q.options,q.correct,q.level,COUNT(r.id) attempts,COALESCE(SUM(r.ok),0) correct_n FROM questions q LEFT JOIN results r ON r.qid=q.id GROUP BY q.id ORDER BY q.level,q.id")->fetchAll(PDO::FETCH_ASSOC);
  foreach($qs as &$q)$q['options']=json_decode($q['options'],true);
  $t=$db->query("SELECT COUNT(*) a,COALESCE(SUM(ok),0) c,COUNT(DISTINCT sid) s FROM results")->fetch(PDO::FETCH_ASSOC);
  out(['students'=>$r,'questions'=>$qs,'totals'=>$t]);
case 'q_add':
  admin();
  $t=trim($b['text']??'');$o=array_values(array_filter(array_map('trim',(array)($b['options']??[])),'strlen'));
  $c=(int)($b['correct']??-1);$lv=max(1,(int)($b['level']??1));
  if($t===''||count($o)<2||$c<0||$c>=count($o)||count($o)>6)out(['error'=>'Enter a question, 2-6 answers and mark the correct one'],400);
  $db->prepare("INSERT INTO questions(text,options,correct,level,created) VALUES(?,?,?,?,?)")->execute([$t,json_encode($o,JSON_UNESCAPED_UNICODE),$c,$lv,time()]);
  out(['ok'=>true]);
case 'q_del':
  admin();$id=(int)($b['id']??0);
  $db->prepare("DELETE FROM questions WHERE id=?")->execute([$id]);
  $db->prepare("DELETE FROM results WHERE qid=?")->execute([$id]);
  out(['ok'=>true]);
case 'reset_results':
  admin();$db->exec("DELETE FROM results");out(['ok'=>true]);
default:out(['error'=>'unknown'],404);
}
