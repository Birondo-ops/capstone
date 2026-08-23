<?php
session_start();
require_once "auth/conn.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$admin_name = $_SESSION['admin_name'] ?? 'System';
$msg = "";

// Handle Balance Settlement & Sales Revenue Logging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settle_id'])) {
    $balance_id = (int)$_POST['settle_id'];

    try {
        $pdo->beginTransaction();

        //Retrieve worker balance details
        $stmtGet = $pdo->prepare("SELECT session_id, worker_name, shortage_amount FROM worker_balances WHERE id = ? AND status = 'Pending'");
        $stmtGet->execute([$balance_id]);
        $balance = $stmtGet->fetch(PDO::FETCH_ASSOC);

        if ($balance) {
            $session_id = $balance['session_id'];
            $worker = $balance['worker_name'];
            $amount = (float)$balance['shortage_amount'];

            // Mark worker balance as Paid
            $stmtPay = $pdo->prepare("UPDATE worker_balances SET status = 'Paid', paid_at = NOW() WHERE id = ?");
            $stmtPay->execute([$balance_id]);

            //Record into sales table as 'BALANCE' type
            $stmtSales = $pdo->prepare("
                INSERT INTO sales (type, product, worker, qty, unit_price, subtotal, created_at) 
                VALUES ('BALANCE', ?, ?, 1, ?, ?, NOW())
            ");
            $stmtSales->execute([
                "Shortage Settlement",
                $worker,
                $amount,
                $amount
            ]);

            $pdo->commit();
            $msg = "Balance successfully settled and logged into Sales!";
        } else {
            $pdo->rollBack();
            $msg = "Record not found or balance is already settled.";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = "Error updating balance: " . $e->getMessage();
    }
}

// Fetch all worker balances from database
$stmtFetch = $pdo->prepare("SELECT id, session_id, worker_name, shortage_amount, status, created_at, paid_at FROM worker_balances ORDER BY id DESC");
$stmtFetch->execute();
$balances = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Balance Management - Salescore</title>
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; } 
        body { font-family: 'Segoe UI', sans-serif; background-color: #f8fafc; color: #334155; }
        html, body { overflow-x: auto !important; } 
        .layout-wrapper { display: flex; min-height: 100vh; width: 100%; }
        .sidebar { width: 240px; background-color: #ffffff; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); overflow: visible; white-space: nowrap; flex-shrink: 0; position: relative; z-index: 1000; }
        .sidebar.collapsed { width: 75px; } 
        .sidebar-header { min-height: 90px; padding: 15px 25px; display: flex; align-items: center; justify-content: center; position: relative; box-sizing: border-box; }
        .sidebar-logo { width: 100px; height: 100px; object-fit: contain; border-radius: 50%; transition: all 0.3s ease; display: block; margin: 0 auto; flex-shrink: 0; }
        .sidebar.collapsed .sidebar-logo { width: 45px; height: 45px; } 
        .sidebar nav { flex: 1; padding: 15px 10px; display: flex; flex-direction: column; gap: 6px; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 10px; color: #334155; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.2s ease; position: relative; } 
        .nav-item .icon { width: 20px; text-align: center; font-size: 16px; flex-shrink: 0; } 
        .nav-item span { transition: opacity 0.2s ease, visibility 0.2s ease; } 
        .sidebar.collapsed .nav-item span { opacity: 0; visibility: hidden; pointer-events: none; } 
        .nav-item:hover { background: #fff7ed; color: #f28c28; } 
        .nav-item.active { background-color: #fff5eb; color: #f28c28; border-right: 3px solid #f28c28; } 
        .sidebar.collapsed .nav-item::after { content: attr(data-title); position: absolute; left: 85px; background: #333333; color: #ffffff; padding: 8px 12px; font-size: 0.85rem; border-radius: 6px; opacity: 0; visibility: hidden; transform: translateX(-10px); transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s; pointer-events: none; z-index: 99999; box-shadow: 0 4px 10px rgba(0,0,0,0.15); white-space: nowrap; } 
        .sidebar.collapsed .nav-item::before { content: ""; position: absolute; left: 77px; border-width: 5px; border-style: solid; border-color: transparent #333333 transparent transparent; opacity: 0; visibility: hidden; transform: translateX(-10px); transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s; pointer-events: none; z-index: 99999; } 
        .sidebar.collapsed .nav-item:hover::after, 
        .sidebar.collapsed .nav-item:hover::before { opacity: 1; visibility: visible; transform: translateX(0); } 
        .main-content { flex: 1; display: flex; flex-direction: column; min-width: 0; background-color: #f8fafc; } 
        .top-bar { background-color: #f28c28; height: 70px; padding: 0 30px; display: flex; justify-content: space-between; align-items: center; box-sizing: border-box; box-shadow: 0 2px 4px rgba(0,0,0,0.08); } 
        .top-bar-left { display: flex; align-items: center; gap: 20px; } 
        .top-bar h1 { color: #ffffff; margin: 0; font-size: 1.25rem; font-weight: 700; } 
        .hamburger-btn { background: none; border: none; color: #ffffff; font-size: 1.25rem; cursor: pointer; padding: 5px; display: flex; align-items: center; transition: transform 0.2s ease; } 
        .hamburger-btn:hover { transform: scale(1.1); } 
        .content-body { padding: 30px; flex-grow: 1; } 
        .container { background: #ffffff; padding: 30px; border-radius: 16px; max-width: 1100px; margin: 0 auto; box-shadow: 0 4px 12px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; } 
        .card-title { color: #f28c28; font-weight: 800; font-size: 20px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #fff7ed; display: flex; align-items: center; gap: 10px; } 
        .alert { background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; border-left: 4px solid #10b981; } 
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 14px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        th { background-color: #fffcf8; color: #f28c28; font-weight: 700; border-bottom: 2px solid #ffe4c4; }
        tr:hover { background-color: #fdfbf7; } 
        .badge { display: inline-block; padding: 5px 12px; border-radius: 6px; font-weight: 700; font-size: 12px; } 
        .badge-pending { background-color: #fee2e2; color: #991b1b; } 
        .badge-paid { background-color: #d1fae5; color: #065f46; } 
        .btn-pay { background: #f28c28; color: white; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; transition: all 0.2s ease; } 
        .btn-pay:hover { background: #ea580c; } 
        @media (max-width: 768px) { 
            .analytics-row, .data-row { flex-direction: column; padding: 10px; } 
            .sidebar { width: 75px; position: relative; z-index: 99999 !important; } 
            .sidebar span { display: none; } 
            .sidebar-header { padding: 10px 5px; min-height: 75px; } 
            .sidebar-logo { width: 50px !important; height: 50px !important; } 
            .sidebar .nav-item::after { content: attr(data-title); position: absolute; left: 85px; background: #333333; color: #ffffff; padding: 8px 12px; font-size: 0.85rem; border-radius: 6px; opacity: 0; visibility: hidden; transform: translateX(-10px); transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s; pointer-events: none; z-index: 99999; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15); white-space: nowrap; } 
            .sidebar .nav-item::before { content: ""; position: absolute; left: 77px; border-width: 5px; border-style: solid; border-color: transparent #333333 transparent transparent; opacity: 0; visibility: hidden; transform: translateX(-10px); transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s; pointer-events: none; z-index: 99999; } 
            .sidebar .nav-item:hover::after, .sidebar .nav-item:hover::before { opacity: 1; visibility: visible; transform: translateX(0); } 
        }
    </style>
</head>
<body>

<div class="layout-wrapper">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="assets/img/logo.png" alt="Salescore Logo" class="sidebar-logo">
        </div>
        <nav style="flex-grow: 1;">
            <a href="index.php" class="nav-item" data-title="Dashboard">
                <div class="icon"><i class="fa-solid fa-chart-line"></i></div>
                <span>Dashboard</span>
            </a>
            <a href="inventory.php" class="nav-item" data-title="Inventory">
                <div class="icon"><i class="fa-solid fa-boxes-packing"></i></div>
                <span>Inventory</span>
            </a>
            <a href="inventory_logs.php" class="nav-item" data-title="Inventory Logs">
                <div class="icon"><i class="fa-solid fa-route"></i></div>
                <span>Inventory Logs</span>
            </a>
            <a href="dispatchers.php" class="nav-item" data-title="Dispatchers">
                <div class="icon"><i class="fa-solid fa-clipboard-list"></i></div>
                <span>Dispatchers</span>
            </a>
            <a href="balance.php" class="nav-item active" data-title="Worker Balances">
                <div class="icon"><i class="fa-solid fa-scale-unbalanced"></i></div>
                <span>Worker Balances</span>
            </a>
            <a href="retailer.php" class="nav-item" data-title="Retailer">
                <div class="icon"><i class="fa-solid fa-shop"></i></div>
                <span>Retailer</span>
            </a>
            <a href="audit_trail.php" class="nav-item" data-title="Audit Trail">
                <div class="icon"><i class="fa-solid fa-clipboard-list"></i></div>
                <span>Audit Trail</span>
            </a>
            <a href="sales.php" class="nav-item" data-title="Sales History">
                <div class="icon"><i class="fa-solid fa-coins"></i></div>
                <span>Sales History</span>
            </a>
            <a href="setting.php" class="nav-item" data-title="Settings">
                <div class="icon"><i class="fa-solid fa-gears"></i></div>
                <span>Settings</span>
            </a>
        </nav>
    </aside>

    <!-- Main Content Area -->
    <main class="main-content">
        <!-- Top Navigation Bar -->
        <header class="top-bar">
            <div class="top-bar-left">
                <button id="sidebarToggle" class="hamburger-btn"><i class="fa-solid fa-bars"></i></button>
                <h1 style="white-space: nowrap;">Worker Balance Management</h1>
            </div>
        </header>

        <!-- Body Content -->
        <div class="content-body">
            <div class="container">
                <div class="card-title">
                    <i class="fa-solid fa-scale-unbalanced"></i> WORKER BALANCE & SHORTAGE RECORDS
                </div>

                <?php if (!empty($msg)): ?>
                    <div class="alert"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>

                <table>
                    <thead>
                        <tr>
                            <th>Session #</th>
                            <th>Worker Name</th>
                            <th>Shortage Amount</th>
                            <th>Status</th>
                            <th>Date Logged</th>
                            <th>Date Paid</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($balances)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: #64748b; padding: 20px;">No balance records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($balances as $row): ?>
                                <tr>
                                    <td><strong>#<?= htmlspecialchars($row['session_id']) ?></strong></td>
                                    <td><?= htmlspecialchars($row['worker_name']) ?></td>
                                    <td style="font-weight: bold; color: #d9534f;">₱<?= number_format($row['shortage_amount'], 2) ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'Pending'): ?>
                                            <span class="badge badge-pending">Pending</span>
                                        <?php else: ?>
                                            <span class="badge badge-paid">Paid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('M d, Y h:i A', strtotime($row['created_at'])) ?></td>
                                    <td>
                                        <?= $row['paid_at'] ? date('M d, Y h:i A', strtotime($row['paid_at'])) : '-' ?>
                                    </td>
                                    <td>
                                        <?php if ($row['status'] === 'Pending'): ?>
                                            <form method="POST" onsubmit="return confirm('Are you sure you want to mark this balance as PAID?');">
                                                <input type="hidden" name="settle_id" value="<?= $row['id'] ?>">
                                                <button type="submit" class="btn-pay">Mark as Paid</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color: #10b981; font-weight: bold;">✓ Settled</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script>
    // Sidebar Toggle Functionality
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
    });
</script>

</body>
</html>