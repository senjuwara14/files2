<?php
require 'app.php';
require_role(['Admin']);

// ---------------------------------------------------------------
// Void an order: the exact ingredients it used go back into stock
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_order'])) {
    csrf_check();
    $oid = (int)$_POST['void_order'];
    $result = ['error', 'Something went wrong.'];
    $conn->begin_transaction();
    try {
        $st = $conn->prepare("SELECT status FROM orders WHERE id = ? FOR UPDATE");
        $st->bind_param("i", $oid);
        $st->execute();
        $order = $st->get_result()->fetch_assoc();
        if (!$order) { throw new Exception('Order not found.'); }
        if ($order['status'] === 'Voided') { throw new Exception("Order #$oid was already voided.");
        }

        $ded = $conn->prepare("SELECT ingredient_id, item_name, quantity, unit FROM order_deductions WHERE order_id = ?");
        $ded->bind_param("i", $oid);
        $ded->execute();
        $deductions = $ded->get_result()->fetch_all(MYSQLI_ASSOC);

        $get = $conn->prepare("SELECT current_stock FROM inventory WHERE id = ? FOR UPDATE");
        $upd = $conn->prepare("UPDATE inventory SET current_stock = ? WHERE id = ?");
        $returned = 0;
        foreach ($deductions as $d) {
            $iid = (int)$d['ingredient_id'];
            $get->bind_param("i", $iid);
            $get->execute();
            $row = $get->get_result()->fetch_assoc();
            if (!$row) { continue; } // ingredient was deleted since; nothing to return
            $before = (float)$row['current_stock'];
            $qty    = (float)$d['quantity'];
            $after  = round($before + $qty, 2);
            $upd->bind_param("di", $after, $iid);
            $upd->execute();
            log_move($conn, $iid, $d['item_name'], 'VOID RETURN', $qty, $d['unit'], $before, $after, $_SESSION['user'], "Order #$oid voided");
            $returned++;
        }

        $mark = $conn->prepare("UPDATE orders SET status = 'Voided', voided_by = ?, voided_at = NOW() WHERE id = ?");
        $mark->bind_param("si", $_SESSION['user'], $oid);
        $mark->execute();
        $conn->commit();
        $result = ['ok', "Order #$oid voided. $returned ingredient(s) returned to stock."];
    } catch (mysqli_sql_exception $ex) {
        $conn->rollback();
        error_log('Don Felix void: ' . $ex->getMessage());
        $result = ['error', "Couldn't void that order. Nothing was changed."];
    } catch (Exception $ex) {
        $conn->rollback();
        $result = ['error', $ex->getMessage()];
    }
    flash($result[0], $result[1]);
    go('orders.php');
}

// ---------------------------------------------------------------
$orders = $conn->query("SELECT o.id, o.created_at, o.cashier, o.total, o.status, o.voided_by,
        GROUP_CONCAT(CONCAT(oi.qty, '× ', oi.dish_name, IF(oi.omitted IS NULL OR oi.omitted = '', '', CONCAT(' (no ', oi.omitted, ')')))
                     ORDER BY oi.id SEPARATOR ', ') AS items
    FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id
    GROUP BY o.id ORDER BY o.id DESC LIMIT 40");
$activity = $conn->query("SELECT * FROM logs ORDER BY timestamp DESC, id DESC LIMIT 50");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - Don Felix Sizzling House</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php app_header('orders'); ?>
<div class="wrap">
    <?php echo flash_html(); ?>

    <div class="panel" id="ordersPanel">
        <div class="panel-head">
            <h2>Orders</h2>
            <button type="button" class="btn ghost noprint" onclick="printOnly('ordersPanel')">Print</button>
        </div>
        <p class="hint noprint">Made a mistake? Press Void and the ingredients go back into stock. The order stays listed so there is a record.</p>
        <table>
            <thead><tr><th>Order</th><th>Time</th><th>Items</th><th>Total</th><th>Cashier</th><th class="noprint"></th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): $void = $o['status'] === 'Voided'; ?>
                <tr class="<?php echo $void ? 'voided' : ''; ?>">
                    <td><b>#<?php echo (int)$o['id']; ?></b></td>
                    <td class="muted small"><?php echo e(when_str($o['created_at'])); ?></td>
                    <td><?php echo e($o['items']); ?></td>
                    <td><?php echo peso($o['total']); ?></td>
                    <td><?php echo e($o['cashier']); ?></td>
                    <td class="noprint">
                        <?php if ($void): ?>
                            <span class="pill gray">Voided by <?php echo e($o['voided_by']); ?></span>
                        <?php else: ?>
                            <form method="POST" action="orders.php" class="inline" onsubmit="return confirm('Void order #<?php echo (int)$o['id']; ?>? The ingredients go back into stock.');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="void_order" value="<?php echo (int)$o['id']; ?>">
                                <button type="submit" class="btn outline">Void</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="panel" id="activityPanel">
        <div class="panel-head">
            <h2>Activity log: who changed what</h2>
            <button type="button" class="btn ghost noprint" onclick="printOnly('activityPanel')">Print</button>
        </div>
        <table>
            <thead><tr><th>When</th><th>Who</th><th>Ingredient</th><th>Change</th><th>Why</th></tr></thead>
            <tbody>
            <?php foreach ($activity as $l):
                [$label, $cls] = action_label($l['action_type']);
                $hasBA = $l['stock_before'] !== null && $l['stock_after'] !== null;
                $up = $hasBA ? ((float)$l['stock_after'] >= (float)$l['stock_before']) : in_array($l['action_type'], ['STOCK IN', 'VOID RETURN'], true);
            ?>
                <tr>
                    <td class="muted small"><?php echo e(when_str($l['timestamp'])); ?></td>
                    <td><?php echo e($l['logged_by']); ?></td>
                    <td><?php echo e($l['item_name']); ?></td>
                    <td class="<?php echo $up ? 'act-in' : 'act-out'; ?>">
                        <?php if ($hasBA): ?>
                            <?php echo fmt_qty($l['stock_before']) . ' → ' . fmt_qty($l['stock_after']) . ' ' . e($l['unit']); ?>
                        <?php else: ?>
                            <?php echo ($up ? '+' : '-') . fmt_qty($l['quantity']) . ' ' . e($l['unit']); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e($label); ?><?php echo !empty($l['notes']) ? ' <span class="muted small">· ' . e($l['notes']) . '</span>' : ''; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="app.js"></script>
</body>
</html>
