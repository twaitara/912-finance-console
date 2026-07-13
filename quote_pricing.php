<?php
/* quote_pricing.php — shared quote line-item pricing + output helpers.
   Extracted from api/quotes.php so other endpoints reuse the same maths.
   Include with require_once.

   Line-item cost here is the BUDGETED unit cost (`cost`) typed while quoting; it
   drives the quote's estimated profit. ACTUAL costs are captured against a project
   in the relational `project_costs` table (see project_costs.php), not in this JSON. */

if (!function_exists('quote_price')) {
    /* normalise + price line items (per-line tax + budgeted unit cost, entity discount before tax).
       Returns [cleanItems, sub, discAmt, tax, total, costTotal, profit]. */
    function quote_price($rawItems, $vatRate, $discVal, $discType){
        $items = []; $sub = 0.0; $taxedBase = 0.0; $costTotal = 0.0;
        foreach ((array)$rawItems as $it){
            $name = trim((string)($it['name'] ?? ''));
            if ($name === '') continue;
            $qty   = round((float)($it['qty'] ?? 0), 2);
            $rate  = round((float)($it['rate'] ?? 0), 2);
            $cost  = max(0, round((float)($it['cost'] ?? 0), 2));          // budgeted unit cost
            $acost = max(0, round((float)($it['actual_cost'] ?? 0), 2));   // legacy single actual (still honoured if present)
            if ($qty <= 0) $qty = 1;
            $amount   = round($qty * $rate, 2);
            $effCost  = $acost > 0 ? $acost : $cost;
            $lineCost = round($qty * $effCost, 2);

            $tax = (strtolower((string)($it['tax'] ?? 'vat')) === 'none') ? 'none' : 'vat';
            $sub += $amount;
            $costTotal += $lineCost;
            if ($tax === 'vat') $taxedBase += $amount;
            $items[] = [
                'name'        => substr($name, 0, 190),
                'description' => substr(trim((string)($it['description'] ?? '')), 0, 500),
                'qty'         => $qty,
                'rate'        => $rate,
                'cost'        => $cost,
                'actual_cost' => $acost,
                'amount'      => $amount,
                'profit'      => round($amount - $lineCost, 2),   // budgeted line profit (ex VAT)
                'tax'         => $tax,
            ];
        }
        $sub = round($sub, 2);
        $costTotal = round($costTotal, 2);
        $discVal = max(0, (float)$discVal);
        $discType = ($discType === 'amount') ? 'amount' : 'percent';
        $discAmt = $discType === 'percent' ? round($sub * $discVal / 100, 2) : min(round($discVal, 2), $sub);
        // discount is before tax — spread it across taxed lines proportionally
        $taxedAfter = $taxedBase - ($sub > 0 ? $discAmt * ($taxedBase / $sub) : 0);
        $tax = round(max(0, $taxedAfter) * (float)$vatRate, 2);
        $total = round($sub - $discAmt + $tax, 2);
        $profit = round(($sub - $discAmt) - $costTotal, 2);       // budgeted quote profit (ex VAT, after discount)
        return [$items, $sub, $discAmt, $tax, $total, $costTotal, $profit];
    }
}

if (!function_exists('quote_strip_prices')) {
    /* Remove every selling-price / profit figure from a quote_out()-shaped array so a
       non-admin cost capturer never sees what the client was charged. Keeps line
       name/description/qty/tax. Mutates a copy. */
    function quote_strip_prices(array $r){
        foreach (['sub_total','tax_amount','total','total_cost','profit','actual_cost','actual_profit','discount_value','discount_amount'] as $k) {
            if (array_key_exists($k, $r)) $r[$k] = 0;
        }
        $r['prices_hidden'] = true;
        $items = is_array($r['line_items'] ?? null) ? $r['line_items'] : [];
        foreach ($items as &$it) {
            unset($it['rate'], $it['amount'], $it['profit'], $it['cost'], $it['actual_cost']);
        }
        unset($it);
        $r['line_items'] = $items;
        return $r;
    }
}

if (!function_exists('quote_out')) {
    function quote_out(array $r){
        $r['line_items'] = json_decode($r['line_items'] ?: '[]', true) ?: [];
        $r['id'] = (int)$r['id'];
        $r['sub_total'] = (float)$r['sub_total'];
        $r['tax_amount'] = (float)$r['tax_amount'];
        $r['total'] = (float)$r['total'];
        $r['total_cost'] = (float)($r['total_cost'] ?? 0);
        $r['profit'] = (float)($r['profit'] ?? 0);
        $r['actual_cost'] = (float)($r['actual_cost'] ?? 0);
        $r['actual_profit'] = (float)($r['actual_profit'] ?? 0);
        $r['discount_value'] = (float)($r['discount_value'] ?? 0);
        $r['discount_amount'] = (float)($r['discount_amount'] ?? 0);
        return $r;
    }
}
