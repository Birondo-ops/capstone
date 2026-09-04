<?php
session_start();
require_once "auth/conn.php";
require_once "function/dispatchController.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: auth/login.php");
    exit();
}

$dispatchManager = new DispatchController($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['submit_dispatch'])) {
            $dispatchManager->createBulkDispatch($_POST, $_SESSION['admin_name'] ?? 'Admin');
            header("Location: dispatchers.php?success=1"); 
            exit();
        }
        if (isset($_POST['add_single_item'])) {
            $dispatchManager->addSingleItem($_POST, $_SESSION['admin_name'] ?? 'Admin');
            header("Location: dispatchers.php?success=added"); 
            exit();
        }
    } catch (Exception $e) {
        header("Location: dispatchers.php?error=" . urlencode($e->getMessage())); 
        exit();
    }
}

$grouped_data = $dispatchManager->getActiveDispatches();
$all_products = $pdo->query("SELECT id, product_name, wholesale_price, quantity FROM products ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel - Dispatchers</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dispatcher.css">
    <style>
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: #ffffff;
            padding: 24px;
            border-radius: 12px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }

        .alert-box {
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: 500;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #f87171;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #4ade80;
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
            <a href="dispatchers.php" class="nav-item active" data-title="Dispatchers">
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
                <h1 style="white-space: nowrap; margin-right: 20px;">Dispatch Overview</h1>
            </div>
        </header>

        <section class="content-area" style="padding: 20px;">

            <?php if (isset($_GET['error'])): ?>
                <div class="alert-box alert-error">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['msg']) || isset($_GET['success'])): ?>
                <div class="alert-box alert-success">
                    <i class="fa-solid fa-circle-check"></i> 
                    <?php 
                        if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
                            echo "Item removed and stock restored successfully!";
                        } elseif (isset($_GET['success']) && $_GET['success'] === 'added') {
                            echo "Product added successfully!";
                        } else {
                            echo "Dispatch session recorded successfully!";
                        }
                    ?>
                </div>
            <?php endif; ?>

            <button class="btn-open" onclick="toggleModal('dispatchModal', true)" style="background: var(--primary, #2563eb); color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; margin-bottom: 20px;">
                <i class="fa-solid fa-plus"></i> Record New Dispatch
            </button>

            <div class="search-container" style="margin-bottom: 20px;">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="inventorySearch" placeholder="Search worker name...">
            </div>

            <?php foreach($grouped_data as $sid => $data): ?>
            <div class="worker-group" data-worker="<?php echo htmlspecialchars(strtolower($data['info']['name'])); ?>" style="margin-bottom: 25px;">
                <div class="worker-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <div>
                        <span style="color: var(--text); font-weight: 700;">Worker: <?php echo htmlspecialchars($data['info']['name']); ?></span>
                        <span class="status-badge" style="margin-left:10px; background: #dcfce7; color: #15803d; padding: 3px 8px; border-radius: 12px; font-size: 12px;">Active</span>
                        <span style="margin-left: 15px; color: #64748b; font-size: 13px;">
                            <i class="fa-regular fa-clock"></i> 
                            <?php echo date('M d, Y h:i A', strtotime($data['info']['date_today'])); ?>
                        </span>
                    </div>
                    <div>
                        <button type="button" 
                                onclick="openAddProductModal(<?php echo $sid; ?>, '<?php echo base64_encode(json_encode(array_column($data['items'], 'product_name'))); ?>')" 
                                class="action-pill" 
                                style="background: #22c55e; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer;">
                            + PRODUCT
                        </button>
                        <a href="function/afternoon_remit.php?id=<?php echo $sid; ?>" class="action-pill" style="background: var(--primary, #2563eb); color:white; padding: 6px 12px; text-decoration: none; border-radius: 6px;">REMIT</a>
                    </div>
                </div>

                <table class="table-responsive-wrapper" style="width: 100%; border-collapse: collapse;">
                    <thead class="main-table">
                        <tr>
                            <th style="text-align: left; padding: 8px;">Product Name</th>
                            <th style="text-align: left; padding: 8px;">Price</th>
                            <th style="text-align: left; padding: 8px;">Qty</th>
                            <th style="text-align: left; padding: 8px;">Subtotal</th>
                            <th style="text-align: left; padding: 8px;">Stock Left</th>
                            <th style="text-align: center; padding: 8px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_total = 0;
                        foreach($data['items'] as $item): 
                            $subtotal = $item['price_at_time'] * $item['qty_taken'];
                            $grand_total += $subtotal;
                            $delete_id = $item['id'] ?? $item['di_id'];
                        ?>
                        <tr>
                            <td style="padding: 8px;"><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td style="padding: 8px;">₱<?php echo number_format($item['price_at_time'], 2); ?></td>
                            <td style="padding: 8px;"><?php echo $item['qty_taken']; ?></td>
                            <td style="padding: 8px;">₱<?php echo number_format($subtotal, 2); ?></td>
                            <td style="padding: 8px; color:#94a3b8;"><?php echo $item['inventory_qty']; ?></td>
                            <td style="text-align: center; padding: 8px;">
                                <a href="function/edit_despatch.php?id=<?php echo $delete_id; ?>" class="fa-solid fa-pencil" style="color:#666; margin-right:10px; cursor:pointer; text-decoration:none;"></a>
                                <a href="function/delete_item.php?id=<?php echo $delete_id; ?>" onclick="return confirm('Remove product?')" style="color: #ef4444;">
                                    <i class="fa-solid fa-trash-can"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row" style="font-weight: bold; background: #f8fafc;">
                            <td colspan="3" style="text-align: right; padding: 10px;">Total Amount Accountable:</td>
                            <td colspan="3" style="padding: 10px;">₱<?php echo number_format($grand_total, 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </section>
    </main>
</div>

<!-- Morning Pickup Modal -->
<div id="dispatchModal" class="modal-overlay">
    <div class="modal-content">
        <form method="POST">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; background:#f97316; color:white; padding:15px; border-radius:8px 8px 0 0;">
                <h2 style="margin:0; font-size:20px;">Morning Pickup</h2>
                <span onclick="toggleModal('dispatchModal', false)" style="cursor:pointer; font-size:24px;">&times;</span>
            </div>
            <div class="form-body" style="padding:15px 0;">
                <label style="margin-bottom: 5px; display:block; font-weight:600;">Worker Name:</label>
                <input type="text" name="worker_name" required style="width:100%; padding:10px; margin-bottom:15px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box;">
                
                <label style="margin-bottom: 5px; display:block; font-weight:600;">Dispatch Date & Time:</label>
                <input type="datetime-local" name="date_today" value="<?php echo date('Y-m-d\TH:i'); ?>" required style="width:100%; padding:10px; margin-bottom:15px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box;">
                
                <div style="display:flex; width:100%; justify-content: space-between; align-items:center; margin-bottom:10px;">
                    <label style="margin: 0; font-weight:600;">Products:</label>
                    <button type="button" onclick="addNewRow()" style="background:#e0e7ff; color:#3730a3; border:none; padding:6px 12px; border-radius:6px; font-weight:bold; cursor:pointer;">+ Add Row</button>
                </div>

                <table style="width:100%; border-collapse: collapse;" id="morningTable">
                    <tbody id="morningRows">
                        <tr>
                            <td style="padding: 4px 0;">
                                <select name="product_ids[]" required style="border: 1px solid #ddd; border-radius: 8px; width:98%; padding:8px;" onchange="updateDropdowns()">
                                    <option value="">-- Select Product --</option>
                                    <?php foreach($all_products as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['product_name']); ?> (₱<?php echo $p['wholesale_price']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="width:70px; padding: 4px 0;">
                                <input type="number" name="qtys[]" value="1" min="1" style="border: 1px solid #ddd; border-radius: 8px; width:100%; padding:8px; box-sizing: border-box;">
                            </td>
                            <td style="width:30px; text-align:right; padding: 4px 0;">
                                <i class="fa-solid fa-trash-can remove-icon" style="cursor:pointer; color:#ef4444;" onclick="removeRow(this)"></i>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <button type="submit" name="submit_dispatch" style="margin-top: 20px; width: 100%; padding:12px; background:#f97316; color:white; border:none; border-radius:8px; font-weight:bold; font-size:16px; cursor:pointer;">Submit Dispatch</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Product Modal -->
<div id="addProductModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 400px;">
        <form method="POST">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:10px;">
                <h2 style="margin:0; font-size:18px;">Add Product</h2>
                <span onclick="toggleModal('addProductModal', false)" style="cursor:pointer; font-size:20px;">&times;</span>
            </div>
            <div class="form-body" style="padding-top:15px;">
                <input type="hidden" name="session_id" id="modal_session_id">
                <label style="font-weight:600;">Select New Product:</label>
                <select name="new_product_id" id="filteredSelect" required style="width:100%; padding:10px; margin-top:5px; margin-bottom:15px; border:1px solid #ddd; border-radius:6px;">
                    <option value="">-- Choose Product --</option>
                    <?php foreach($all_products as $p): ?>
                        <option value="<?php echo $p['id']; ?>" data-pname="<?php echo htmlspecialchars($p['product_name']); ?>">
                            <?php echo htmlspecialchars($p['product_name']); ?> (₱<?php echo $p['wholesale_price']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <label style="display:block; font-weight:600;">Quantity:</label>
                <input type="number" name="new_qty" value="1" min="1" required style="width:100%; padding:10px; margin-top:5px; margin-bottom:20px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box;">
                <button type="submit" name="add_single_item" style="width:100%; padding:12px; background:#22c55e; color:white; border:none; border-radius:8px; font-weight:bold; cursor:pointer;">Add to List</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleModal(modalId, show) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = show ? 'flex' : 'none';
    }
}

function openAddProductModal(sessionId, encodedExistingProducts) {
    const inputSession = document.getElementById('modal_session_id');
    if (inputSession) {
        inputSession.value = sessionId;
    }

    let existingProducts = [];
    try {
        if (encodedExistingProducts) {
            existingProducts = JSON.parse(atob(encodedExistingProducts));
        }
    } catch(e) {
        console.error("Error parsing existing products array:", e);
    }

    const select = document.getElementById('filteredSelect');
    if (select) {
        Array.from(select.options).forEach(option => {
            const pname = option.getAttribute('data-pname');
            if (pname && existingProducts.includes(pname)) {
                option.style.display = 'none';
            } else {
                option.style.display = 'block';
            }
        });
        select.value = ''; 
    }

    toggleModal('addProductModal', true);
}

function updateDropdowns() {
    const selects = document.querySelectorAll('#morningRows select[name="product_ids[]"]');
    const selectedValues = Array.from(selects)
        .map(s => s.value)
        .filter(val => val !== "");

    selects.forEach(select => {
        const currentValue = select.value;
        Array.from(select.options).forEach(option => {
            if (option.value === "") return;

            if (selectedValues.includes(option.value) && option.value !== currentValue) {
                option.style.display = 'none';
            } else {
                option.style.display = 'block';
            }
        });
    });
}

function addNewRow() {
    const tbody = document.getElementById('morningRows');
    const firstRow = tbody.querySelector('tr');
    if (!firstRow) return;

    const newRow = firstRow.cloneNode(true);
    
    const numInput = newRow.querySelector('input[type="number"]');
    if (numInput) numInput.value = 1;

    const newSelect = newRow.querySelector('select');
    if (newSelect) {
        newSelect.value = "";
        newSelect.addEventListener('change', updateDropdowns);
    }

    tbody.appendChild(newRow);
    updateDropdowns();
}

function removeRow(btn) {
    const tbody = document.getElementById('morningRows');
    if (tbody.querySelectorAll('tr').length > 1) {
        btn.closest('tr').remove();
        updateDropdowns();
    } else {
        alert("At least one item row must remain.");
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const sidebarBtn = document.getElementById('sidebarToggle');
    if (sidebarBtn) {
        sidebarBtn.addEventListener('click', () => {
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.classList.toggle('active');
                sidebar.classList.toggle('collapsed');
            }
        });
    }

    const invSearch = document.getElementById('inventorySearch');
    if (invSearch) {
        invSearch.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase().trim();
            document.querySelectorAll('.worker-group').forEach(group => {
                const workerName = group.getAttribute('data-worker') || "";
                group.style.display = workerName.includes(searchTerm) ? "" : "none";
            });
        });
    }

    const alerts = document.querySelectorAll('.alert-box');
    if (alerts.length > 0) {
        setTimeout(() => {
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 4000);
    }
});
</script>
</body>
</html>