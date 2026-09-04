<?php
// Enable temporary error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: auth/login.php");
    exit();
}

/** @param mixed $value */
function e($value): string { 
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); 
}

$products = [];

// API Requirement Execution
$apiFile = __DIR__ . '/function/get_product_api.php';

if (file_exists($apiFile)) {
    ob_start();
    include $apiFile;
    $apiOutput = ob_get_clean();

    // Reset headers so browser displays HTML properly
    header_remove("Content-Type");
    header("Content-Type: text/html; charset=UTF-8");

    $json = json_decode($apiOutput, true);
    if (isset($json['status']) && $json['status'] === 'success' && !empty($json['data'])) {
        $products = $json['data'];
    }
} else {
    echo "API file not found at: " . $apiFile;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Inventory</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/inventory_admins.css">
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
            <a href="inventory.php" class="nav-item active" data-title="Inventory">
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
                    <h1 style="white-space: nowrap; margin-right: 20px;">Inventory Overview</h1>
                </div>
            </header>

            <section class="inventory-container">
                <div class="catalog-control-row">
                    <div class="catalog-left-group">
                        <h2 style="color: #2c3e50; margin: 0; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-layer-group"></i> Current Catalog
                        </h2>
                        <button class="refresh-btn" onclick="openForm()">
                            <i class="fa-solid fa-plus"></i> Add New Product
                        </button>
                    </div>

                    <div class="search-container">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="inventorySearch" placeholder="Search product...">
                    </div>
                </div>

                <div id="inventory-grid" class="inventory-grid">
                    <?php if (!empty($products)): ?>
                        <?php foreach ($products as $product): 
                            $max = intval($product['max_quantity'] ?? 100);
                            $current = intval($product['quantity'] ?? 0);
                            $max = $max > 0 ? $max : 100;
                            $percent = min(($current / $max) * 100, 100);
                            $healthColor = $percent <= 15 ? '#e74c3c' : '#2ecc71';

                            $wholesale = floatval($product['wholesale_price'] ?? 0);
                            $retail = floatval($product['retail_price'] ?? 0);
                            $marginVal = $retail - $wholesale;
                            $margin = number_format($marginVal, 2);

                            $isLoss = $marginVal < 0;
                            $profitColor = $isLoss ? '#e74c3c' : '#3498db';
                            $profitLabel = $isLoss ? 'LOSS/Unit:' : 'Profit/Unit:';

                            $alertBadge = $isLoss 
                                ? '<div style="background: #fff5f5; color: #e74c3c; border: 1px solid #feb2b2; padding: 4px; border-radius: 4px; font-size: 0.7rem; margin-bottom: 10px; text-align: center; font-weight: bold;">
                                    <i class="fa-solid fa-triangle-exclamation"></i> PRICING ERROR
                                   </div>' 
                                : '';
                            
                            // FIX: Relative path without leading slash
                            $fileName = (!empty($product['image_path']) && $product['image_path'] !== 'default-product.png') 
                                ? $product['image_path'] 
                                : 'default-product.png';
                            $imageSrc = 'uploads/' . e($fileName);
                            $safeName = e($product['product_name']);
                        ?>
                            <div class="product-card" data-category="<?= e($product['category'] ?? ''); ?>">
                                <div class="card-actions">
                                    <a href="function/edit_product.php?id=<?= $product['id']; ?>" class="action-btn" title="Edit">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <div class="action-btn" onclick="confirmDelete(<?= $product['id']; ?>)" style="color: #e74c3c; cursor: pointer;" title="Delete">
                                        <i class="fa-solid fa-trash"></i>
                                    </div>
                                </div>
                                
                                <div class="card-image-wrapper">
                                    <img src="<?php echo $imageSrc; ?>" 
                                         alt="<?php echo e($product['product_name']); ?>" 
                                         class="product-image"
                                         onerror="this.onerror=null; this.src='uploads/default-product.png';" />
                                </div>
                                
                                <div class="card-info">
                                    <div class="card-title"><?= e($product['product_name']); ?></div>
                                    <div class="card-variation"><?= e($product['variation'] ?? 'Standard'); ?></div>
                                    
                                    <?= $alertBadge; ?>

                                    <div class="card-description" style="font-size: 0.8rem; color: #5f6769; line-height: 1.4; margin-bottom: 15px;">
                                        <?= e($product['description'] ?? 'No description available.'); ?>
                                    </div>

                                    <div class="price-details-box" style="<?= $isLoss ? 'border-color: #e74c3c; background: #fffcfc;' : ''; ?>">
                                        <div class="price-line">
                                            <span>Wholesale:</span>
                                            <span style="font-weight: 500;">₱<?= number_format($wholesale, 2); ?></span>
                                        </div>
                                        <div class="price-line">
                                            <span>Retail:</span>
                                            <span style="font-weight: 600; color: #2ecc71;">₱<?= number_format($retail, 2); ?></span>
                                        </div>
                                        <div class="price-line profit-border">
                                            <span style="color: #7f8c8d;"><?= $profitLabel; ?></span>
                                            <span style="font-weight: bold; color: <?= $profitColor; ?>;">₱<?= $margin; ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="progress-bar-bg" style="width: 100%; height: 6px; background: #eee; border-radius: 10px; overflow: hidden; margin-top:10px;">
                                        <div class="progress-fill" style="width:<?= $percent; ?>%; background:<?= $healthColor; ?>; height: 100%; transition: width 0.5s ease;"></div>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; margin-top: 5px; margin-bottom: 10px;">
                                        <small style="color: #7f8c8d;">Stock Level</small>
                                        <small style="font-weight: bold; color: <?= $healthColor; ?>;"><?= $current; ?> / <?= $max; ?></small>
                                    </div>
                                    
                                    <div class="card-footer" style="text-align: center; border-top: 1px solid #eee; padding-top: 10px; font-weight: bold;">
                                        <?= $current; ?> units available
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1/-1; text-align: center; padding: 50px; color: #7f8c8d;">No products found.</div>
                    <?php endif; ?>
                </div>

                <div id="popupForm" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <span class="close" onclick="closeForm()">&times;</span>
                            <h2 style="margin:0; color:white;">Register New Stock</h2>
                        </div>
                        <div class="modal-body">
                            <form action="function/insert_into.php" method="POST" enctype="multipart/form-data">
                                <label>Product Photo</label>
                                <div class="file-input-wrapper">
                                    <input type="file" name="product_image" id="imgInput" accept="image/*" required onchange="previewImage(this)">
                                    <p style="font-size: 0.7rem; color: #7f8c8d;">Click to upload (PNG, JPG)</p>
                                    <center><img id="imagePreview" src=""></center>
                                </div>

                                <label>Category</label>
                                <select name="category" required>
                                    <option value="">-- Select Category --</option>
                                    <option value="Brooms">Brooms (Silhig)</option>
                                    <option value="Dustpan">Dustpan</option>
                                    <option value="Brushes">Brushes</option>
                                    <option value="Bucket">Bucket (Balde)</option>
                                    <option value="Tub">Tub (Labador)</option>
                                    <option value="Doormats">Doormats</option>
                                    <option value="Mops">Mops</option>
                                    <option value="Trash Can">Trash Can</option>
                                </select>

                                <label>Product Name</label>
                                <input type="text" name="product_name" placeholder="e.g. Walis Tambo Ordinary" required>

                                <label>Description</label>
                                <input type="text" name="description" placeholder="e.g. High-quality broom with durable handle" required>

                                <label>Variation</label>
                                <input type="text" name="variation" placeholder="e.g. Wooden Handle">

                                <div style="display: flex; gap: 10px;">
                                    <div style="flex:1;">
                                        <label>Wholesale Price (₱)</label>
                                        <input type="number" name="wholesale_price" step="0.01" required>
                                    </div>
                                    <div style="flex:1;">
                                        <label>Retail Price (₱)</label>
                                        <input type="number" name="retail_price" step="0.01" required>
                                    </div>
                                    <div style="flex:1;">
                                        <label>Initial Qty</label>
                                        <input type="number" name="quantity" required>
                                    </div>
                                </div>
                                <label>Max Capacity</label>
                                <input type="number" name="max_quantity" value="100" required>

                                <button type="submit" class="btn-submit">Confirm & Save Product</button>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script>
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function openForm() { document.getElementById("popupForm").style.display = "flex"; }
        function closeForm() { 
            document.getElementById("popupForm").style.display = "none";
            document.getElementById("imagePreview").style.display = "none";
        }
        
        window.onclick = function(event) {
            if (event.target == document.getElementById("popupForm")) closeForm();
        }

        const sidebar = document.querySelector('.sidebar');
        document.getElementById('sidebarToggle').addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
        });

        function confirmDelete(id) {
            if (!confirm("Are you sure? This will permanently delete the product and its history.")) {
                return;
            }
            fetch(`function/delete_product.php?id=${id}`)
                .then(async response => {
                    const isJson = response.headers.get('content-type')?.includes('application/json');
                    const data = isJson ? await response.json() : null;

                    if (!response.ok) {
                        throw new Error(data?.message || `Server error: ${response.status}`);
                    }
                    return data;
                })
                .then(result => {
                    if (result.status === 'success') {
                        alert("Product removed successfully!");
                        location.reload();
                    } else {
                        alert("Error: " + result.message);
                    }
                })
                .catch(error => {
                    console.error('Delete Error:', error);
                    alert("Could not delete item: " + error.message);
                });
        }

        const searchInput = document.getElementById('inventorySearch');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                const cards = document.querySelectorAll('.product-card');

                cards.forEach(card => {
                    const productName = card.querySelector('.card-title')?.textContent.toLowerCase() || "";
                    const category = card.getAttribute('data-category')?.toLowerCase() || "";
                    
                    if (productName.includes(searchTerm) || category.includes(searchTerm)) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }
    </script>
</body>
</html>