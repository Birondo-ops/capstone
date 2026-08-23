<?php
session_start();
require_once "../auth/conn.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

$admin_name = $_SESSION['admin_name'] ?? 'System';
$sid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$items = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settlement'])) {
    try {
        $pdo->beginTransaction();
        $sid = (int)$_POST['session_id'];
        $received_amount = (float)$_POST['received_amount']; 
        
        // Fetch worker name
        $stmtW = $pdo->prepare("SELECT worker_name FROM dispatch_sessions WHERE id = ?");
        $stmtW->execute([$sid]);
        $worker_name = $stmtW->fetchColumn() ?: 'Unknown Worker';

        // Explicitly set sale type to WHOLESALE for dispatcher remittance
        $session_type = 'Wholesale';

        $total_expected_due = 0.00;
        $item_calculations = [];

        // Calculate sales and validate returns
        if (isset($_POST['returns'])) {
            foreach ($_POST['returns'] as $r_item_id => $return_qty) {
                $return_qty = max(0, (int)$return_qty);
                $qty_taken  = (int)($_POST['qtys_taken'][$r_item_id] ?? 0);
                $pid        = (int)($_POST['product_ids'][$r_item_id] ?? 0);
                $p_name     = $_POST['product_names'][$r_item_id] ?? 'Unknown Product';

                if ($return_qty > $qty_taken) {
                    throw new Exception("Return quantity for $p_name cannot exceed quantity taken.");
                }

                $qty_sold = $qty_taken - $return_qty;
                $price_at_time = (float)($_POST['unit_prices'][$r_item_id] ?? 0); 
                $item_total_sale = $qty_sold * $price_at_time;

                $total_expected_due += $item_total_sale;

                $item_calculations[$r_item_id] = [
                    'pid' => $pid,
                    'p_name' => $p_name,
                    'qty_taken' => $qty_taken,
                    'return_qty' => $return_qty,
                    'qty_sold' => $qty_sold,
                    'price_at_time' => $price_at_time,
                    'item_total_sale' => $item_total_sale
                ];
            }
        }

        // Process Stock Updates, Inventory Logs, Sales Insertion, and Audit Trail
        foreach ($item_calculations as $r_item_id => $data) {
            $pid = $data['pid'];
            $p_name = $data['p_name'];
            $qty_taken = $data['qty_taken'];
            $return_qty = $data['return_qty'];
            $qty_sold = $data['qty_sold'];
            $price_at_time = $data['price_at_time'];
            $item_total_sale = $data['item_total_sale'];

            if ($pid <= 0) {
                throw new Exception("Invalid Product ID for product: " . $p_name);
            }

            // Calculate share of received cash for audit log
            $item_received_share = ($total_expected_due > 0) ? ($item_total_sale / $total_expected_due) * $received_amount : 0;

            // Update dispatch item table
            $stmtUpd = $pdo->prepare("UPDATE dispatch_items SET qty_returned = ?, qty_sold = ? WHERE id = ?");
            $stmtUpd->execute([$return_qty, $qty_sold, $r_item_id]);

            // Inventory Log: Return Items (Stock Added Back)
            if ($return_qty > 0) {
                $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")
                    ->execute([$return_qty, $pid]);

                $logReturn = $pdo->prepare("INSERT INTO inventory_logs (product_id, quantity_change, action, notes, admin_name) VALUES (?, ?, 'Added', ?, ?)");
                $logReturn->execute([$pid, $return_qty, "Returned from Session #$sid ({$worker_name})", $admin_name]);
            }

            // Inventory Log & Wholesale Sales Record for Sold Items
            if ($qty_sold > 0) {
                // Stock Removed Log
                $logSold = $pdo->prepare("INSERT INTO inventory_logs (product_id, quantity_change, action, notes, admin_name) VALUES (?, ?, 'Removed', ?, ?)");
                $logSold->execute([$pid, $qty_sold, "Sold from Session #$sid ({$worker_name})", $admin_name]);

                // Insert Into Sales Table strictly as WHOLESALE
                $stmtSales = $pdo->prepare("
                    INSERT INTO sales (type, product, worker, qty, unit_price, subtotal, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmtSales->execute([
                    $session_type,
                    $p_name,
                    $worker_name,
                    $qty_sold,
                    $price_at_time,
                    $item_total_sale
                ]);
            }

            // Audit Trail Entry
            $audit = $pdo->prepare("INSERT INTO audit_trail (session_id, worker_name, product_id, product_name, qty_taken, qty_sold, qty_returned, received_amount, status) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([$sid, $worker_name, $pid, $p_name, $qty_taken, $qty_sold, $return_qty, $item_received_share, 'Completed Remittance']);
        }

        // Shortage Calculation & Recording in worker_balances
        $shortage_amount = $total_expected_due - $received_amount;

        if ($shortage_amount > 0.01) {
            $chk = $pdo->prepare("SELECT id FROM worker_balances WHERE session_id = ?");
            $chk->execute([$sid]);
            
            if ($chk->fetch()) {
                $stmtBal = $pdo->prepare("UPDATE worker_balances SET shortage_amount = ?, worker_name = ?, status = 'Pending' WHERE session_id = ?");
                $stmtBal->execute([$shortage_amount, $worker_name, $sid]);
            } else {
                $stmtBal = $pdo->prepare("INSERT INTO worker_balances (session_id, worker_name, shortage_amount, status, created_at, paid_at) VALUES (?, ?, ?, 'Pending', NOW(), NULL)");
                $stmtBal->execute([$sid, $worker_name, $shortage_amount]);
            }
        }

        // Close Dispatch Session
        $closeSession = $pdo->prepare("UPDATE dispatch_sessions SET status = 'Completed', total_collected = ? WHERE id = ?");
        $closeSession->execute([$received_amount, $sid]);

        $pdo->commit();
        header("Location: ../dispatchers.php?remitted=1"); 
        exit();
    } catch (Exception $e) { 
        if ($pdo->inTransaction()) $pdo->rollBack(); 
        die("Remittance Error: " . $e->getMessage()); 
    }
}

if ($sid > 0) {
    $stmt = $pdo->prepare("SELECT di.id AS item_id, di.product_id AS p_id, 
        COALESCE(di.price_at_time, p.wholesale_price) AS price_at_time, 
        p.product_name, di.qty_taken
        FROM dispatch_items di
        JOIN products p ON di.product_id = p.id
        WHERE di.session_id = ?"); 
    $stmt->execute([$sid]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Afternoon Remittance</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f9f9f9; padding: 20px; color: #334155; }
        .container { background: #ffffff; padding: 30px; border-radius: 16px; max-width: 900px; margin: 20px auto; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .header { color: #f28c28; font-weight: 800; font-size: 24px; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 2px solid #fff7ed; }
        .product-row { display: grid; grid-template-columns: 2fr 1fr 1fr 1.5fr 1.5fr; align-items: center; gap: 15px; padding: 16px; background: #fffcf8; border-radius: 10px; border: 1px solid #ffe4c4; margin-bottom: 12px; }
        .input-style { border: 1px solid #e2e8f0; padding: 10px; border-radius: 8px; width: 100%; box-sizing: border-box; text-align: center; }
        .total-section { margin-top: 30px; padding: 20px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; }
        .btn-submit { margin: 15px 0; background: #f28c28; color: white; border: none; padding: 12px 30px; border-radius: 10px; cursor: pointer; font-size: 16px; font-weight: 600; width: 220px; transition: 0.2s; }
        .btn-submit:hover { background: #ea580c; }
        .cancel { background-color: #64748b; color: white; border: none; padding: 12px 30px; border-radius: 10px; cursor: pointer; font-size: 16px; font-weight: 600; width: 100px; text-align: center; display: inline-block; transition: 0.2s; text-decoration: none; margin-left: 10px; }
        .status-text { font-size: 13px; font-weight: bold; margin-left: 10px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">TOTAL REMITTANCE SUMMARY (Session #<?= $sid ?>)</div>
    <form method="POST">
        <input type="hidden" name="session_id" value="<?= $sid ?>">
        
        <?php foreach ($items as $item): 
            $subtotal = $item['price_at_time'] * $item['qty_taken'];
        ?>
            <div class="product-row" data-price="<?= $item['price_at_time'] ?>" data-taken="<?= $item['qty_taken'] ?>">
                <div style="min-width: 200px; font-weight:600;"><?= htmlspecialchars($item['product_name']) ?></div>
                <div style="width: 80px;">₱<?= number_format($item['price_at_time'], 2) ?></div>
                <div class="input-style" style="width: 70px;"><?= $item['qty_taken'] ?> pcs</div>
                <div style="text-align: center;">
                    <label style="font-size: 10px; display: block; color: #f28c28; font-weight: bold;">RETURN ITEMS</label>
                    <input type="number" name="returns[<?= $item['item_id'] ?>]" required class="input-style return-qty" style="width: 80px;" value="0" min="0" max="<?= $item['qty_taken'] ?>">
                </div>
                <div style="margin-left: auto; font-weight: bold;">Settlement: ₱<span class="row-settlement"><?= number_format($subtotal, 2) ?></span></div>
                
                <input type="hidden" name="product_ids[<?= $item['item_id'] ?>]" value="<?= $item['p_id'] ?>">
                <input type="hidden" name="product_names[<?= $item['item_id'] ?>]" value="<?= htmlspecialchars($item['product_name']) ?>">
                <input type="hidden" name="qtys_taken[<?= $item['item_id'] ?>]" value="<?= $item['qty_taken'] ?>">
                <input type="hidden" name="unit_prices[<?= $item['item_id'] ?>]" value="<?= $item['price_at_time'] ?>">
            </div>
        <?php endforeach; ?>

        <div class="total-section">
            <div style="font-size: 18px; margin-bottom: 12px;">
                <strong>Total Amount Due:</strong> ₱<span id="grand_total">0.00</span>
            </div>
            <div style="margin-bottom: 12px;">
                <strong>Received Cash:</strong> ₱<input type="number" name="received_amount" id="received_amt" class="input-style" step="0.01" style="width: 150px; font-weight: bold;" required>
            </div>
            <div style="margin-bottom: 12px;">
                <strong>Balance / Shortage:</strong> ₱<input type="number" id="balance_input" class="input-style" step="0.01" style="width: 150px; font-weight: bold;" value="0.00">
                <span id="balance_status" class="status-text"></span>
            </div>
        </div>

        <button type="submit" name="save_settlement" class="btn-submit">Complete Remittance</button>
        <a href="javascript:history.back()" class="cancel">Cancel</a>
    </form>
</div>

<script>
    const rows = document.querySelectorAll('.product-row');
    const receivedInput = document.getElementById('received_amt');
    const balanceInput = document.getElementById('balance_input');
    const grandTotalDisplay = document.getElementById('grand_total');
    const balanceStatus = document.getElementById('balance_status');

    let currentTotalDue = 0;

    function calculateTotalDue() {
        let total = 0;
        rows.forEach(row => {
            const price = parseFloat(row.dataset.price) || 0;
            const taken = parseInt(row.dataset.taken) || 0;
            const returns = parseInt(row.querySelector('.return-qty').value) || 0;
            const settlement = price * Math.max(0, taken - returns);

            row.querySelector('.row-settlement').innerText = settlement.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            total += settlement;
        });
        currentTotalDue = total;
        grandTotalDisplay.innerText = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function updateStatusLabel(shortage) {
        if (Math.abs(shortage) < 0.01) {
            balanceStatus.innerText = "(Balanced)";
            balanceStatus.style.color = "green";
        } else if (shortage > 0) {
            balanceStatus.innerText = "(Shortage - Will record in Worker Balances)";
            balanceStatus.style.color = "#d9534f";
        } else {
            balanceStatus.innerText = "(Overage)";
            balanceStatus.style.color = "#f0ad4e";
        }
    }

    // 1. When Balance/Shortage is modified -> Deduct from Received Cash
    balanceInput.addEventListener('input', () => {
        const shortage = parseFloat(balanceInput.value) || 0;
        const newReceived = currentTotalDue - shortage;
        receivedInput.value = newReceived.toFixed(2);
        updateStatusLabel(shortage);
    });

    // 2. When Received Cash is modified -> Update Balance/Shortage
    receivedInput.addEventListener('input', () => {
        const received = parseFloat(receivedInput.value) || 0;
        const shortage = currentTotalDue - received;
        balanceInput.value = shortage.toFixed(2);
        updateStatusLabel(shortage);
    });

    // 3. When Returns change -> Reset Received Cash to full Total Due
    document.addEventListener('input', (e) => {
        if (e.target.classList.contains('return-qty')) {
            calculateTotalDue();
            receivedInput.value = currentTotalDue.toFixed(2);
            balanceInput.value = "0.00";
            updateStatusLabel(0);
        }
    });

    // Initial load setup
    calculateTotalDue();
    receivedInput.value = currentTotalDue.toFixed(2);
    balanceInput.value = "0.00";
    updateStatusLabel(0);
</script>
</body>
</html>