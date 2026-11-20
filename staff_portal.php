<?php
require 'app.php';
require_role(['Admin', 'Staff']);

// ---------------------------------------------------------------
// Three simple actions: Received (delivery), Waste, Set count.
// Sales are NOT entered here - the cashier screen deducts them.
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();
    $id     = (int)($_POST['item_id'] ?? 0);
    $qty    = (float)($_POST['qty'] ?? 0);
    $action = $_POST['action'];
    $notes  = trim($_POST['notes'] ?? '');

    if (!in_array($action, ['in', 'waste', 'count'], true)) { flash('error', 'Unknown action.'); go('staff_portal.php'); }
    if ($action !== 'count' && $qty <= 0) { flash('error', 'Enter a quantity greater than 0.'); go('staff_portal.php'); }
    if ($qty < 0) { flash('error', 'Quantity cannot be negative.'); go('staff_portal.php'); }

    $result = ['error', 'Something went wrong.'];
    $conn->begin_transaction();
    try {
        $st = $conn->prepare("SELECT item_name, current_stock, unit FROM inventory WHERE id = ? FOR UPDATE");
        $st->bind_param("i", $id);
        $st->execute();
        $item = $st->get_result()->fetch_assoc();
        if (!$item) { throw new Exception('Ingredient not found.'); }

        $before = (float)$item['current_stock'];
        $unit   = $item['unit'];
        $name   = $item['item_name'];
        $noop   = false;

        if ($action === 'in') {
            $after = round($before + $qty, 2); $type = 'STOCK IN'; $log_qty = $qty;
            $msg = 'Received ' . fmt_qty($qty) . " $unit of $name.";
        } elseif ($action === 'waste') {
            if (round($qty, 2) > round($before, 2)) {
                throw new Exception("Only " . fmt_qty($before) . " $unit of $name on record, so you can't remove " . fmt_qty($qty) . '.');
            }
            $after = round($before - $qty, 2); $type = 'WASTE/SPOILAGE'; $log_qty = $qty;
            $msg = 'Logged ' . fmt_qty($qty) . " $unit of $name as waste.";
        } else {
            $after = round($qty, 2);
            $diff  = $after - $before;
            if (abs($diff) < 0.005) {
                $noop = true;
                $result = ['info', "$name already shows " . fmt_qty($before) . " $unit. No change."];
            } else {
                $type = $diff > 0 ? 'STOCK IN' : 'STOCK OUT';
                $log_qty = abs($diff);
                $auto = 'Count correction: ' . fmt_qty($before) . ' -> ' . fmt_qty($after);
                $notes = $notes !== '' ? "$auto ($notes)" : $auto;
                $msg = "$name set to " . fmt_qty($after) . " $unit.";
            }
        }

        if (!$noop) {
            $upd = $conn->prepare("UPDATE inventory SET current_stock = ? WHERE id = ?");
            $upd->bind_param("di", $after, $id);
            $upd->execute();
            log_move($conn, $id, $name, $type, $log_qty, $unit, $before, $after, $_SESSION['user'], $notes);
            $result = ['ok', $msg];
        }
        $conn->commit();
    } catch (mysqli_sql_exception $ex) {
        $conn->rollback();
        error_log('Don Felix kitchen: ' . $ex->getMessage());
        $result = ['error', "Couldn't save that. Nothing was changed. Please try again."];
    } catch (Exception $ex) {
        $conn->rollback();
        $result = ['error', $ex->getMessage()];
    }
    flash($result[0], $result[1]);
    go('staff_portal.php');
}

// ---------------------------------------------------------------
// Data for the screen (low items first)
// ---------------------------------------------------------------
$items = $conn->query("SELECT * FROM inventory ORDER BY (current_stock <= min_alert) DESC, category, item_name");
$low = [];
$cats = [];
$rows = [];
foreach ($items as $r) {
    $rows[] = $r;
    $cats[$r['category']] = short_category($r['category']);
    if ((float)$r['current_stock'] <= (float)$r['min_alert']) { $low[] = $r['item_name']; }
}
$activity = $conn->query("SELECT * FROM logs ORDER BY timestamp DESC, id DESC LIMIT 10");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen - Don Felix Sizzling House</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php app_header('staff'); ?>
<div class="wrap">
    <?php echo flash_html(); ?>

    <?php if (count($low) === 0): ?>
        <div class="alert good">All stock levels are OK.</div>
    <?php else: ?>
        <div class="alert">Running low (<?php echo count($low); ?>): <?php echo e(implode(', ', $low)); ?></div>
    <?php endif; ?>

    <div class="filters">
        <button type="button" class="tab on" data-cat="">All</button>
        <?php foreach ($cats as $full => $short): ?>
            <button type="button" class="tab" data-cat="<?php echo e($full); ?>"><?php echo e($short); ?></button>
        <?php endforeach; ?>
        <input class="search" id="q" type="search" placeholder="Search ingredient">
    </div>
    <p class="hint">Delivery arrived: <b>+ Received</b> &middot; Spoiled or dropped: <b>Waste</b> &middot; Counted the shelf: type the real amount, press <b>Set count</b>. Sales are deducted automatically.</p>

    <div class="cards">
    <?php foreach ($rows as $r):
        [$lvl, $pct] = stock_level($r['current_stock'], $r['min_alert']);
    ?>
        <div class="card <?php echo $lvl === 'low' ? 'lowcard' : ''; ?>" data-cat="<?php echo e($r['category']); ?>" data-name="<?php echo e(strtolower($r['item_name'])); ?>">
            <div class="top"><b><?php echo e($r['item_name']); ?></b><span class="pill <?php echo $lvl; ?>"><?php echo level_label($lvl); ?></span></div>
            <div class="muted small"><?php echo fmt_qty($r['current_stock']) . ' ' . e($r['unit']); ?> &middot; low at <?php echo fmt_qty($r['min_alert']); ?></div>
            <div class="meter"><i class="<?php echo $lvl; ?>" style="width:<?php echo $pct; ?>%"></i></div>
            <form action="staff_portal.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="item_id" value="<?php echo (int)$r['id']; ?>">
                <div class="in2">
                    <input type="number" step="0.01" min="0" name="qty" placeholder="Qty" required>
                    <input type="text" name="notes" placeholder="Note (optional)" maxlength="150">
                </div>
                <div class="bs3">
                    <button type="submit" name="action" value="in" class="btn ok">+ Received</button>
                    <button type="submit" name="action" value="waste" class="btn warn">Waste</button>
                    <button type="submit" name="action" value="count" class="btn ghost"
                            onclick="return confirm('Set <?php echo e(addslashes($r['item_name'])); ?> to the quantity you typed?');">Set count</button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="panel">
        <h2>Recent activity</h2>
        <table>
            <thead><tr><th>When</th><th>Ingredient</th><th>What happened</th><th>Amount</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($activity as $l): [$label, $cls] = action_label($l['action_type']); ?>
                <tr>
                    <td class="muted small"><?php echo e(when_str($l['timestamp'])); ?></td>
                    <td><?php echo e($l['item_name']); ?></td>
                    <td class="<?php echo $cls; ?>"><?php echo e($label); ?></td>
                    <td><?php echo fmt_qty($l['quantity']) . ' ' . e($l['unit']); ?></td>
                    <td><?php echo e($l['logged_by']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
var activeCat = '';
function applyFilter() {
    var term = document.getElementById('q').value.trim().toLowerCase();
    document.querySelectorAll('.cards .card').forEach(function (c) {
        var okCat = activeCat === '' || c.dataset.cat === activeCat;
        var okName = c.dataset.name.indexOf(term) !== -1;
        c.style.display = okCat && okName ? '' : 'none';
    });
}
document.getElementById('q').addEventListener('input', applyFilter);
document.querySelectorAll('.filters .tab').forEach(function (t) {
    t.addEventListener('click', function () {
        document.querySelectorAll('.filters .tab').forEach(function (x) { x.classList.remove('on'); });
        t.classList.add('on');
        activeCat = t.dataset.cat;
        applyFilter();
    });
});
</script>
</body>
</html>
