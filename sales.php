<?php
session_start();
require_once "auth/conn.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

try {
    // Pull sales records directly from the sales table
    $query = "
        SELECT 
            id AS sale_id, 
            type AS sale_type, 
            product AS product_name, 
            worker AS customer_name, 
            qty, 
            unit_price, 
            subtotal AS total, 
            created_at AS sale_date
        FROM sales
        ORDER BY created_at DESC
    ";
        
    $stmt = $pdo->query($query);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($sales) {
        $grand_total = array_sum(array_column($sales, 'total'));
    } else {
        $grand_total = 0;
        $sales = []; 
    }

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    die("A system error occurred. Please try again later.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/sale.css">
    
    <script src="https://cdn.jsdelivr.net/gh/linways/table-to-excel@v1.0.4/dist/tableToExcel.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    
    <style>
        .type-balance {
            background-color: #fff3ed;
            color: #f28c28;
            border: 1px solid #f28c28;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }
        .type-wholesale {
            background-color: #e0f2fe;
            color: #0369a1;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }
        .type-retail {
            background-color: #dcfce7;
            color: #15803d;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }
        .text-na {
            color: #94a3b8;
            font-style: italic;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <aside class="sidebar">
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
                <a href="balance.php" class="nav-item" data-title="Worker Balances">
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
                <a href="sales.php" class="nav-item active" data-title="Sales History">
                    <div class="icon"><i class="fa-solid fa-coins"></i></div>
                    <span>Sales History</span>
                </a>
                <a href="setting.php" class="nav-item" data-title="Settings">
                    <div class="icon"><i class="fa-solid fa-gears"></i></div>
                    <span>Settings</span>
                </a>
            </nav>
        </aside>

        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <button id="sidebarToggle" class="hamburger-btn"><i class="fa-solid fa-bars"></i></button>
                    <h1 style="white-space: nowrap; margin-right: 20px;">Financial Overview</h1>
                </div>
            </header>

            <section class="sales-card">
                <div class="sales-header">
                    <div style="display: flex; gap: 10px;">
                        <button class="action-btn" style="background:#f28c28; color:white; border:none; padding:10px 16px; border-radius:8px; cursor:pointer; font-weight:600;" id="exportBtn">
                            <i class="fa-solid fa-file-excel"></i> Export Excel
                        </button>
                    </div>
                </div>

                <table class="sales-table" id="salesTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Product</th>
                            <th>Worker / Customer</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Subtotal</th>
                            <th>Date</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($sales)): ?>
                            <?php foreach ($sales as $sale): ?>
                            <tr>
                                <td>#<?= htmlspecialchars($sale['sale_id']) ?></td>
                                <td>
                                    <?php 
                                        $type = strtolower(trim($sale['sale_type']));
                                        $typeClass = 'type-retail';
                                        if ($type === 'wholesale') {
                                            $typeClass = 'type-wholesale';
                                        } elseif ($type === 'balance') {
                                            $typeClass = 'type-balance';
                                        }
                                    ?>
                                    <span class="type-tag <?= $typeClass ?>">
                                        <?= htmlspecialchars($sale['sale_type']) ?>
                                    </span>
                                </td>
                                <td><strong><?= htmlspecialchars($sale['product_name']) ?></strong></td>
                                <td><?= htmlspecialchars($sale['customer_name']) ?></td>
                                
                                <!-- Quantity Column -->
                                <td>
                                    <?php if (is_null($sale['qty']) || $sale['qty'] == 0): ?>
                                        <span class="text-na">N/A</span>
                                    <?php else: ?>
                                        <?= number_format($sale['qty']) ?>
                                    <?php endif; ?>
                                </td>

                                <!-- Unit Price Column -->
                                <td>
                                    <?php if (is_null($sale['unit_price']) || $sale['unit_price'] == 0): ?>
                                        <span class="text-na">N/A</span>
                                    <?php else: ?>
                                        ₱<?= number_format($sale['unit_price'], 2) ?>
                                    <?php endif; ?>
                                </td>

                                <td class="amount-text">₱<?= number_format($sale['total'], 2) ?></td>
                                <td><?= date('M d, Y h:i A', strtotime($sale['sale_date'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center; padding:50px; color:#999;">No sales records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>

<script>
    document.getElementById('exportBtn').addEventListener('click', function() {
        TableToExcel.convert(document.getElementById("salesTable"), {
            name: "Sales_Report_<?= date('Y-m-d') ?>.xlsx",
            sheet: { name: "Revenue" }
        });
    });

    document.getElementById('sidebarToggle').addEventListener('click', () => {
        const sidebar = document.querySelector('.sidebar');
        sidebar.classList.toggle('active');
        sidebar.classList.toggle('collapsed');
    });
</script>

</body>
</html>