<?php
require 'app.php';
require_role(['Admin', 'Client']);

// ---------------------------------------------------------------
// Charge an order (one order can hold several dishes)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['charge'])) {
    csrf_check();
    $cart = json_decode($_POST['cart'] ?? '[]', true);
    if (!is_array($cart) || count($cart) === 0 || count($cart) > 30) {
        flash('error', 'Add at least one dish to the order.');
        go('pos_order.php');
    }

    $conn->begin_transaction();
    try {
        $dish_st = $conn->prepare("SELECT dish_name, price FROM menu_items WHERE id = ? AND is_available = 1");
        $rec_st  = $conn->prepare("SELECT r.ingredient_id, r.quantity_required, i.item_name, i.unit
                                   FROM recipes r JOIN inventory i ON r.ingredient_id = i.id WHERE r.menu_item_id = ?");
        $needs = [];
        $lines = [];
        $total = 0.0;

        foreach ($cart as $line) {
            $did  = (int)($line['id'] ?? 0);
            $qty  = (int)($line['qty'] ?? 0);
            $omit = (isset($line['omit']) && is_array($line['omit'])) ? array_map('intval', $line['omit']) : [];
            if ($did <= 0 || $qty < 1 || $qty > 50) { throw new Exception('Something in the order was invalid. Please try again.'); }

            $dish_st->bind_param("i", $did);
            $dish_st->execute();
            $dish = $dish_st->get_result()->fetch_assoc();
            if (!$dish) { throw new Exception('A dish in the order is no longer on the menu.'); }

            $rec_st->bind_param("i", $did);
            $rec_st->execute();
            $omitted = [];
            foreach ($rec_st->get_result() as $ing) {
                $iid = (int)$ing['ingredient_id'];
                if (in_array($iid, $omit, true)) { $omitted[] = $ing['item_name']; continue; }
                if (!isset($needs[$iid])) { $needs[$iid] = ['name' => $ing['item_name'], 'unit' => $ing['unit'], 'need' => 0.0]; }
                $needs[$iid]['need'] += (float)$ing['quantity_required'] * $qty;
            }
            $total += (float)$dish['price'] * $qty;
            $lines[] = [$did, $dish['dish_name'], (float)$dish['price'], $qty, implode(', ', $omitted)];
        }

        // Lock the stock rows (in a fixed order) so two cashiers can't sell the last portion at once
        ksort($needs);
        $inv_st = $conn->prepare("SELECT current_stock FROM inventory WHERE id = ? FOR UPDATE");
        $short = [];
        foreach ($needs as $iid => $n) {
            $inv_st->bind_param("i", $iid);
            $inv_st->execute();
            $row = $inv_st->get_result()->fetch_assoc();
            if (!$row) { throw new Exception($n['name'] . ' is no longer in the inventory.'); }
            $needs[$iid]['before'] = (float)$row['current_stock'];
            if (round($needs[$iid]['before'], 2) < round($n['need'], 2)) {
                $short[] = $n['name'] . ' (need ' . fmt_qty($n['need']) . ', have ' . fmt_qty($needs[$iid]['before']) . ' ' . $n['unit'] . ')';
            }
        }
        if (!empty($short)) {
            throw new Exception('Not enough stock: ' . implode('; ', $short) . '. Leave it out of the dish or lower the quantity.');
        }

        $ord = $conn->prepare("INSERT INTO orders (cashier, total) VALUES (?, ?)");
        $ord->bind_param("sd", $_SESSION['user'], $total);
        $ord->execute();
        $oid = (int)$conn->insert_id;

        $oi = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, dish_name, price, qty, omitted) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($lines as $l) {
            $oi->bind_param("iisdis", $oid, $l[0], $l[1], $l[2], $l[3], $l[4]);
            $oi->execute();
        }

        $upd = $conn->prepare("UPDATE inventory SET current_stock = ? WHERE id = ?");
        $ded = $conn->prepare("INSERT INTO order_deductions (order_id, ingredient_id, item_name, quantity, unit) VALUES (?, ?, ?, ?, ?)");
        foreach ($needs as $iid => $n) {
            $need  = round($n['need'], 2);
            $after = round($n['before'] - $need, 2);
            $upd->bind_param("di", $after, $iid);
            $upd->execute();
            $ded->bind_param("iisds", $oid, $iid, $n['name'], $need, $n['unit']);
            $ded->execute();
            log_move($conn, $iid, $n['name'], 'POS SALE', $need, $n['unit'], $n['before'], $after, $_SESSION['user'], 'Order #' . $oid);
        }

        $conn->commit();
        $result = ['ok', 'Order #' . $oid . ' charged: ' . peso($total) . '.'];
    } catch (mysqli_sql_exception $ex) {
        $conn->rollback();
        error_log('Don Felix POS: ' . $ex->getMessage());
        $result = ['error', "Couldn't save the order. Nothing was charged. Please try again."];
    } catch (Exception $ex) {
        $conn->rollback();
        $result = ['error', $ex->getMessage()];
    }
    flash($result[0], $result[1]);
    go('pos_order.php');
}

// ---------------------------------------------------------------
// Data for the screen
// ---------------------------------------------------------------
$recipes = [];
$rq = $conn->query("SELECT r.menu_item_id, r.ingredient_id, r.quantity_required, i.item_name, i.unit, i.current_stock
                    FROM recipes r JOIN inventory i ON i.id = r.ingredient_id ORDER BY i.item_name");
foreach ($rq as $r) { $recipes[(int)$r['menu_item_id']][] = $r; }

$menu = [];
$stock = [];
$dq = $conn->query("SELECT id, dish_name, category, price FROM menu_items WHERE is_available = 1 ORDER BY category, dish_name");
foreach ($dq as $d) {
    $id = (int)$d['id'];
    $ings = [];
    $left = null;
    $limit = '';
    foreach ($recipes[$id] ?? [] as $g) {
        $gid = (int)$g['ingredient_id'];
        $ings[] = ['id' => $gid, 'name' => $g['item_name'], 'unit' => $g['unit'], 'qty' => (float)$g['quantity_required']];
        $stock[$gid] = (float)$g['current_stock'];
        if ((float)$g['quantity_required'] > 0) {
            $can = (int)floor(round((float)$g['current_stock'], 2) / (float)$g['quantity_required'] + 0.000001);
            if ($left === null || $can < $left) { $left = $can; $limit = $g['item_name']; }
        }
    }
    $menu[$id] = ['id' => $id, 'name' => $d['dish_name'], 'cat' => $d['category'], 'price' => (float)$d['price'], 'ings' => $ings, 'left' => $left, 'limit' => $limit];
}

$role = $_SESSION['role'];
$user = $_SESSION['user'];
$recent = $conn->prepare("SELECT o.id, o.created_at, o.total, o.status, o.cashier,
        GROUP_CONCAT(CONCAT(oi.qty, '× ', oi.dish_name) ORDER BY oi.id SEPARATOR ', ') AS items
    FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE (? = 'Admin' OR o.cashier = ?)
    GROUP BY o.id ORDER BY o.id DESC LIMIT 8");
$recent->bind_param("ss", $role, $user);
$recent->execute();
$recent_orders = $recent->get_result();
$JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier - Don Felix Sizzling House</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php app_header('pos'); ?>
<div class="wrap">
    <?php echo flash_html(); ?>

    <div class="pos">
        <div>
            <?php if (empty($menu)): ?>
                <div class="panel"><p class="muted">No dishes on the menu yet. The owner can add them under Manage.</p></div>
            <?php endif; ?>
            <div class="tiles">
            <?php foreach ($menu as $d):
                if ($d['left'] === null) { $badge = ['', '']; }
                elseif ($d['left'] <= 0) { $badge = ['Out: ' . $d['limit'], 'r']; }
                elseif ($d['left'] <= 5) { $badge = [$d['left'] . ' left', 'w']; }
                else { $badge = [$d['left'] . ' left', 'g']; }
            ?>
                <button type="button" class="tile" onclick="addDish(<?php echo $d['id']; ?>)">
                    <span class="t-name"><?php echo e($d['name']); ?></span>
                    <span class="muted small"><?php echo e($d['cat']); ?></span>
                    <span class="t-row">
                        <span class="t-price"><?php echo peso($d['price']); ?></span>
                        <?php if ($badge[0] !== ''): ?><span class="bd <?php echo $badge[1]; ?>"><?php echo e($badge[0]); ?></span><?php endif; ?>
                    </span>
                    <span class="t-add">+ Add</span>
                </button>
            <?php endforeach; ?>
            </div>

            <div class="panel" style="margin-top:20px;">
                <h2>Recent orders</h2>
                <table>
                    <thead><tr><th>Order</th><th>Time</th><th>Items</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent_orders as $o): $void = $o['status'] === 'Voided'; ?>
                        <tr class="<?php echo $void ? 'voided' : ''; ?>">
                            <td><b>#<?php echo (int)$o['id']; ?></b></td>
                            <td class="muted small"><?php echo e(when_str($o['created_at'])); ?></td>
                            <td><?php echo e($o['items']); ?><?php echo $void ? ' <span class="pill gray">Voided</span>' : ''; ?></td>
                            <td><?php echo peso($o['total']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <form class="cart" id="cartForm" method="POST" action="pos_order.php" onsubmit="return submitCart()">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="charge" value="1">
            <input type="hidden" name="cart" id="cartField" value="">
            <h2>Current order</h2>
            <div id="lines"></div>
            <p id="empty" class="muted">Tap a dish to start an order.</p>
            <p id="warn" class="warn-text" style="display:none;">Not enough of a red ingredient. Tap it to leave it out, or lower the quantity.</p>
            <div class="tot"><span>Total</span><span id="total">₱0.00</span></div>
            <button type="submit" class="btn big" id="chargeBtn" disabled>Charge</button>
            <button type="button" class="btn ghost big" style="margin-top:8px;" onclick="clearCart()">Clear</button>
        </form>
    </div>
</div>

<script>
var MENU = <?php echo json_encode($menu, $JSON); ?>;
var STOCK = <?php echo json_encode($stock, $JSON); ?>;
var cart = [];

function money(n) {
    var p = n.toFixed(2).split('.');
    return '\u20B1' + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + p[1];
}
function el(tag, cls, text) {
    var x = document.createElement(tag);
    if (cls) { x.className = cls; }
    if (text !== undefined) { x.textContent = text; }
    return x;
}
function needs() {
    var n = {};
    cart.forEach(function (l) {
        MENU[l.id].ings.forEach(function (g) {
            if (l.omit.indexOf(g.id) === -1) { n[g.id] = (n[g.id] || 0) + g.qty * l.qty; }
        });
    });
    return n;
}
function shortSet() {
    var n = needs(), s = {};
    for (var k in n) {
        if (Math.round(n[k] * 100) > Math.round((STOCK[k] || 0) * 100)) { s[k] = true; }
    }
    return s;
}
function addDish(id) {
    for (var i = cart.length - 1; i >= 0; i--) {
        if (cart[i].id === id) {
            if (cart[i].omit.length === 0) { cart[i].qty = Math.min(50, cart[i].qty + 1); render(); return; }
            break;
        }
    }
    cart.push({ id: id, qty: 1, omit: [] });
    render();
}
function changeQty(i, d) { cart[i].qty = Math.max(1, Math.min(50, cart[i].qty + d)); render(); }
function removeLine(i) { cart.splice(i, 1); render(); }
function toggleOmit(i, gid) {
    var o = cart[i].omit, p = o.indexOf(gid);
    if (p === -1) { o.push(gid); } else { o.splice(p, 1); }
    render();
}
function clearCart() { cart = []; render(); }

function render() {
    var box = document.getElementById('lines');
    box.innerHTML = '';
    var sh = shortSet(), total = 0;
    cart.forEach(function (l, i) {
        var d = MENU[l.id];
        total += d.price * l.qty;
        var row = el('div', 'cl');
        var top = el('div', 'cl-top');
        top.appendChild(el('b', '', d.name));
        top.appendChild(el('span', '', money(d.price * l.qty)));
        row.appendChild(top);

        if (d.ings.length) {
            var chips = el('div', 'chips');
            d.ings.forEach(function (g) {
                var off = l.omit.indexOf(g.id) !== -1;
                var bad = !off && sh[g.id];
                var c = el('button', 'chip2' + (off ? ' off' : '') + (bad ? ' out' : ''), g.name + (bad ? ' (not enough)' : ''));
                c.type = 'button';
                c.onclick = function () { toggleOmit(i, g.id); };
                chips.appendChild(c);
            });
            row.appendChild(chips);
        }

        var q = el('div', 'q');
        var minus = el('button', 'qb', '\u2212'); minus.type = 'button'; minus.onclick = function () { changeQty(i, -1); };
        var plus = el('button', 'qb', '+'); plus.type = 'button'; plus.onclick = function () { changeQty(i, 1); };
        var rm = el('button', 'linkbtn', 'Remove'); rm.type = 'button'; rm.onclick = function () { removeLine(i); };
        q.appendChild(minus); q.appendChild(el('b', '', String(l.qty))); q.appendChild(plus);
        q.appendChild(el('span', 'grow')); q.appendChild(rm);
        row.appendChild(q);
        box.appendChild(row);
    });

    var isShort = Object.keys(sh).length > 0;
    document.getElementById('empty').style.display = cart.length ? 'none' : 'block';
    document.getElementById('warn').style.display = isShort ? 'block' : 'none';
    document.getElementById('total').textContent = money(total);
    var btn = document.getElementById('chargeBtn');
    btn.disabled = cart.length === 0 || isShort;
    btn.textContent = cart.length ? 'Charge ' + money(total) : 'Charge';
}
function submitCart() {
    if (!cart.length || Object.keys(shortSet()).length) { return false; }
    document.getElementById('cartField').value = JSON.stringify(cart);
    return true;
}
render();
</script>
</body>
</html>
