<?php
namespace App\Services;

final class GoldBarAsset {
    public const STANDARD=457, NRJ=2032, RADAR=281, CAPACITY=999;
    public static function template(int|string|null $id=null): int {
        $id=(int)($id??self::STANDARD);
        if(!in_array($id,[self::STANDARD,self::NRJ,self::RADAR],true))throw new \InvalidArgumentException('Loại vật phẩm vàng không hợp lệ.');
        return $id;
    }
    public static function value(int|string|null $id=null): int {return match(self::template($id)){self::NRJ=>195_000_000,self::RADAR=>37_500_000,default=>37_000_000};}
    public static function field(int|string|null $id=null): string {return match(self::template($id)){self::NRJ=>'nrj_gold_bar_qty',self::RADAR=>'radar_qty',default=>'gold_bar_qty'};}
    public static function roundLimit(int $template): int {return self::template($template)===self::RADAR?20:self::CAPACITY;}
}
