<?php
// Reproduces the two original fputcsv call signatures from immutable261a97aa.
$values=['comma,value','a"b','slash\\"quote','ends\\',"new\nline",'台灣🙂','',null,'0'];
$warnings=[];set_error_handler(function ($code,$message) use (&$warnings) { $warnings[]=$message;return true; });
$old=fopen('php://temp','w+');fputcsv($old,['Title']);fputcsv($old,$values);rewind($old);$oldWire=stream_get_contents($old);fclose($old);restore_error_handler();
$new=fopen('php://temp','w+');fputcsv($new,['Title'],',','"','');fputcsv($new,$values,',','"','');rewind($new);$newWire=stream_get_contents($new);rewind($new);fgetcsv($new,null,',','"','');$roundtrip=fgetcsv($new,null,',','"','');fclose($new);
if (count($warnings)!==2 || $roundtrip!==array_map(fn ($value)=>(string)$value,$values)) throw new RuntimeException('Unexpected wire characterization');
echo json_encode(['php'=>PHP_VERSION,'baseline_calls'=>['fputcsv($handle, $titles = $this->getVisiableTitles())','fputcsv($handle, $this->getVisiableFields($record, $original[$index]))'],'warnings'=>$warnings,'inputs'=>$values,'baseline_wire'=>$oldWire,'candidate_wire'=>$newWire,'candidate_roundtrip'=>$roundtrip,'wire_changed'=>$oldWire!==$newWire],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
