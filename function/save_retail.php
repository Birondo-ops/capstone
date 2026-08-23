<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../auth/conn.php"; 

class RetailController {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function handleSale(array $data, string $adminName): bool {
        try {
            $this->pdo->beginTransaction();

            $productId = isset($data['product_id']) ? (int)$data['product_id'] : 0;
            $qty       = isset($data['qty']) ? (int)$data['qty'] : 0;
            
            // Extract selected date from form or fallback to right now
            $inputDate = !empty($data['order_date']) ? trim($data['order_date']) : date('Y-m-d H:i:s');

            // Check if inputDate contains time (HH:MM:SS)
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $inputDate)) {
                // If only YYYY-MM-DD was provided, attach current real-time clock
                $fullDateTime = $inputDate . ' ' . date('H:i:s');
            } else {
                // If it already has time or uses default, format appropriately
                $fullDateTime = date('Y-m-d H:i:s', strtotime($inputDate));
            }

            // Standard YYYY-MM-DD for retail_orders table (if DATE column)
            $orderDate = date('Y-m-d', strtotime($fullDateTime));

            if ($qty <= 0) {
                throw new Exception("Invalid quantity. Please enter a number greater than 0.");
            }

            $product   = $this->validateStock($productId, $qty);
            $unitPrice = (float)$product['retail_price'];
            $subtotal  = $unitPrice * $qty;

            // 1. Insert into sales table with full date and time
            $this->recordSale(
                'Retail', 
                $product['product_name'], 
                $adminName, 
                $qty, 
                $unitPrice, 
                $subtotal, 
                $fullDateTime
            );

            // 2. Insert into retail_orders table
            $this->recordRetailOrder($productId, $qty, $subtotal, $orderDate);

            // 3. Deduct stock & log
            $this->deductInventory($productId, $qty);
            $this->logTransaction($productId, $qty, $fullDateTime, $adminName);

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e; 
        }
    }

    private function validateStock(int $id, int $qty): array {
        $stmt = $this->pdo->prepare("SELECT product_name, quantity, retail_price FROM products WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception("Product not found.");
        }

        if ((int)$product['quantity'] < $qty) {
            throw new Exception("Insufficient stock for " . $product['product_name']);
        }

        return $product;
    }

    private function recordSale(
        string $type, 
        string $productName, 
        string $worker, 
        int $qty, 
        float $unitPrice, 
        float $subtotal, 
        string $date
    ): void {
        $sql = "INSERT INTO sales (type, product, worker, qty, unit_price, subtotal, created_at) 
                VALUES (:type, :product, :worker, :qty, :unit_price, :subtotal, :created_at)";
        
        $this->pdo->prepare($sql)->execute([
            ':type'       => $type,
            ':product'    => $productName,
            ':worker'     => $worker,
            ':qty'        => $qty,
            ':unit_price' => $unitPrice,
            ':subtotal'   => $subtotal,
            ':created_at' => $date
        ]);
    }

    private function recordRetailOrder(int $pid, int $qty, float $subtotal, string $dateFormatted): void {
        $sql = "INSERT INTO retail_orders (product_id, qty, subtotal, order_date) 
                VALUES (:pid, :qty, :subtotal, :order_date)";
        
        $this->pdo->prepare($sql)->execute([
            ':pid'        => $pid,
            ':qty'        => $qty,
            ':subtotal'   => $subtotal,
            ':order_date' => $dateFormatted
        ]);
    }

    private function deductInventory(int $pid, int $qty): void {
        $sql = "UPDATE products SET quantity = quantity - :qty WHERE id = :pid";
        $this->pdo->prepare($sql)->execute([':qty' => $qty, ':pid' => $pid]);
    }

    private function logTransaction(int $pid, int $qty, string $date, string $admin): void {
        $sql = "INSERT INTO inventory_logs (product_id, quantity_change, action, notes, admin_name) 
                VALUES (:pid, :qty, 'Removed', :notes, :admin)";
        $this->pdo->prepare($sql)->execute([
            ':pid'   => $pid,
            ':qty'   => $qty,
            ':notes' => "Retail Sale - Date: $date", 
            ':admin' => $admin
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_retail'])) {
    $retailManager = new RetailController($pdo);
    $admin = $_SESSION['admin_name'] ?? 'System';

    try {
        $retailManager->handleSale($_POST, $admin);
        header("Location: ../retailer.php?status=success");
        exit();
    } catch (Exception $e) {
        header("Location: ../retailer.php?status=error&msg=" . urlencode($e->getMessage()));
        exit();
    }
}