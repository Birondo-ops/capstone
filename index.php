<?php
session_start();
require_once "auth/conn.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: auth/login.php");
    exit();
}

$today = date('Y-m-d');
$currentYear = date('Y');

// Five-Month Revenue Calculation
$fiveMonthsAgo = date('Y-m-01', strtotime("-4 month"));

$query = "SELECT m, SUM(revenue) as total_rev
        FROM (
            SELECT DATE_FORMAT(date_today, '%Y-%m') as m, total_collected as revenue 
            FROM dispatch_sessions
            WHERE status = 'Completed' AND date_today >= :start_date1
            UNION ALL
            SELECT DATE_FORMAT(order_date, '%Y-%m') as m, subtotal as revenue 
            FROM retail_orders
            WHERE order_date >= :start_date2
        ) as combined_sales
        GROUP BY m ORDER BY m ASC";

$stmt = $pdo->prepare($query);
$stmt->execute(['start_date1' => $fiveMonthsAgo, 'start_date2' => $fiveMonthsAgo]);
$dataMap = [];
while ($row = $stmt->fetch()) {
    $dataMap[$row['m']] = (float)$row['total_rev']; 
}

$currentMonthKey = date('Y-m');
$currentMonthRevenue = $dataMap[$currentMonthKey] ?? 0;

$months = [];
for ($i = 4; $i >= 0; $i--) { $months[] = date('Y-m', strtotime("-$i month")); }
$monthlyLabels = [];
$monthlyValues = [];
foreach ($months as $m) {
    $monthlyLabels[] = date('M', strtotime($m));
    $monthlyValues[] = $dataMap[$m] ?? 0;
}

// Daily Sales Calculation
$dailySalesQuery = "SELECT SUM(rev) FROM (
                        SELECT total_collected as rev FROM dispatch_sessions WHERE status='Completed' AND date_today = :today1 
                        UNION ALL 
                        SELECT subtotal FROM retail_orders WHERE order_date = :today2
                    ) as t";
$stmtDaily = $pdo->prepare($dailySalesQuery);
$stmtDaily->execute(['today1' => $today, 'today2' => $today]);
$dailySales = $stmtDaily->fetchColumn() ?: 0;

// Yearly Sales Calculation
$yearlySalesQuery = "SELECT SUM(rev) FROM (
                        SELECT total_collected as rev FROM dispatch_sessions WHERE status='Completed' AND YEAR(date_today) = :year1 
                        UNION ALL 
                        SELECT subtotal FROM retail_orders WHERE YEAR(order_date) = :year2
                    ) as t_year";
$stmtYearly = $pdo->prepare($yearlySalesQuery);
$stmtYearly->execute(['year1' => $currentYear, 'year2' => $currentYear]);
$yearlySales = $stmtYearly->fetchColumn() ?: 0;

// Inventory Metrics
$activeProductCount = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$lowStockCount = $pdo->query("SELECT COUNT(*) FROM products WHERE quantity <= 30")->fetchColumn();

// Worker Balances Metrics
$pendingBalancesQuery = "SELECT DISTINCT worker_name 
                        FROM worker_balances 
                        WHERE status = 'Pending' 
                        ORDER BY worker_name ASC";
$pendingWorkers = $pdo->query($pendingBalancesQuery)->fetchAll(PDO::FETCH_COLUMN);

$totalPendingBalanceQuery = "SELECT SUM(shortage_amount) FROM worker_balances WHERE status = 'Pending'";
$totalPendingShortage = $pdo->query($totalPendingBalanceQuery)->fetchColumn() ?: 0;

// Top Demand Products Query
$topCombinedQuery = "SELECT p.product_name, SUM(all_sales.total_qty) as total_sold
                    FROM (
                        SELECT product_id, SUM(qty) as total_qty 
                        FROM retail_orders 
                        GROUP BY product_id
                        UNION ALL
                        SELECT product_id, SUM(qty_sold) as total_qty 
                        FROM dispatch_items 
                        GROUP BY product_id
                    ) as all_sales
                    JOIN products p ON all_sales.product_id = p.id
                    GROUP BY p.id, p.product_name
                    ORDER BY total_sold DESC
                    LIMIT 5";

$topProductsRes = $pdo->query($topCombinedQuery)->fetchAll();
$topProductLabels = [];
$topProductValues = [];
foreach ($topProductsRes as $prod) {
    $topProductLabels[] = $prod['product_name'];
    $topProductValues[] = (int)$prod['total_sold'];
}

// Retail vs Wholesale Channel Comparison
$combinedTopQuery = "SELECT p.product_name, SUM(combined.retail_qty) as r_qty, SUM(combined.wholesale_qty) as w_qty
                    FROM (
                        SELECT product_id, qty as retail_qty, 0 as wholesale_qty FROM retail_orders
                        UNION ALL
                        SELECT product_id, 0 as retail_qty, qty_sold as wholesale_qty FROM dispatch_items
                    ) as combined
                    JOIN products p ON combined.product_id = p.id
                    GROUP BY p.id, p.product_name
                    ORDER BY (SUM(combined.retail_qty) + SUM(combined.wholesale_qty)) DESC
                    LIMIT 5";
$combinedRes = $pdo->query($combinedTopQuery)->fetchAll();

$compLabels = [];
$compRetailValues = [];
$compWholesaleValues = [];
foreach($combinedRes as $row) {
    $compLabels[] = $row['product_name'];
    $compRetailValues[] = (int)$row['r_qty'];
    $compWholesaleValues[] = (int)$row['w_qty'];
}

// Dispatcher Performance Query
$logisticsQuery = "SELECT ds.worker_name, SUM(di.qty_taken) as taken, SUM(di.qty_sold) as sold, SUM(di.qty_returned) as returned
                FROM dispatch_items di
                JOIN dispatch_sessions ds ON di.session_id = ds.id
                WHERE ds.status = 'Completed'
                GROUP BY ds.worker_name
                ORDER BY sold DESC LIMIT 5";
$logisticsRes = $pdo->query($logisticsQuery)->fetchAll();

$workerLabels = [];
$workerSoldValues = [];
$workerReturnValues = [];
foreach($logisticsRes as $row) {
    $workerLabels[] = $row['worker_name'];
    $workerSoldValues[] = (int)$row['sold'];
    $workerReturnValues[] = (int)$row['returned'];
}

// ML Forecast Prediction Query
$nextMonthStr = date('Y-m', strtotime("+1 month"));
$mlPredictionQuery = "SELECT predicted_revenue FROM ml_predictions WHERE target_period = :next_month LIMIT 1";
$stmt = $pdo->prepare($mlPredictionQuery);
$stmt->execute(['next_month' => $nextMonthStr]);
$forecast = $stmt->fetchColumn();

if ($forecast === false) {
    $forecast = count($monthlyValues) > 0 ? array_sum($monthlyValues) / count($monthlyValues) : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/indix.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        .btn-generate-report {
            background-color: #f28c28;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s ease, transform 0.1s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn-generate-report:hover {
            background-color: #ea580c;
        }
        .btn-generate-report:active {
            transform: scale(0.98);
        }

        .report-header-info {
            display: none;
            margin-bottom: 25px;
            border-bottom: 2px solid #e3e6f0;
            padding-bottom: 15px;
        }
        .report-header-info h1 {
            margin: 0 0 5px 0;
            font-size: 1.8rem;
            color: #f28c28;
        }
        .report-header-info p {
            margin: 0;
            color: #5a5c69;
            font-size: 0.85rem;
        }

        .worker-names-badge {
            font-size: 0.8rem;
            color: #e74a3b;
            font-weight: 600;
            margin-top: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media print {
            body {
                background-color: #ffffff !important;
                color: #000000 !important;
                font-family: Arial, sans-serif !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .sidebar, 
            .hamburger-btn, 
            .btn-generate-report,
            header.header {
                display: none !important;
            }

            .report-header-info {
                display: block !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .container {
                display: block !important;
                width: 100% !important;
            }

            .dashboard-grid,
            .charts-container,
            .analysis-container {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 15px !important;
                margin-bottom: 20px !important;
                page-break-inside: avoid;
            }

            .stat-card,
            .content-box {
                border: 1px solid #e3e6f0 !important;
                box-shadow: none !important;
                padding: 12px !important;
                page-break-inside: avoid;
            }

            canvas {
                max-width: 100% !important;
                height: auto !important;
            }
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
            <a href="index.php" class="nav-item active" data-title="Dashboard">
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
            <a href="balance.php" class="nav-item " data-title="Worker Balances">
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

        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <button id="sidebarToggle" class="hamburger-btn"><i class="fa-solid fa-bars"></i></button>
                    <h1 style="white-space: nowrap; margin-right: 20px;">Dashboard Overview</h1>
                </div>
                <div class="header-right">
                    <button onclick="window.print()" class="btn-generate-report">
                        <i class="fa-solid fa-file-pdf"></i> Generate Report
                    </button>
                </div>
            </header>

            <div class="report-header-info">
                <h1>Executive Sales & Operations Report</h1>
                <p><strong>Generated On:</strong> <?= date('F j, Y, g:i a') ?> | <strong>System Administrator ID:</strong> <?= htmlspecialchars($_SESSION['admin_id']) ?></p>
            </div>

            <!-- Dashboard Grid with All Stat Cards -->
            <div class="dashboard-grid">
                <div class="stat-card" style="border-left: 5px solid #36b9cc;">
                    <h3 style="color:#36b9cc; font-size: 0.8rem;">DAILY REVENUE</h3>
                    <div class="value">₱<?= number_format($dailySales, 2) ?></div>
                </div>

                <div class="stat-card" style="border-left: 5px solid #f28c28;">
                    <h3 style="color:#f28c28; font-size: 0.8rem;">MONTH REVENUE</h3>
                    <div class="value">₱<?= number_format($currentMonthRevenue, 2) ?></div>
                </div>

                <div class="stat-card" style="border-left: 5px solid #4e73df;">
                    <h3 style="color:#4e73df; font-size: 0.8rem;">YEARLY REVENUE (<?= $currentYear ?>)</h3>
                    <div class="value">₱<?= number_format($yearlySales, 2) ?></div>
                </div>

                <!-- Worker Balances Card -->
                <div class="stat-card" style="border-left: 5px solid #e74a3b;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="color:#e74a3b; font-size: 0.8rem; margin: 0;">WORKER BALANCES</h3>
                        <a href="balance.php" style="color: #f28c28; font-size: 0.75rem; text-decoration: none; font-weight: bold;">View All <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="value" style="color: #e74a3b;">₱<?= number_format($totalPendingShortage, 2) ?></div>
                    <div class="worker-names-badge">
                        <?php if (count($pendingWorkers) > 0): ?>
                            <i class="fa-solid fa-user-clock"></i> <?= htmlspecialchars(implode(', ', $pendingWorkers)) ?>
                        <?php else: ?>
                            <span style="color: #1cc88a;"><i class="fa-solid fa-circle-check"></i> All balances cleared</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Active Inventory Card -->
                <div class="stat-card" style="border-left: 5px solid #1cc88a;">
                    <h3 style="color:#1cc88a; font-size: 0.8rem;">ACTIVE INVENTORY</h3>
                    <div class="value"><?= $activeProductCount ?></div>
                    <span class="sub-value" style="font-size: 0.8rem;">Products in stock</span>
                </div>

                <!-- Low Stock Alert Card -->
                <div class="stat-card" style="border-left: 5px solid #f6c23e;">
                    <h3 style="color:#f6c23e; font-size: 0.8rem;">LOW STOCK ALERT</h3>
                    <div class="value" style="color: #f6c23e;"><?= $lowStockCount ?></div>
                    <span class="sub-value" style="font-size: 0.8rem; color: #858796;">Products ≤ 30 items</span>
                </div>
            </div>

            <div class="charts-container" style="margin-top: 25px;">
                <div class="content-box">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                        <h2>5-Month Revenue</h2>
                        <div style="background:#e8f5e9; padding:10px; border-radius:8px; border: 1px solid #1cc88a;">
                            <small style="color:#1cc88a; font-weight:bold;"><i class="fa-solid fa-brain"></i> Forecast:</small><br/>
                            <strong style="color:#1cc88a;">₱<?= number_format($forecast, 2) ?></strong>
                        </div>
                    </div>
                    <div style="height: 300px;"><canvas id="liveTrendChart"></canvas></div>
                </div>

                <div class="content-box">
                    <div style="margin-bottom: 15px;">
                        <h2>Top Demand Products</h2>
                    </div>
                    <div style="height: 300px;"><canvas id="topProductsChart"></canvas></div>
                </div>
            </div>

            <div class="analysis-container">
                <div class="content-box">
                    <div style="margin-bottom: 15px;">
                        <h2>Retail vs Wholesale</h2>
                    </div>
                    <div style="height: 300px;"><canvas id="channelCompChart"></canvas></div>
                </div>

                <div class="content-box">
                    <div style="margin-bottom: 15px;">
                        <h2>Dispatcher Performance & Return</h2>
                    </div>
                    <div style="height: 300px;"><canvas id="dispatcherLogisticsChart"></canvas></div>
                </div>
            </div>
        </main>
    </div>

    <script>
        const ctx = document.getElementById('liveTrendChart').getContext('2d');
        new Chart(ctx, {
            data: {
                labels: <?= json_encode($monthlyLabels) ?>,
                datasets: [
                    { type: 'bar', label: 'Revenue', data: <?= json_encode($monthlyValues) ?>, backgroundColor: 'rgba(242, 140, 40, 0.2)', borderColor: '#f28c28', borderWidth: 1 },
                    { type: 'line', label: 'Trend', data: <?= json_encode($monthlyValues) ?>, borderColor: '#ea580c', borderWidth: 3, tension: 0.4 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });

        const ctxProd = document.getElementById('topProductsChart').getContext('2d');
        new Chart(ctxProd, {
            type: 'bar',
            data: {
                labels: <?= json_encode($topProductLabels) ?>,
                datasets: [{
                    label: 'Total Units Sold (Retail + Wholesale)',
                    data: <?= json_encode($topProductValues) ?>,
                    backgroundColor: 'rgba(28, 200, 138, 0.2)',
                    borderColor: '#1cc88a',
                    borderWidth: 1
                }]
            },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });

        const ctxChannel = document.getElementById('channelCompChart').getContext('2d');
        new Chart(ctxChannel, {
            type: 'bar',
            data: {
                labels: <?= json_encode($compLabels) ?>,
                datasets: [
                    { label: 'Retail Sales', data: <?= json_encode($compRetailValues) ?>, backgroundColor: '#4e73df' },
                    { label: 'Wholesale Sales', data: <?= json_encode($compWholesaleValues) ?>, backgroundColor: '#f28c28' }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true } } }
        });

        const ctxLogistics = document.getElementById('dispatcherLogisticsChart').getContext('2d');
        new Chart(ctxLogistics, {
            type: 'bar',
            data: {
                labels: <?= json_encode($workerLabels) ?>,
                datasets: [
                    { label: 'Qty Sold', data: <?= json_encode($workerSoldValues) ?>, backgroundColor: '#1cc88a' },
                    { label: 'Qty Returned', data: <?= json_encode($workerReturnValues) ?>, backgroundColor: '#e74a3b' }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
        });

        document.getElementById('sidebarToggle').addEventListener('click', () => {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('active');
            sidebar.classList.toggle('collapsed');
        });
    </script>
</body>
</html>
