<?php
require 'app.php';
require_role(['Admin']);

// Sales today (voided orders are not counted)
$k = $conn->query("SELECT COALESCE(SUM(total), 0) AS s, COUNT(*) AS c FROM orders
                   WHERE status = 'Completed' AND DATE(created_at) = CURDATE()")->fetch_assoc();

// Stock overview
$inventory = [];
$valuation = 0.0;
$low_count = 0;
$buy = [];
foreach ($conn->query("SELECT * FROM inventory ORDER BY item_name") as $r) {
    [$lvl, $pct] = stock_level($r['current_stock'], $r['min_alert']);
    $r['lvl'] = $lvl;
    $r['pct'] = $pct;
    $inventory[] = $r;
    $valuation += (float)$r['current_stock'] * (float)$r['cost_per_unit'];
    if ($lvl === 'low') {
        $low_count++;
        // Simple rule: refill up to 3x the "low at" level, rounded up
        $target = max((float)$r['min_alert'] * 3, 1);
        $buy[] = ['name' => $r['item_name'], 'unit' => $r['unit'], 'have' => $r['current_stock'], 'min' => $r['min_alert'],
                  'amount' => (int)ceil($target - (float)$r['current_stock'])];
    }
}
usort($inventory, function ($a, $b) { return $a['pct'] <=> $b['pct']; });

// Plates you can still make (limited by the ingredient that runs out first)
$plates = [];
$pq = $conn->query("SELECT m.id, m.dish_name, r.quantity_required, i.item_name, i.current_stock
                    FROM menu_items m
                    LEFT JOIN recipes r ON r.menu_item_id = m.id
                    LEFT JOIN inventory i ON i.id = r.ingredient_id
                    WHERE m.is_available = 1 ORDER BY m.dish_name");
foreach ($pq as $r) {
    $id = (int)$r['id'];
    if (!isset($plates[$id])) { $plates[$id] = ['name' => $r['dish_name'], 'left' => null, 'limit' => '']; }
    if ($r['quantity_required'] !== null && (float)$r['quantity_required'] > 0) {
        $can = (int)floor(round((float)$r['current_stock'], 2) / (float)$r['quantity_required'] + 0.000001);
        if ($plates[$id]['left'] === null || $can < $plates[$id]['left']) {
            $plates[$id]['left'] = $can;
            $plates[$id]['limit'] = $r['item_name'];
        }
    }
}

// Used today (sales minus voids)
$used = [];
$maxUsed = 0;
$uq = $conn->query("SELECT item_name, unit,
        SUM(CASE action_type WHEN 'POS SALE' THEN quantity WHEN 'VOID RETURN' THEN -quantity ELSE 0 END) AS used
    FROM logs WHERE DATE(timestamp) = CURDATE() AND action_type IN ('POS SALE', 'VOID RETURN')
    GROUP BY item_name, unit HAVING used > 0 ORDER BY used DESC LIMIT 5");
foreach ($uq as $r) { $used[] = $r; $maxUsed = max($maxUsed, (float)$r['used']); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Don Felix Sizzling House</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php app_header('admin'); ?>
<div class="wrap">
    <?php echo flash_html(); ?>

    <div class="kpis">
        <div class="kpi money"><small>Sales today</small><b><?php echo peso($k['s']); ?></b></div>
        <div class="kpi"><small>Orders today</small><b><?php echo (int)$k['c']; ?></b></div>
        <div class="kpi low"><small>Running low</small><b><?php echo $low_count; ?></b></div>
        <div class="kpi"><small>Stock value</small><b><?php echo peso($valuation); ?></b></div>
    </div>

    <div class="dgrid">
        <div class="panel">
            <h2>Stock levels</h2>
            <?php foreach ($inventory as $r): ?>
                <div class="brow">
                    <span class="bname"><?php echo e($r['item_name']); ?></span>
                    <div class="meter"><i class="<?php echo $r['lvl']; ?>" style="width:<?php echo $r['pct']; ?>%"></i></div>
                    <em><?php echo fmt_qty($r['current_stock']) . ' ' . e($r['unit']); ?></em>
                </div>
            <?php endforeach; ?>
            <?php if (empty($inventory)): ?><p class="muted">No ingredients yet. Add some under Manage.</p><?php endif; ?>
        </div>

        <div class="panel" id="buylist">
            <div class="panel-head">
                <h2>Buy list</h2>
                <?php if (!empty($buy)): ?><button type="button" class="btn ghost noprint" onclick="printOnly('buylist')">Print</button><?php endif; ?>
            </div>
            <?php if (empty($buy)): ?>
                <p class="muted">Nothing to buy right now. All stock is above its low level.</p>
            <?php else: ?>
                <?php foreach ($buy as $b): ?>
                    <div class="ro">
                        <span><b><?php echo e($b['name']); ?></b><br><span class="muted small"><?php echo fmt_qty($b['have']) . ' ' . e($b['unit']); ?> left &middot; low at <?php echo fmt_qty($b['min']); ?></span></span>
                        <b class="act-out">Buy about <?php echo $b['amount'] . ' ' . e($b['unit']); ?></b>
                    </div>
                <?php endforeach; ?>
                <p class="hint" style="margin:8px 0 0;">Amounts refill each item to 3 times its low level.</p>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h2>Plates you can still make</h2>
            <?php foreach ($plates as $p): ?>
                <div class="pl-row">
                    <span><?php echo e($p['name']); ?></span>
                    <?php if ($p['left'] === null): ?>
                        <span class="muted small">No recipe yet</span>
                    <?php elseif ($p['left'] <= 0): ?>
                        <b class="act-out">0 (out of <?php echo e($p['limit']); ?>)</b>
                    <?php else: ?>
                        <b class="act-in"><?php echo (int)$p['left']; ?></b>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (empty($plates)): ?><p class="muted">No dishes yet.</p><?php endif; ?>
        </div>

        <div class="panel">
            <h2>Used today</h2>
            <?php foreach ($used as $u): ?>
                <div class="brow">
                    <span class="bname"><?php echo e($u['item_name']); ?></span>
                    <div class="meter"><i class="use" style="width:<?php echo (int)max(6, round((float)$u['used'] / $maxUsed * 100)); ?>%"></i></div>
                    <em><?php echo fmt_qty($u['used']) . ' ' . e($u['unit']); ?></em>
                </div>
            <?php endforeach; ?>
            <?php if (empty($used)): ?><p class="muted">No sales yet today.</p><?php endif; ?>
        </div>
    </div>

    <div class="panel noprint">
        <div class="panel-head">
            <div>
                <h2>Backup</h2>
                <p class="hint" style="margin:4px 0 0;">Download a copy of everything (stock, orders, accounts). Do this every week and keep the file somewhere safe, like a flash drive.</p>
            </div>
            <form method="POST" action="admin_backup.php">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn">Download backup</button>
            </form>
        </div>
    </div>
</div>
<script src="app.js"></script>
</body>
</html>
