<?php
session_start();
require_once "auth/conn.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

$filter     = isset($_GET['filter']) ? $_GET['filter'] : 'All';
$search     = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date   = isset($_GET['end_date']) ? $_GET['end_date'] : '';

$whereClauses = [];
$params = [];

if ($filter === 'In') {
    $whereClauses[] = "il.action = 'Added'";
} elseif ($filter === 'Out') {
    $whereClauses[] = "il.action = 'Removed'";
} elseif ($filter === 'Retail') { 
    $whereClauses[] = "il.notes LIKE :retail_note";
    $params[':retail_note'] = '%Retail%';
} elseif ($filter === 'Wholesale') { 
    // Uses LOWER() so it catches 'wholesale', 'Wholesale', 'REMIT', 'remit', 'dispatch', etc.
    $whereClauses[] = "(
        LOWER(il.notes) LIKE :wholesale_note 
        OR LOWER(il.notes) LIKE :return_note 
        OR LOWER(il.notes) LIKE :remit_note 
        OR LOWER(il.notes) LIKE :dispatch_note
    )";
    $params[':wholesale_note'] = '%wholesale%';
    $params[':return_note']    = '%returned%';
    $params[':remit_note']     = '%remit%';
    $params[':dispatch_note']  = '%dispatch%';
}
if (!empty($search)) {
    $whereClauses[] = "(p.product_name LIKE :search OR il.notes LIKE :search OR il.admin_name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($start_date) && !empty($end_date)) {
    $whereClauses[] = "DATE(il.created_at) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date']   = $end_date;
}

$whereSQL = !empty($whereClauses) ? " WHERE " . implode(" AND ", $whereClauses) : "";

// --- EXPORT TO CSV / EXCEL LOGIC ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportQuery = "SELECT il.created_at, il.admin_name, p.product_name, p.variation, il.action, il.quantity_change, il.notes
                    FROM inventory_logs il 
                    JOIN products p ON il.product_id = p.id
                    $whereSQL
                    ORDER BY il.created_at DESC";

    $stmtExport = $pdo->prepare($exportQuery);
    $stmtExport->execute($params);
    $exportLogs = $stmtExport->fetchAll(PDO::FETCH_ASSOC);

    $filename = "inventory_movement_report_" . date('Y-m-d_H-i') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    // Add UTF-8 BOM for Microsoft Excel compatibility
    fputs($output, "\xEF\xBB\xBF");

    // CSV Header row
    fputcsv($output, ['Date & Time', 'Admin/Source', 'Product Name', 'Variation', 'Action Type', 'Quantity Change', 'Notes']);

    // CSV Data rows
    foreach ($exportLogs as $row) {
        $changeSign = ($row['action'] === 'Added') ? '+' : '-';
        fputcsv($output, [
            date('M d, Y h:i A', strtotime($row['created_at'])),
            $row['admin_name'],
            $row['product_name'],
            $row['variation'],
            $row['action'] === 'Added' ? 'IN' : 'OUT',
            $changeSign . $row['quantity_change'],
            $row['notes']
        ]);
    }

    fclose($output);
    exit();
}

$query = "SELECT il.*, p.product_name, p.variation
        FROM inventory_logs il 
        JOIN products p ON il.product_id = p.id
        $whereSQL
        ORDER BY il.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals calculation reflecting active date filter if set
$dateWhere = "";
$dateParams = [];
if (!empty($start_date) && !empty($end_date)) {
    $dateWhere = " WHERE DATE(created_at) BETWEEN :s_date AND :e_date";
    $dateParams[':s_date'] = $start_date;
    $dateParams[':e_date'] = $end_date;
}

$stmtIn = $pdo->prepare("SELECT SUM(quantity_change) FROM inventory_logs WHERE action = 'Added'" . ($dateWhere ? " AND DATE(created_at) BETWEEN :s_date AND :e_date" : ""));
$stmtIn->execute($dateParams);
$totalIn = $stmtIn->fetchColumn() ?? 0;

$stmtOut = $pdo->prepare("SELECT SUM(quantity_change) FROM inventory_logs WHERE action = 'Removed'" . ($dateWhere ? " AND DATE(created_at) BETWEEN :s_date AND :e_date" : ""));
$stmtOut->execute($dateParams);
$totalOut = $stmtOut->fetchColumn() ?? 0;

function e(mixed $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/logs.css">
    <style>
        .report-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .report-modal.active { display: flex; }
        .modal-card { background: #fff; padding: 25px; border-radius: 12px; width: 100%; max-width: 450px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: #1e293b; }
        .close-btn { cursor: pointer; font-size: 18px; color: #64748b; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
        .btn-gen { width: 100%; background: #f28c28; color: #fff; border: none; padding: 12px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; text-align: center; text-decoration: none; display: block; box-sizing: border-box; }
        .btn-gen:hover { background: #ea580c; }
        .btn-excel { background: #16a34a; }
        .btn-excel:hover { background: #15803d; }
        .btn-report-trigger { background: #1e293b; color: #fff; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-report-trigger:hover { background: #0f172a; }

        @media print {
            body * { visibility: hidden; }
            #printable-report, #printable-report * { visibility: visible; }
            #printable-report { position: absolute; left: 0; top: 0; width: 100%; }
            .sidebar, .header, .controls-row, .summary-cards, .btn-report-trigger, .report-modal { display: none !important; }
            .print-header-info { display: block !important; margin-bottom: 20px; text-align: center; }
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
            <a href="inventory_logs.php" class="nav-item active" data-title="Inventory Logs">
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
                    <h1 style="white-space: nowrap; margin-right: 20px;">Inventory Logs Overview</h1>
                </div>
                <div class="header-right">
                    <button class="btn-report-trigger" id="openModalBtn">
                        <i class="fa-solid fa-file-pdf"></i> Generate Report
                    </button>
                </div>
            </header>
            
            <section class="report-container">
                <div class="summary-cards">
                    <div class="s-card">
                        <i class="fa-solid fa-circle-arrow-down fa-2x" style="color:var(--success)"></i>
                        <div><small>Total Stock In</small><h3><?= number_format($totalIn) ?></h3></div>
                    </div>
                    <div class="s-card">
                        <i class="fa-solid fa-circle-arrow-up fa-2x" style="color:var(--danger)"></i>
                        <div><small>Total Stock Out</small><h3><?= number_format($totalOut) ?></h3></div>
                    </div>
                </div>

                <div class="controls-row">
                    <div class="filter-group" style="display:flex; gap:8px; flex-wrap: wrap;">
                        <?php 
                            $queryString = '';
                            if (!empty($search)) $queryString .= "&search=" . urlencode($search);
                            if (!empty($start_date) && !empty($end_date)) $queryString .= "&start_date=$start_date&end_date=$end_date";
                        ?>
                        <a href="?filter=All<?= $queryString ?>" class="filter-btn <?= $filter === 'All' ? 'active' : '' ?>">All</a>
                        <a href="?filter=In<?= $queryString ?>" class="filter-btn <?= $filter === 'In' ? 'active' : '' ?>">In</a>
                        <a href="?filter=Out<?= $queryString ?>" class="filter-btn <?= $filter === 'Out' ? 'active' : '' ?>">Out</a>
                        <a href="?filter=Retail<?= $queryString ?>" class="filter-btn <?= $filter === 'Retail' ? 'active' : '' ?>">Retail</a>
                        <a href="?filter=Wholesale<?= $queryString ?>" class="filter-btn <?= $filter === 'Wholesale' ? 'active' : '' ?>">Wholesale</a>
                    </div>
                    <form method="GET" style="display:flex; gap:10px;">
                        <input type="hidden" name="filter" value="<?= e($filter) ?>">
                        <?php if(!empty($start_date)): ?>
                            <input type="hidden" name="start_date" value="<?= e($start_date) ?>">
                            <input type="hidden" name="end_date" value="<?= e($end_date) ?>">
                        <?php endif; ?>
                        <input type="text" name="search" placeholder="Search product or note..." value="<?= e($search) ?>" style="padding:10px; border-radius:8px; border:1px solid #ddd; width: 100%; max-width: 300px; box-sizing: border-box;">
                    </form>
                </div>

                <div class="table-responsive" id="printable-report">
                    <div class="print-header-info" style="display:none;">
                        <h2>INVENTORY STOCK MOVEMENT REPORT</h2>
                        <p>Period: <?= !empty($start_date) ? e($start_date) . ' to ' . e($end_date) : 'All Time History' ?></p>
                        <p>Total Stock In: <strong>+<?= number_format($totalIn) ?></strong> | Total Stock Out: <strong>-<?= number_format($totalOut) ?></strong></p>
                        <hr style="margin: 15px 0;">
                    </div>
                    <table class="log-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Admin/Source</th>
                                <th>Product</th>
                                <th>Action Type</th>
                                <th>Movement</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; padding: 20px; color: #94a3b8;">No inventory movement logs found for the selected criteria.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
                                    <td><i class="fa-solid fa-user-tag" style="color:#94a3b8"></i> <?= e($log['admin_name']) ?></td>
                                    <td><b><?= e($log['product_name']) ?></b> <br><small><?= e($log['variation']) ?></small></td>
                                    <td>
                                        <?php if(strpos($log['notes'], 'Retail') !== false): ?>
                                            <span class="badge badge-retail">OUT</span>
                                        <?php elseif(strpos($log['notes'], 'Remit') !== false): ?>
                                            <span class="badge badge-wholesale">WHOLESALE</span>
                                        <?php else: ?>
                                            <span class="badge <?= $log['action'] == 'Added' ? 'badge-in' : 'badge-out' ?>">
                                                <?= $log['action'] == 'Added' ? 'IN' : 'OUT' ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight:bold; color: <?= $log['action'] == 'Added' ? 'var(--success)' : 'var(--danger)' ?>;">
                                        <?= $log['action'] == 'Added' ? '+' : '-' ?> <?= number_format($log['quantity_change']) ?>
                                    </td>
                                    <td style="color: #64748b; font-style: italic; font-size: 0.8rem;"><?= e($log['notes']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <!-- Generate Report Modal -->
    <div class="report-modal" id="reportModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Generate Stock Movement Report</h3>
                <i class="fa-solid fa-xmark close-btn" id="closeModalBtn"></i>
            </div>
            <form method="GET" action="inventory_logs.php">
                <input type="hidden" name="filter" value="<?= e($filter) ?>">
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?= e($start_date ?: date('Y-m-01')) ?>" required>
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?= e($end_date ?: date('Y-m-d')) ?>" required>
                </div>
                <button type="submit" name="print" value="1" class="btn-gen" style="background:#0f172a; margin-bottom: 10px;">
                    <i class="fa-solid fa-print"></i> Print / Export PDF
                </button>
                <button type="submit" name="export" value="csv" class="btn-gen btn-excel">
                    <i class="fa-solid fa-file-excel"></i> Export Filtered to Excel/CSV
                </button>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('sidebarToggle').addEventListener('click', () => {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('active');
            sidebar.classList.toggle('collapsed');
        });

        const reportModal = document.getElementById('reportModal');
        const openModalBtn = document.getElementById('openModalBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');

        openModalBtn.addEventListener('click', () => reportModal.classList.add('active'));
        closeModalBtn.addEventListener('click', () => reportModal.classList.remove('active'));
        window.addEventListener('click', (e) => {
            if (e.target === reportModal) reportModal.classList.remove('active');
        });

        <?php if (isset($_GET['print']) && $_GET['print'] === '1'): ?>
            window.addEventListener('load', () => {
                window.print();
            });
        <?php endif; ?>
    </script>
</body>
</html>