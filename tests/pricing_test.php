<?php
/* tests/pricing_test.php — money-math regression tests (fix #15).
   Plain PHP (no PHPUnit needed). Run:  php tests/pricing_test.php
   Exits non-zero if anything fails, so it can gate a deploy/CI.
   Covers quote_price() (subtotal / per-line VAT / discount / cost / profit) and the
   project-cost VAT helpers (pc_exvat / pc_vat_amount / pc_gross / pc_vat_mode). */

require __DIR__ . '/../quote_pricing.php';   // quote_price()
require __DIR__ . '/../project_costs.php';   // pc_exvat/pc_vat_amount/pc_gross/pc_vat_mode

$PASS = 0; $FAIL = 0;
function check($cond, $label) {
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  ok   $label\n"; }
    else       { $FAIL++; echo "  FAIL $label\n"; }
}
$eq = function($a, $b) { return abs((float)$a - (float)$b) < 0.005; };   // float tolerance

$VAT = 0.16;

/* ---------- quote_price() ---------- */
// 1) single VAT line: qty2 x rate100, unit cost 30
[$items,$sub,$disc,$tax,$total,$cost,$profit] = quote_price(
    [['name'=>'X','qty'=>2,'rate'=>100,'cost'=>30,'tax'=>'vat']], $VAT, 0, 'percent');
check($eq($sub,200)   , 'sub=200');
check($eq($tax,32)    , 'tax=32 (16% of 200)');
check($eq($total,232) , 'total=232');
check($eq($cost,60)   , 'cost=60 (2 x 30)');
check($eq($profit,140), 'profit=140 (200-60)');
check(!empty($items[0]['lid']), 'line has a stable lid (fix #10)');

// 2) a "no VAT" line is excluded from the taxed base
[$i2,$s2,$d2,$t2,$to2] = quote_price(
    [['name'=>'A','qty'=>1,'rate'=>100,'tax'=>'vat'],
     ['name'=>'B','qty'=>1,'rate'=>100,'tax'=>'none']], $VAT, 0, 'percent');
check($eq($s2,200), 'mixed sub=200');
check($eq($t2,16) , 'mixed tax=16 (only the vat line taxed)');
check($eq($to2,216),'mixed total=216');

// 3) percent discount (before tax, spread across taxed lines)
[$i3,$s3,$d3,$t3,$to3,$c3,$p3] = quote_price(
    [['name'=>'X','qty'=>2,'rate'=>100,'cost'=>30,'tax'=>'vat']], $VAT, 10, 'percent');
check($eq($d3,20)   , 'pct disc amount=20');
check($eq($t3,28.8) , 'pct disc tax=28.8 (16% of 180)');
check($eq($to3,208.8),'pct disc total=208.8');
check($eq($p3,120)  , 'pct disc profit=120 (180-60)');

// 4) fixed-amount discount
[$i4,$s4,$d4,$t4,$to4,$c4,$p4] = quote_price(
    [['name'=>'X','qty'=>2,'rate'=>100,'cost'=>30,'tax'=>'vat']], $VAT, 50, 'amount');
check($eq($d4,50)  , 'amt disc=50');
check($eq($t4,24)  , 'amt disc tax=24 (16% of 150)');
check($eq($to4,174), 'amt disc total=174');
check($eq($p4,90)  , 'amt disc profit=90 (150-60)');

// 5) blank-name lines are dropped
[$i5] = quote_price([['name'=>'','qty'=>1,'rate'=>10],['name'=>'Real','qty'=>1,'rate'=>10]], $VAT, 0, 'percent');
check(count($i5) === 1, 'blank-name line dropped');

/* ---------- project-cost VAT helpers ---------- */
check(pc_vat_mode('excl')==='none', "vat_mode: legacy 'excl' -> 'none'");
check(pc_vat_mode('plus')==='plus' && pc_vat_mode('incl')==='incl', 'vat_mode: plus/incl kept');
check(pc_vat_mode('garbage')==='none', 'vat_mode: unknown -> none');

// none: no VAT
check($eq(pc_exvat(1000,'none',$VAT),1000) && $eq(pc_vat_amount(1000,'none',$VAT),0) && $eq(pc_gross(1000,'none',$VAT),1000), 'none: 1000/0/1000');
// plus: net + 16%
check($eq(pc_exvat(50000,'plus',$VAT),50000) && $eq(pc_vat_amount(50000,'plus',$VAT),8000) && $eq(pc_gross(50000,'plus',$VAT),58000), 'plus: 50000 -> net 50000 / VAT 8000 / gross 58000');
// incl: VAT already inside
check($eq(pc_exvat(11600,'incl',$VAT),10000) && $eq(pc_vat_amount(11600,'incl',$VAT),1600) && $eq(pc_gross(11600,'incl',$VAT),11600), 'incl: 11600 -> net 10000 / VAT 1600 / gross 11600');

// receipt reconciliation: 50,000 + 10,500 both "+16%" -> net 60,500 / VAT 9,680 / gross 70,180
$net = pc_exvat(50000,'plus',$VAT)+pc_exvat(10500,'plus',$VAT);
$v   = pc_vat_amount(50000,'plus',$VAT)+pc_vat_amount(10500,'plus',$VAT);
$g   = pc_gross(50000,'plus',$VAT)+pc_gross(10500,'plus',$VAT);
check($eq($net,60500) && $eq($v,9680) && $eq($g,70180), 'VYEE receipt reconciles: net 60,500 / VAT 9,680 / total 70,180');

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
