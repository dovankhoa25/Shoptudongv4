<?php
namespace App\Services;

final class GoldBarAsset {
    public const STANDARD=457, NRJ=2032, CAPACITY=999;
    public static function template(int|string|null $id=null): int {
        $id=(int)($id??self::STANDARD);
        if(!in_array($id,[self::STANDARD,self::NRJ],true))throw new \InvalidArgumentException('Loại thỏi vàng không hợp lệ.');
        return $id;
    }
    public static function value(int|string|null $id=null): int {return self::template($id)===self::NRJ?195_000_000:37_000_000;}
    public static function field(int|string|null $id=null): string {return self::template($id)===self::NRJ?'nrj_gold_bar_qty':'gold_bar_qty';}
}
