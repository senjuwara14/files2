<?php
require 'app.php';
require_role(['Admin']);

$CATS  = ['Meat & Poultry', 'Produce', 'Dry Goods & Seasonings', 'Packaging & Disposables'];
$UNITS = ['kg', 'grams', 'pieces', 'liters', 'packs', 'sacks'];

// ---------------------------------------------------------------
// Every change on this page is a POST with a CSRF token
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do'])) {
    csrf_check();
    $do = $_POST['do'];
    $anchor = 'ingredients';
    $conn->begin_transaction();
    try {
        switch ($do) {
            case 'add_item':
                $name = trim($_POST['item_name'] ?? '');
                $cat = $_POST['category'] ?? '';
                $unit = $_POST['unit'] ?? '';
                $stock = (float)($_POST['current_stock'] ?? 0);
                $min = (float)($_POST['min_alert'] ?? 0);
                $cost = (float)($_POST['cost_per_unit'] ?? 0);
                if ($name === '' || !in_array($cat, $CATS, true) || !in_array($unit, $UNITS, true) || $stock < 0 || $min < 0 || $cost < 0) {
                    throw new Exception('Please fill in the ingredient details correctly.');
                }
                $st = $conn->prepare("INSERT INTO inventory (item_name, category, current_stock, unit, min_alert, cost_per_unit) VALUES (?, ?, ?, ?, ?, ?)");
                $st->bind_param("ssdsdd", $name, $cat, $stock, $unit, $min, $cost);
                $st->execute();
                $new_id = (int)$conn->insert_id;
                if ($stock > 0) { log_move($conn, $new_id, $name, 'STOCK IN', $stock, $unit, 0.0, $stock, $_SESSION['user'], 'New ingredient'); }
                flash('ok', "Added $name.");
                break;

            case 'update_item':
                $id = (int)($_POST['edit_id'] ?? 0);
                $name = trim($_POST['item_name'] ?? '');
                $cat = $_POST['category'] ?? '';
                $unit = $_POST['unit'] ?? '';
                $stock = round((float)($_POST['current_stock'] ?? 0), 2);
                $min = (float)($_POST['min_alert'] ?? 0);
                $cost = (float)($_POST['cost_per_unit'] ?? 0);
                if ($id <= 0 || $name === '' || !in_array($cat, $CATS, true) || !in_array($unit, $UNITS, true) || $stock < 0 || $min < 0 || $cost < 0) {
                    throw new Exception('Please fill in the ingredient details correctly.');
                }
                $g = $conn->prepare("SELECT current_stock FROM inventory WHERE id = ? FOR UPDATE");
                $g->bind_param("i", $id);
                $g->execute();
                $row = $g->get_result()->fetch_assoc();
                if (!$row) { throw new Exception('Ingredient not found.'); }
                $before = (float)$row['current_stock'];
                $st = $conn->prepare("UPDATE inventory SET item_name = ?, category = ?, current_stock = ?, unit = ?, min_alert = ?, cost_per_unit = ? WHERE id = ?");
                $st->bind_param("ssdsddi", $name, $cat, $stock, $unit, $min, $cost, $id);
                $st->execute();
                if (abs($stock - $before) >= 0.005) {
                    log_move($conn, $id, $name, 'OWNER EDIT', abs($stock - $before), $unit, $before, $stock, $_SESSION['user'], 'Stock changed by owner');
                }
                flash('ok', 'Changes saved.');
                break;

            case 'delete_item':
                $id = (int)($_POST['id'] ?? 0);
                $c = $conn->prepare("SELECT COUNT(*) AS n FROM recipes WHERE ingredient_id = ?");
                $c->bind_param("i", $id);
                $c->execute();
                $n = (int)$c->get_result()->fetch_assoc()['n'];
                if ($n > 0) { throw new Exception("That ingredient is used in $n recipe(s). Remove it from the recipes first."); }
                $st = $conn->prepare("DELETE FROM inventory WHERE id = ?");
                $st->bind_param("i", $id);
                $st->execute();
                flash('ok', 'Ingredient deleted.');
                break;

            case 'add_dish':
                $anchor = 'dishes';
                $name = trim($_POST['dish_name'] ?? '');
                $cat = trim($_POST['category'] ?? '') ?: 'Sizzling Meals';
                $price = (float)($_POST['price'] ?? 0);
                if ($name === '' || $price <= 0) { throw new Exception('Enter a dish name and a price above 0.'); }
                $st = $conn->prepare("INSERT INTO menu_items (dish_name, category, price, is_available) VALUES (?, ?, ?, 1)");
                $st->bind_param("ssd", $name, $cat, $price);
                $st->execute();
                flash('ok', "Added $name. Now add its recipe below.");
                $anchor = 'recipes';
                break;

            case 'update_dish':
                $anchor = 'dishes';
                $id = (int)($_POST['id'] ?? 0);
                $price = (float)($_POST['price'] ?? 0);
                $avail = isset($_POST['available']) ? 1 : 0;
                if ($id <= 0 || $price <= 0) { throw new Exception('Enter a price above 0.'); }
                $st = $conn->prepare("UPDATE menu_items SET price = ?, is_available = ? WHERE id = ?");
                $st->bind_param("dii", $price, $avail, $id);
                $st->execute();
                flash('ok', 'Dish updated.');
                break;

            case 'delete_dish':
                $anchor = 'dishes';
                $id = (int)($_POST['id'] ?? 0);
                $st = $conn->prepare("DELETE FROM menu_items WHERE id = ?");
                $st->bind_param("i", $id);
                $st->execute();
                flash('ok', 'Dish deleted. Past orders keep their record.');
                break;

            case 'save_recipe':
                $anchor = 'recipes';
                $dish = (int)($_POST['menu_item_id'] ?? 0);
                $ing = (int)($_POST['ingredient_id'] ?? 0);
                $qty = (float)($_POST['quantity_required'] ?? 0);
                if ($dish <= 0 || $ing <= 0 || $qty <= 0) { throw new Exception('Choose a dish, an ingredient and an amount above 0.'); }
                $c = $conn->prepare("SELECT id FROM recipes WHERE menu_item_id = ? AND ingredient_id = ?");
                $c->bind_param("ii", $dish, $ing);
                $c->execute();
                $ex = $c->get_result()->fetch_assoc();
                if ($ex) {
                    $st = $conn->prepare("UPDATE recipes SET quantity_required = ? WHERE id = ?");
                    $st->bind_param("di", $qty, $ex['id']);
                } else {
                    $st = $conn->prepare("INSERT INTO recipes (menu_item_id, ingredient_id, quantity_required) VALUES (?, ?, ?)");
                    $st->bind_param("iid", $dish, $ing, $qty);
                }
                $st->execute();
                flash('ok', 'Recipe saved.');
                break;

            case 'del_recipe':
                $anchor = 'recipes';
                $id = (int)($_POST['id'] ?? 0);
                $st = $conn->prepare("DELETE FROM recipes WHERE id = ?");
                $st->bind_param("i", $id);
                $st->execute();
                flash('ok', 'Removed from the recipe.');
                break;

            case 'delete_user':
                $anchor = 'accounts';
                $id = (int)($_POST['id'] ?? 0);
                if ($id === (int)$_SESSION['user_id']) { throw new Exception("You can't delete your own account."); }
                $st = $conn->prepare("DELETE FROM users WHERE id = ?");
                $st->bind_param("i", $id);
                $st->execute();
                flash('ok', 'Account deleted.');
                break;

            case 'reset_password':
                $anchor = 'accounts';
                $id = (int)($_POST['id'] ?? 0);
                $pw = $_POST['new_password'] ?? '';
                if (strlen($pw) < 6) { throw new Exception('The new password needs at least 6 characters.'); }
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                $st = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $st->bind_param("si", $hash, $id);
                $st->execute();
                flash('ok', 'Password changed.');
                break;

            default:
                throw new Exception('Unknown action.');
        }
        $conn->commit();
    } catch (mysqli_sql_exception $ex) {
        $conn->rollback();
        error_log('Don Felix manage: ' . $ex->getMessage());
        flash('error', "Couldn't save that. Check the details and try again.");
    } catch (Exception $ex) {
        $conn->rollback();
        flash('error', $ex->getMessage());
    }
    go('admin_manage.php#' . $anchor);
}

// ---------------------------------------------------------------
$inventory = $conn->query("SELECT * FROM inventory ORDER BY category, item_name");
$dishes    = $conn->query("SELECT * FROM menu_items ORDER BY dish_name");
$dish_opts = $conn->query("SELECT id, dish_name FROM menu_items ORDER BY dish_name")->fetch_all(MYSQLI_ASSOC);
$ing_opts  = $conn->query("SELECT id, item_name, unit FROM inventory ORDER BY item_name")->fetch_all(MYSQLI_ASSOC);
$recipes   = $conn->query("SELECT r.id, r.quantity_required, m.dish_name, i.item_name, i.unit
                           FROM recipes r JOIN menu_items m ON m.id = r.menu_item_id JOIN inventory i ON i.id = r.ingredient_id
                           ORDER BY m.dish_name, i.item_name");
$users     = $conn->query("SELECT id, fullname, username, role, created_at FROM users ORDER BY role, fullname");
$JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage - Don Felix Sizzling House</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php app_header('manage'); ?>
<div class="wrap">
    <?php echo flash_html(); ?>

    <!-- Ingredients -->
    <div class="panel" id="ingredients">
        <div class="panel-head">
            <h2>Ingredients</h2>
            <button type="button" class="btn ghost noprint" onclick="printOnly('ingredients')">Print</button>
        </div>
        <details class="add noprint">
            <summary>+ Add ingredient</summary>
            <form action="admin_manage.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="add_item">
                <div class="grid">
                    <input type="text" name="item_name" placeholder="Name" required>
                    <select name="category" required>
                        <?php foreach ($CATS as $c): ?><option><?php echo e($c); ?></option><?php endforeach; ?>
                    </select>
                    <input type="number" step="0.01" min="0" name="current_stock" placeholder="Starting stock" required>
                    <select name="unit" required>
                        <?php foreach ($UNITS as $u): ?><option><?php echo e($u); ?></option><?php endforeach; ?>
                    </select>
                    <input type="number" step="0.01" min="0" name="min_alert" placeholder="Low at" required>
                    <input type="number" step="0.01" min="0" name="cost_per_unit" placeholder="Cost per unit" required>
                    <button type="submit" class="btn">Add</button>
                </div>
            </form>
        </details>
        <table>
            <thead><tr><th>Ingredient</th><th>In stock</th><th>Low at</th><th>Cost</th><th>Value</th><th class="noprint"></th></tr></thead>
            <tbody>
            <?php foreach ($inventory as $r):
                [$lvl] = stock_level($r['current_stock'], $r['min_alert']); ?>
                <tr class="<?php echo $lvl === 'low' ? 'low' : ''; ?>">
                    <td><b><?php echo e($r['item_name']); ?></b><br><span class="muted small"><?php echo e($r['category']); ?></span></td>
                    <td class="<?php echo $lvl === 'low' ? 'act-out' : 'act-in'; ?>"><?php echo fmt_qty($r['current_stock']) . ' ' . e($r['unit']); ?></td>
                    <td><?php echo fmt_qty($r['min_alert']); ?></td>
                    <td><?php echo peso($r['cost_per_unit']); ?></td>
                    <td><?php echo peso((float)$r['current_stock'] * (float)$r['cost_per_unit']); ?></td>
                    <td class="noprint nowrap">
                        <button type="button" class="btn ghost" onclick='openEdit(<?php echo json_encode($r, $JSON); ?>)'>Edit</button>
                        <form method="POST" action="admin_manage.php" class="inline" onsubmit="return confirm('Delete this ingredient?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="do" value="delete_item">
                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                            <button type="submit" class="linkbtn">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Dishes -->
    <div class="panel noprint" id="dishes">
        <h2>Menu</h2>
        <details class="add">
            <summary>+ Add dish</summary>
            <form action="admin_manage.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="add_dish">
                <div class="grid">
                    <input type="text" name="dish_name" placeholder="Dish name" required>
                    <input type="text" name="category" placeholder="Category (e.g. Sizzling Meals)">
                    <input type="number" step="0.01" min="0.01" name="price" placeholder="Price" required>
                    <button type="submit" class="btn">Add</button>
                </div>
            </form>
        </details>
        <table>
            <thead><tr><th>Dish</th><th>Price and availability</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($dishes as $d): ?>
                <tr>
                    <td><b><?php echo e($d['dish_name']); ?></b><br><span class="muted small"><?php echo e($d['category']); ?></span></td>
                    <td>
                        <form method="POST" action="admin_manage.php" class="row-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="do" value="update_dish">
                            <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                            <input type="number" step="0.01" min="0.01" name="price" value="<?php echo e($d['price']); ?>" required>
                            <label class="check"><input type="checkbox" name="available" <?php echo $d['is_available'] ? 'checked' : ''; ?>> On menu</label>
                            <button type="submit" class="btn ghost">Save</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST" action="admin_manage.php" class="inline" onsubmit="return confirm('Delete this dish and its recipe? Past orders keep their record.');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="do" value="delete_dish">
                            <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                            <button type="submit" class="linkbtn">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Recipes -->
    <div class="panel noprint" id="recipes">
        <h2>Recipes</h2>
        <p class="hint">What one plate uses. The cashier screen deducts these automatically. Saving an ingredient that is already in the recipe updates its amount.</p>
        <form action="admin_manage.php" method="POST" class="row-form" style="margin-bottom:14px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="save_recipe">
            <select name="menu_item_id" required>
                <option value="">Dish</option>
                <?php foreach ($dish_opts as $d): ?><option value="<?php echo (int)$d['id']; ?>"><?php echo e($d['dish_name']); ?></option><?php endforeach; ?>
            </select>
            <select name="ingredient_id" required>
                <option value="">Ingredient</option>
                <?php foreach ($ing_opts as $i): ?><option value="<?php echo (int)$i['id']; ?>"><?php echo e($i['item_name']); ?> (<?php echo e($i['unit']); ?>)</option><?php endforeach; ?>
            </select>
            <input type="number" step="0.01" min="0.01" name="quantity_required" placeholder="Amount per plate" required>
            <button type="submit" class="btn">Save</button>
        </form>
        <table>
            <thead><tr><th>Dish</th><th>Ingredient</th><th>Per plate</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recipes as $r): ?>
                <tr>
                    <td><b><?php echo e($r['dish_name']); ?></b></td>
                    <td><?php echo e($r['item_name']); ?></td>
                    <td><?php echo fmt_qty($r['quantity_required']) . ' ' . e($r['unit']); ?></td>
                    <td>
                        <form method="POST" action="admin_manage.php" class="inline" onsubmit="return confirm('Remove this ingredient from the dish?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="do" value="del_recipe">
                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                            <button type="submit" class="linkbtn">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Accounts -->
    <div class="panel noprint" id="accounts">
        <h2>Accounts</h2>
        <table>
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>New password</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u):
                $roleLabel = ['Admin' => 'Owner', 'Staff' => 'Kitchen', 'Client' => 'Cashier'][$u['role']] ?? $u['role']; ?>
                <tr>
                    <td><b><?php echo e($u['fullname']); ?></b></td>
                    <td><?php echo e($u['username']); ?></td>
                    <td><span class="pill <?php echo strtolower($u['role']); ?>"><?php echo e($roleLabel); ?></span></td>
                    <td>
                        <form method="POST" action="admin_manage.php" class="row-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="do" value="reset_password">
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <input type="password" name="new_password" placeholder="At least 6 characters" minlength="6" autocomplete="new-password" required>
                            <button type="submit" class="btn ghost">Change</button>
                        </form>
                    </td>
                    <td>
                        <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                            <form method="POST" action="admin_manage.php" class="inline" onsubmit="return confirm('Delete this account?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="do" value="delete_user">
                                <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                <button type="submit" class="linkbtn">Delete</button>
                            </form>
                        <?php else: ?>
                            <span class="muted small">You</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="sheet">
        <h3>Edit ingredient</h3>
        <form action="admin_manage.php" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="update_item">
            <input type="hidden" name="edit_id" id="edit_id">
            <div class="field"><label>Name</label><input type="text" name="item_name" id="edit_name" required></div>
            <div class="field"><label>Category</label>
                <select name="category" id="edit_category" required>
                    <?php foreach ($CATS as $c): ?><option><?php echo e($c); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="two">
                <div class="field"><label>In stock</label><input type="number" step="0.01" min="0" name="current_stock" id="edit_stock" required></div>
                <div class="field"><label>Unit</label>
                    <select name="unit" id="edit_unit" required>
                        <?php foreach ($UNITS as $u): ?><option><?php echo e($u); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>Low at</label><input type="number" step="0.01" min="0" name="min_alert" id="edit_min_alert" required></div>
                <div class="field"><label>Cost per unit (&#8369;)</label><input type="number" step="0.01" min="0" name="cost_per_unit" id="edit_cost" required></div>
            </div>
            <p class="hint" style="margin:0 0 10px;">If you change the stock amount, it is saved in the activity log as an owner edit.</p>
            <div style="display:flex; gap:10px;">
                <button type="submit" class="btn" style="flex:1;">Save</button>
                <button type="button" class="btn ghost" onclick="closeEdit()">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script src="app.js"></script>
<script>
function openEdit(item) {
    document.getElementById('edit_id').value = item.id;
    document.getElementById('edit_name').value = item.item_name;
    document.getElementById('edit_category').value = item.category;
    document.getElementById('edit_stock').value = item.current_stock;
    document.getElementById('edit_unit').value = item.unit;
    document.getElementById('edit_min_alert').value = item.min_alert;
    document.getElementById('edit_cost').value = item.cost_per_unit;
    document.getElementById('editModal').style.display = 'flex';
}
function closeEdit() { document.getElementById('editModal').style.display = 'none'; }
document.getElementById('editModal').addEventListener('click', function (e) { if (e.target === this) { closeEdit(); } });
</script>
</body>
</html>
