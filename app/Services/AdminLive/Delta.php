<?php
namespace App\Services\AdminLive;
class Delta {
    private static function rows(array $value): bool {
        if(!$value || !array_is_list($value)) return false;
        $ids=[];foreach($value as $row) {if(!is_array($row) || !isset($row['id']) || !is_scalar($row['id']))return false;$ids[]=(string)$row['id'];}
        return count(array_unique($ids))===count($ids);
    }
    public static function between(mixed $old,mixed $new,array $path=[]): array {
        if($old===$new)return [];
        if(is_array($old) && is_array($new) && (self::rows($old) || self::rows($new)) && ($old===[] || self::rows($old)) && ($new===[] || self::rows($new))) {
            $known=[];foreach($old as $row)$known[(string)$row['id']]=$row;
            $changed=[];foreach($new as $row)if(($known[(string)$row['id']] ?? null)!==$row)$changed[]=$row;
            return [['op'=>'rows','path'=>$path,'order'=>array_column($new,'id'),'rows'=>$changed]];
        }
        if(is_array($old) && is_array($new) && !array_is_list($old) && !array_is_list($new)) {
            $ops=[];foreach($new as $key=>$value)$ops=[...$ops,...self::between($old[$key] ?? null,$value,[...$path,$key])];
            foreach(array_diff(array_keys($old),array_keys($new)) as $key)$ops[]=['op'=>'remove','path'=>[...$path,$key]];
            return $ops;
        }
        return [['op'=>'set','path'=>$path,'value'=>$new]];
    }
}
