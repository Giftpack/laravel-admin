<?php
// Additional proof: real native exporter send/exit over loopback HTTP, separate from the historical substitute.
$sock=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($sock,false);fclose($sock);
$metrics=tempnam('/tmp','export-metrics');$env=getenv();$env['BENCHMARK_METRICS']=$metrics;
$log=tempnam('/tmp','export-log');$process=proc_open([PHP_BINARY,'-d','display_errors=0','-S',$address,'/application/scripts/benchmark-release-router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,'/application',$env);
try {
 for($i=0;$i<300;$i++){if($s=@stream_socket_client('tcp://'.$address,$errno,$error,.05)){fclose($s);break;}usleep(10000);}
 $expectedRows=DB::table('test_users')->get()->keyBy('id');$runs=[];
 for($run=0;$run<6;$run++){
  $start=hrtime(true);$context=stream_context_create(['http'=>['timeout'=>60,'ignore_errors'=>true]]);$body=file_get_contents('http://'.$address.'/admin/users?_export_=all',false,$context);$elapsed=round((hrtime(true)-$start)/1e6,3);
  if(!str_contains(implode(' ',http_get_last_response_headers()),'text/csv')||!str_starts_with($body,"\xEF\xBB\xBF"))throw new RuntimeException('Native CSV response '.substr($body,0,200));
  file_put_contents('/results/issue20-native-'.$mode.'-'.getenv('DB_CONNECTION').'.body',$body);file_put_contents('/results/issue20-native-'.$mode.'-'.getenv('DB_CONNECTION').'.server.log',file_get_contents($log));
  $stream=fopen('php://temp','w+');fwrite($stream,substr($body,3));rewind($stream);$head=fgetcsv($stream,null,',','"','');$rows=[];while(($row=fgetcsv($stream,null,',','"',''))!==false)$rows[]=$row;fclose($stream);
  if(count($rows)!==10000||array_map('intval',array_column($rows,0))!==range(1,10000))throw new RuntimeException('Incomplete native 10k CSV '.json_encode(['rows'=>count($rows),'head'=>$head,'first'=>array_slice($rows,0,2),'last'=>array_slice($rows,-2)]));
  foreach($rows as$row){$expected=$expectedRows[(int)$row[0]];if($row!==[(string)$expected->id,$expected->username,$expected->email])throw new RuntimeException('CSV fidelity');}
  if($run>0)$runs[]=json_decode(file_get_contents($metrics),true)+['transport_ms'=>$elapsed,'bytes'=>strlen($body),'rows'=>count($rows),'sha256'=>hash('sha256',$body)];
 }
 $times=array_column($runs,'milliseconds');sort($times);$output['measurements']['csv_native_send_exit_10000']=['median_ms'=>$times[2],'runs'=>$runs,'transport'=>'Native HTTP response send/exit; complete CSV reopened and every row compared'];
 $output['native_transport_diagnostics']=file_get_contents($log);
}finally{proc_terminate($process);proc_close($process);unlink($metrics);unlink($log);}
