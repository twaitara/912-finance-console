<?php
/* quote_pricing.php — shared quote line-item pricing + output helpers.
   Extracted from api/quotes.php so the cost-capture endpoint (api/quote_costs.php)
   reuses the exact same maths. Include with require_once.

   A line item may carry an optional cost_rows[] breakdown:
     cost_rows: [{category, description, qty, unit_cost, amount}]
   When present, the line's cost is the SUM of those rows (qty*unit_cost) and it
   overrides the per-line cost/actual_cost. Lines without cost_rows behave exactly
   as before. */

if (!function_exists('quote_clean_cost_rows')) {
    /* Normalise a line's cost_rows: keep valid rows, recompute each amount server-side,
       return [rows, total]. Empty/blank rows are dropped. */
    function quote_clean_cost_rows($raw){
        $rows = []; $total = 0.0;
        $cats = ['parts','labour','consumables','subcontract','other'];
        foreach ((array)$raw as $r){
            if (!is_array($r)) continue;
            $desc = trim((string)($r['description'] ?? ''));
            $qty  = round((float)($r['qty'] ?? 0), 2);
            $unit = round((float)($r['unit_cost'] ?? 0), 2);
            // a row with no description and no cost is empty — skip it
            if ($desc === '' && $unit <= 0) continue;
            if ($qty <= 0) $qty = 1;
            $cat = strtolower(trim((string)($r['category'] ?? 'other')));
            if (!in_array($cat, $cats, true)) $cat = 'other';
            $amount = round($qty * $unit, 2);
            $total += $amount;
            $rows[] = [
                'category'    => $cat,
                'description' => substr($desc, 0, 190),
                'qty'         => $qty,
                'unit_cost'   => max(0, $unit),
                'amount'      => $amount,
            ];
        }
        return [$rows, round($total, 2)];
    }
}

if (!function_exists('quote_price')) {
    /* normalise + price line items (per-line tax + unit cost, with an entity discount before tax).
       A line's cost = SUM(cost_rows) when present, else actual_cost, else cost.
       Returns [cleanItems, sub, discAmt, tax, total, costTotal, profit]. */
    function quote_price($rawItems, $vatRate, $discVal, $discType){
        $items = []; $sub = 0.0; $taxedBase = 0.0; $costTotal = 0.0;
        foreach ((array)$rawItems as $it){
            $name = trim((string)($it['name'] ?? ''));
            if ($name === '') continue;
            $qty   = round((float)($it['qty'] ?? 0), 2);
            $rate  = round((float)($it['rate'] ?? 0), 2);
            $cost  = max(0, round((float)($it['cost'] ?? 0), 2));          // estimated unit cost
            $acost = max(0, round((float)($it['actual_cost'] ?? 0), 2));   // actual cost (overrides)
            if ($qty <= 0) $qty = 1;
            $amount   = round($qty * $rate, 2);

            // cost breakdown rows override the single cost/actual_cost values
            [$costRows, $rowsTotal] = quote_clean_cost_rows($it['cost_rows'] ?? []);
            if ($costRows) {
                $lineCost = $rowsTotal;
            } else {
                $effCost  = $acost > 0 ? $acost : $cost;                    // actual wins, else unit cost
                $lineCost = round($qty * $effCost, 2);
            }

            $tax = (strtolower((string)($it['tax'] ?? 'vat')) === 'none') ? 'none' : 'vat';
            $sub += $amount;
            $costTotal += $lineCost;
            if ($tax === 'vat') $taxedBase += $amount;
            $item = [
                'name'        => substr($name, 0, 190),
                'description' => substr(trim((string)($it['description'] ?? '')), 0, 500),
                'qty'         => $qty,
                'rate'        => $rate,
                'cost'        => $cost,
                'actual_cost' => $acost,
                'amount'      => $amount,
                'profit'      => round($amount - $lineCost, 2),   // line profit (ex VAT, using effective cost)
                'tax'         => $tax,
            ];
            if ($costRows) $item['cost_rows'] = $costRows;         // only persist when present
            $items[] = $item;
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
        $profit = round(($sub - $discAmt) - $costTotal, 2);       // quote profit (ex VAT, after discount)
        return [$items, $sub, $discAmt, $tax, $total, $costTotal, $profit];
    }
}

if (!function_exists('quote_strip_prices')) {
    /* Remove every selling-price / profit figure from a quote_out()-shaped array so a
       non-admin cost capturer never sees what the client was charged. Keeps line
       name/description/qty/tax and the cost_rows they enter. Mutates a copy. */
    function quote_strip_prices(array $r){
        foreach (['sub_total','tax_amount','total','total_cost','profit','discount_value','discount_amount'] as $k) {
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
        $r['discount_value'] = (float)($r['discount_value'] ?? 0);
        $r['discount_amount'] = (float)($r['discount_amount'] ?? 0);
        return $r;
    }
}
