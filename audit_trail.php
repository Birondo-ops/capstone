<?php

session_start();

require_once "auth/conn.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: auth/login.php");
    exit();
}

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// FILTER VALUES

$selected_year  = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : 0;

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date   = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// BUILD WHERE CLAUSES
$whereClauses = [];
$params = [];

// YEAR FILTER
if ($selected_year > 0) {
    $whereClauses[] = "YEAR(created_at) = :year";
    $params[':year'] = $selected_year;
}

// MONTH FILTER
if ($selected_month >= 1 && $selected_month <= 12) {
    $whereClauses[] = "MONTH(created_at) = :month";
    $params[':month'] = $selected_month;
}

// DATE RANGE FILTER
// Used by Generate Report
if (!empty($start_date) && !empty($end_date)) {

    // If a specific year/month is NOT selected,
    // use the date range.
    if ($selected_year === 0 && $selected_month === 0) {

        $whereClauses[] = "DATE(created_at) BETWEEN :start_date AND :end_date";

        $params[':start_date'] = $start_date;
        $params[':end_date']   = $end_date;
    }
}


$whereSQL = '';

if (!empty($whereClauses)) {
    $whereSQL = " WHERE " . implode(" AND ", $whereClauses);
}

// GET AVAILABLE YEARS
try {

    $yearStmt = $pdo->query("
        SELECT DISTINCT YEAR(created_at) AS audit_year
        FROM audit_trail
        WHERE created_at IS NOT NULL
        ORDER BY audit_year DESC
    ");

    $availableYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (Exception $e) {

    $availableYears = [];

}

// EXPORT TO CSV / EXCEL
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $exportQuery = "
        SELECT
            created_at,
            worker_name,
            product_name,
            qty_taken,
            qty_sold,
            qty_returned,
            received_amount,
            status
        FROM audit_trail
        $whereSQL
        ORDER BY created_at DESC
    ";

    $stmtExport = $pdo->prepare($exportQuery);
    $stmtExport->execute($params);

    $exportLogs = $stmtExport->fetchAll(PDO::FETCH_ASSOC);
    $filename = "audit_trail_report_" . date('Y-m-d_H-i') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    $output = fopen('php://output', 'w');

    // UTF-8 BOM for Microsoft Excel
    fputs($output, "\xEF\xBB\xBF");
    // CSV HEADERS
    fputcsv($output, [
        'Date & Time',
        'Worker Name',
        'Product',
        'Brought (Taken)',
        'Sold',
        'Returned',
        'Amount Collected',
        'Status'
    ]);

    // CSV DATA
    foreach ($exportLogs as $row) {
        fputcsv($output, [
            date(
                'M d, Y h:i A',
                strtotime($row['created_at'])
            ),
            $row['worker_name'],
            $row['product_name'],
            $row['qty_taken'],
            $row['qty_sold'],
            $row['qty_returned'],
            number_format(
                (float)$row['received_amount'],
                2
            ),

            $row['status']
        ]);
    }
    fclose($output);
    exit();
}

// GET AUDIT LOGS
try {

    $stmt = $pdo->prepare("
        SELECT *
        FROM audit_trail
        $whereSQL
        ORDER BY created_at DESC
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error fetching logs: " . $e->getMessage());
}

// DETERMINE REPORT TITLE
$reportPeriod = 'All Time History';
if ($selected_year > 0 && $selected_month > 0) {
    $reportPeriod = date(
        'F Y',
        mktime(0, 0, 0, $selected_month, 1, $selected_year)
    );

} elseif ($selected_year > 0) {
    $reportPeriod = "Year " . $selected_year;
} elseif (!empty($start_date) && !empty($end_date)) {
    $reportPeriod =
        date('M d, Y', strtotime($start_date))
        . ' to ' .
        date('M d, Y', strtotime($end_date));
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"content="width=device-width, initial-scale=1.0">
    <title>Audit Trail</title>
    <!-- Font Awesome -->
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
     <!-- Existing Audit CSS -->
      <link rel="stylesheet" href="assets/css/audit.css">
    <style>
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .report-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }
        .report-modal.active {
            display: flex;
        }

        .modal-card {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            margin: 0;
            color: #1e293b;
        }

        .close-btn {
            cursor: pointer;
            font-size: 18px;
            color: #64748b;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 5px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-sizing: border-box;
            background: #fff;
        }

        .btn-gen {
            width: 100%;
            background: #f28c28;
            color: #fff;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            text-align: center;
            text-decoration: none;
            display: block;
            box-sizing: border-box;
        }

        .btn-gen:hover {
            background: #ea580c;
        }

        .btn-excel {
            background: #16a34a;
        }

        .btn-excel:hover {
            background: #15803d;
        }

        .btn-report-trigger {
            background: #1e293b;
            color: #fff;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-report-trigger:hover {
            background: #0f172a;
        }

        .audit-filter-card {
            background: #fff;
            margin: 20px;
            padding: 18px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }

        .filter-title {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 12px;
        }

        .filter-form {
            display: flex;
            gap: 12px;
            align-items: end;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
        }

        .filter-group select {
            min-width: 160px;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            background: white;
            font-size: 14px;
        }

        .btn-filter {
            background: #f28c28;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 7px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-filter:hover {
            background: #ea580c;
        }

        .btn-reset {
            background: #64748b;
            color: white;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 7px;
            font-weight: 600;
            display: inline-block;
        }

        .btn-reset:hover {
            background: #475569;
        }

        .month-header td {
            background: #fff3e6 !important;
            color: #f28c28 !important;
            font-size: 16px;
            font-weight: 700;
            padding: 14px 12px !important;
            border-top: 2px solid #f28c28 !important;
            border-bottom: 1px solid #f5d5b5 !important;
        }

        .month-header i {
            margin-right: 8px;
        }

        @media print {

            body * {
                visibility: hidden;
            }

            #printable-report,
            #printable-report * {
                visibility: visible;
            }

            #printable-report {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0 !important;
            }

            .sidebar,
            .header,
            .audit-filter-card,
            .btn-report-trigger,
            .report-modal {
                display: none !important;
            }

            .print-header-info {
                display: block !important;
                margin-bottom: 20px;
                text-align: center;
            }
            .month-header td {
                background: #fff3e6 !important;
                color: #000 !important;
            }

        }
        @media (max-width: 768px) {

            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group select,
            .btn-filter,
            .btn-reset {
                width: 100%;
                box-sizing: border-box;
            }

            .audit-filter-card {
                margin: 10px;
            }

            .audit-table-card {
                margin: 10px !important;
                overflow-x: auto;
            }

            .modal-card {
                width: 90%;
            }

        }

    </style>
</head>
<body>

<div class="container">
    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="assets/img/logo.png" alt="Salescore Logo"class="sidebar-logo">
        </div>
        <nav style="flex-grow: 1;">
            <a href="index.php" class="nav-item" data-title="Dashboard">
                <div class="icon">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <span>Dashboard</span>
            </a>

            <a href="inventory.php" class="nav-item"  data-title="Inventory">
                <div class="icon">
                    <i class="fa-solid fa-boxes-packing"></i>
                </div>
                <span>Inventory</span>
            </a>
            <a href="inventory_logs.php" class="nav-item" data-title="Inventory Logs">
                <div class="icon">
                    <i class="fa-solid fa-route"></i>
                </div>
                <span>Inventory Logs</span>
            </a>

            <a href="dispatchers.php" class="nav-item" data-title="Dispatchers">
                <div class="icon">
                    <i class="fa-solid fa-clipboard-list"></i>
                </div>
                <span>Dispatchers</span>
            </a>

            <a href="balance.php" class="nav-item" data-title="Worker Balances">
                <div class="icon">
                    <i class="fa-solid fa-scale-unbalanced"></i>
                </div>
                <span>Worker Balances</span>
             </a>

            <a href="retailer.php" class="nav-item" data-title="Retailer">
                <div class="icon">
                    <i class="fa-solid fa-shop"></i>
                </div>
                <span>Retailer</span>
            </a>

            <a href="audit_trail.php"  class="nav-item active"  data-title="Audit Trail">
                <div class="icon">
                    <i class="fa-solid fa-clipboard-list"></i>
                </div>
                <span>Audit Trail</span>
            </a>

            <a href="sales.php" class="nav-item"  data-title="Sales History">
                <div class="icon">
                    <i class="fa-solid fa-coins"></i>
                </div>
                <span>Sales History</span>
            </a>

            <a href="setting.php" class="nav-item" data-title="Settings">
                <div class="icon">
                    <i class="fa-solid fa-gears"></i>
                </div>
                <span>Settings</span>
            </a>
        </nav>
    </aside>
    <!-- =====================================================
         MAIN CONTENT
    ===================================================== -->
    <main class="main-content">
        <!-- HEADER -->
        <header class="header">
            <div class="header-left">
                <button id="sidebarToggle" class="hamburger-btn" >
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h1>Audit Trail</h1>
            </div>
            <div class="header-right">
                <button class="btn-report-trigger" id="openModalBtn" >
                    <i class="fa-solid fa-file-pdf"></i>
                    Generate Report
                </button>
            </div>
        </header>
        <!-- =================================================
             YEAR / MONTH FILTER
        ================================================= -->
        <div class="audit-filter-card">
            <div class="filter-title">
                <i class="fa-solid fa-filter" style="color:#f28c28; margin-right:6px;" ></i>
                Filter Audit Logs
            </div>
            <form method="GET" action="audit_trail.php" class="filter-form" >
                <!-- YEAR -->
                <div class="filter-group">
                    <label for="year">Year</label>
                    <select name="year" id="year"  >
                      <option value="0">
                            All Years
                        </option>
                        <?php foreach ($availableYears as $year): ?>
                            <option
                                value="<?php echo (int)$year; ?>"
                                <?php
                                echo ($selected_year == $year)
                                    ? 'selected'
                                    : '';
                                ?>
                            >
                                <?php echo e($year); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- MONTH -->
                <div class="filter-group">
                    <label for="month">
                        Month
                    </label>
                    <select
                        name="month"
                        id="month">
                        <option value="0">
                            All Months
                        </option>
                        <?php
                        $months = [
                            1  => 'January',
                            2  => 'February',
                            3  => 'March',
                            4  => 'April',
                            5  => 'May',
                            6  => 'June',
                            7  => 'July',
                            8  => 'August',
                            9  => 'September',
                            10 => 'October',
                            11 => 'November',
                            12 => 'December'
                        ];
                        foreach ($months as $number => $name):
                        ?>
                            <option
                                value="<?php echo $number; ?>"
                                <?php
                                echo ($selected_month == $number)
                                    ? 'selected'
                                    : '';
                                ?>
                            >
                                <?php echo $name; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- FILTER BUTTON -->
                <button
                    type="submit"
                    class="btn-filter">
                    <i class="fa-solid fa-filter"></i>
                    Apply Filter
                </button>
                <!-- RESET -->
                <a href="audit_trail.php" class="btn-reset" >
                    <i class="fa-solid fa-rotate-left"></i>
                    Reset
                </a>
            </form>
        </div>
        <!-- ================================================
             AUDIT TABLE
        ================================================= -->
        <div class="audit-table-card"  id="printable-report" style="margin:20px;"  >
            <!--PRINT HEADER -->
            <div class="print-header-info" style="display:none;" >
                <h2>   AUDIT TRAIL REPORT </h2>
             <p>  Period: <?php echo e($reportPeriod); ?></p>  <hr style="margin:15px 0;">         </div>
            <!-- TABLE TITLE -->
            <h3>
                <i class="fa-solid fa-clock-rotate-left"  style="color:#f28c28; margin-right:10px;" ></i>
                Audit Logs
                <?php if ($selected_year > 0): ?>
                    <span
                        style="
                            font-size:14px;
                            color:#64748b;
                            font-weight:500;
                            margin-left:8px; ">-
                        <?php
                        if ($selected_month > 0) {
                            echo e(
                                date(
                                    'F Y',
                                    mktime(
                                        0,
                                        0,
                                        0,
                                        $selected_month,
                                        1,
                                        $selected_year
                                    )
                                )
                            );
                        } else {
                            echo e($selected_year);
                        }
                        ?>
                    </span>
                <?php endif; ?>
            </h3>
            <!-- TABLE -->
            <table id="auditTable">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th> Worker Name</th>
                        <th>Product</th>
                        <th>Brought (Taken)</th>
                        <th> Sold</th>
                        <th>Returned </th>
                        <th>Amount Collected</th>
                        <th> Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($logs) > 0): ?>
                    <?php
                    /*
                     * This variable remembers the previous
                     * month/year.
                     *
                     * When the month changes,
                     * we create a new month heading.
                     */
                    $currentMonthYear = '';
                    ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $monthYear = date(
                            'F Y',
                            strtotime($log['created_at'])
                        );
                        ?>
                        <!-- =================================
                             MONTH / YEAR HEADER
                        ================================= -->
                        <?php if ($monthYear !== $currentMonthYear): ?>

                            <?php
                            $currentMonthYear = $monthYear;
                            ?>
                            <tr class="month-header">
                                <td colspan="8">
                                    <i class="fa-solid fa-calendar-days"></i>
                                    <?php echo e($monthYear); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <!-- =================================
                             AUDIT RECORD
                        ================================= -->
                        <tr>
                            <td>
                                <?php
                                echo date(
                                    'M d, Y h:i A',
                                    strtotime($log['created_at'])
                                );
                                ?>
                            </td>
                            <td>
                                <strong>
                                    <?php
                                    echo e($log['worker_name']);
                                    ?>
                                </strong>
                            </td>
                            <td>
                                <?php
                                echo e($log['product_name']);
                                ?>
                            </td>
                            <td>
                                <?php
                                echo e($log['qty_taken']);
                                ?>
                            </td>
                            <td class="text-success">
                                <?php
                                echo e($log['qty_sold']);
                                ?>
                            </td>
                            <td class="text-danger">
                                <?php
                                echo e($log['qty_returned']);
                                ?>
                            </td>
                            <td>
                                ₱<?php
                                echo number_format(
                                    (float)$log['received_amount'],
                                    2
                                );
                                ?>
                            </td>
                            <td>
                                <span class="status-badge">
                                    <?php
                                    echo e($log['status']);
                                    ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8 style="text-align:center; padding:30px;" >
                            <i class="fa-solid fa-folder-open" style= "font-size:30px;color:#94a3b8; display:block; margin-bottom:10px; " ></i>
                            No audit logs found for this period.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
<!-- =====================================================
     GENERATE REPORT MODAL
===================================================== -->
<div class="report-modal" id="reportModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3> Generate Audit Trail Report</h3>
            <i  class="fa-solid fa-xmark close-btn"  id="closeModalBtn"></i>
        </div>
        <form method="GET" action="audit_trail.php" >
            <!-- YEAR -->
            <div class="form-group">
                <label>       Year  </label>
                <select    name="year"   id="reportYear"  >
                    <option value="0">  All Years  </option>
                    <?php foreach ($availableYears as $year): ?>
                        <option
                            value="<?php echo (int)$year; ?>"
                            <?php
                            echo ($selected_year == $year)
                                ? 'selected'
                                : '';
                            ?>
                        >
                            <?php echo e($year); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- MONTH -->
            <div class="form-group">
                <label> Month   </label>
                <select  name="month"  id="reportMonth"    >
                    <option value="0"> All Months   </option>
                    <?php foreach ($months as $number => $name): ?>
                        <option
                            value="<?php echo $number; ?>"
                            <?php
                            echo ($selected_month == $number)
                                ? 'selected'
                                : '';
                            ?>
                        >
                            <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- PRINT -->
            <button
                type="submit"
                name="print"
                value="1"
                class="btn-gen"
                style="
                    background:#0f172a;
                    margin-bottom:10px;
                " >
                <i class="fa-solid fa-print"></i>
                Print / Export PDF
            </button>
            <!-- CSV -->
            <button
                type="submit"
                name="export"
                value="csv"
                class="btn-gen btn-excel  >
                <i class="fa-solid fa-file-excel"></i>
                Export Filtered to Excel/CSV
            </button>
        </form>
    </div>
</div>
<!-- =====================================================
     JAVASCRIPT
===================================================== -->
<script>
// =====================================================
// SIDEBAR
// =====================================================
const sidebar = document.querySelector('.sidebar');
const toggleBtn =
    document.getElementById('sidebarToggle');

if (toggleBtn) {
    toggleBtn.addEventListener('click', () => {

        sidebar.classList.toggle('collapsed');
    });
}
// =====================================================
// REPORT MODAL
// =====================================================
const reportModal =
    document.getElementById('reportModal');
const openModalBtn =
    documet.getElementById('openModalBtn');
const closeModalBtn =
    document.getElementById('closeModalBtn');
if (openModalBtn) {
    openModalBtn.addEventListener('click', () => {
        reportModal.classList.add('active');
    });
}
if (closeModalBtn) {
    closeModalBtn.addEventListener('click', () => {
        reportModal.classList.remove('active');
    });
}
window.addEventListener('click', (e) => {
    if (e.target === reportModal) {
        reportModal.classList.remove('active');
    }
});
// =====================================================
// AUTO PRINT
// =====================================================
<?php if (isset($_GET['print']) && $_GET['print'] === '1'): ?>
window.addEventListener('load', () => {
    window.print();
});
<?php endif; ?>
</script>

</body>
</html>