#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=dirname(__DIR__,2); require_once $root.'/core/php/serial/bootstrap.php';
use Testkit\Core\Serial\SerialReadOnlyClient; use Testkit\Core\Serial\SerialReadOnlyException;
$errors=[]; $assert=static function(bool $c,string $m)use(&$errors){if(!$c)$errors[]=$m;};
$fixture=__DIR__.'/fixtures/serial_pty_writer.py';
$withPty=static function(string $payload, callable $fn, int $holdMs=300) use($fixture,$assert): void {
 $ready=sys_get_temp_dir().'/tk-serial-'.bin2hex(random_bytes(5)).'.tty'; $d=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
 $proc=proc_open(['python3',$fixture,'--ready='.$ready,'--payload-hex='.bin2hex($payload),'--delay-ms=120','--hold-ms='.$holdMs],$d,$pipes);
 if(!is_resource($proc)){ $assert(false,'unable to start PTY fixture'); return; } fclose($pipes[0]);
 $device=''; for($i=0;$i<100;$i++){ if(is_file($ready)){ $device=trim((string)file_get_contents($ready)); if($device!=='')break; } usleep(10000); }
 try { if($device==='') $assert(false,'PTY fixture did not publish slave'); else $fn($device); } finally { @unlink($ready); foreach([1,2] as $i) if(is_resource($pipes[$i])) fclose($pipes[$i]); proc_terminate($proc); proc_close($proc); }
};
$client=static fn(string $d,array $t=['CR','LF','CRLF'],int $timeout=500,int $max=64)=>new SerialReadOnlyClient($d,9600,8,1,'none','none',$timeout,$max,$t);
$withPty("ABC\r",function($d)use($client,$assert){$r=$client($d,['CR'])->captureFrame();$assert($r['frame']==='ABC'&&$r['evidence']['result']['terminator']==='CR','CR frame failed');});
$withPty("ABC\n",function($d)use($client,$assert){$r=$client($d,['LF'])->captureFrame();$assert($r['frame']==='ABC'&&$r['evidence']['result']['terminator']==='LF','LF frame failed');});
$withPty("ABC\r\n",function($d)use($client,$assert){$r=$client($d)->captureFrame();$assert($r['frame']==='ABC'&&$r['evidence']['result']['terminator']==='CRLF','CRLF frame failed');});
$withPty("ONE\rTWO\n",function($d)use($client,$assert){$c=$client($d,['CR','LF']);$a=$c->captureFrame();$b=$c->captureFrame();$assert($a['frame']==='ONE'&&$b['frame']==='TWO','consecutive frames failed');});
$withPty('',function($d)use($client,$assert){try{$client($d,['LF'],120)->captureFrame();$assert(false,'timeout accepted');}catch(SerialReadOnlyException $e){$assert($e->stage()==='timeout','timeout stage mismatch');}},250);
$withPty(str_repeat('X',12)."\n",function($d)use($client,$assert){try{$client($d,['LF'],500,8)->captureFrame();$assert(false,'overflow accepted');}catch(SerialReadOnlyException $e){$assert($e->stage()==='overflow','overflow stage mismatch');}});
try{$client('/tmp/definitely-missing-serial',['LF'])->captureFrame();$assert(false,'missing device accepted');}catch(SerialReadOnlyException $e){$assert($e->stage()==='device_error','missing device stage mismatch');}
$invalid=[
 fn()=>new SerialReadOnlyClient('/dev/null',12345,8,1,'none','none',100,10,['LF']),
 fn()=>new SerialReadOnlyClient('/dev/null',9600,9,1,'none','none',100,10,['LF']),
 fn()=>new SerialReadOnlyClient('/dev/null',9600,8,3,'none','none',100,10,['LF']),
 fn()=>new SerialReadOnlyClient('/dev/null',9600,8,1,'mark','none',100,10,['LF']),
 fn()=>new SerialReadOnlyClient('/dev/null',9600,8,1,'none','magic',100,10,['LF']),
]; foreach($invalid as $i=>$make){try{$make();$assert(false,'invalid config '.$i.' accepted');}catch(SerialReadOnlyException $e){$assert($e->stage()==='config_error','invalid config stage '.$i);}}
foreach((new ReflectionClass(SerialReadOnlyClient::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m){$assert(preg_match('/write|send|transmit|trigger|command/i',$m->getName())!==1,'public TX-like API '.$m->getName());}
$source=(string)file_get_contents($root.'/core/php/serial/SerialReadOnlyClient.php');$assert(stripos($source,'fwrite')===false,'client source contains fwrite');
$withPty("SAFE\n",function($d)use($client,$assert){$r=$client($d,['LF'])->captureFrame();$json=json_encode($r['evidence']);$assert(is_string($json)&&!str_contains($json,'SAFE'),'neutral evidence leaked payload');$assert(($r['evidence']['readonlyInvariant']??false)===true,'readonly invariant missing');});
if($errors!==[]){foreach($errors as $e)fwrite(STDERR,"FAIL $e\n");exit(1);} echo "PASS serial_readonly PTY=CR,LF,CRLF,consecutive timeout overflow config device readonly\n";
