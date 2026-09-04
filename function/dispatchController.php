<?php
class DispatchController {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getActiveDispatches(): array {
        $stmt = $this->pdo->query(
            "SELECT ds.id AS session_id, ds.worker_name, ds.date_today, ds.status,
                    di.id AS di_id, di.product_id, di.qty_taken, di.price_at_time, 
                    p.product_name, p.quantity AS inventory_qty
                FROM dispatch_sessions ds
                JOIN dispatch_items di ON di.session_id = ds.id
                JOIN products p ON p.id = di.product_id
                WHERE ds.status = 'Active'
                ORDER BY ds.date_today DESC, ds.id, di.id"
        );
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($items as $row) {
            $sid = $row['session_id'];
            if (!isset($grouped[$sid])) {
                $grouped[$sid] = [
                    'info' => [
                        'name' => $row['worker_name'], 
                        'date_today' => $row['date_today'], 
                        'status' => $row['status']
                    ],
                    'items' => []
                ];
            }
            $grouped[$sid]['items'][] = $row;
        }
        return $grouped;
    }

    public function createBulkDispatch(array $data, string $admin): bool {
        if (empty($data['worker_name']) || empty($data['product_ids'])) {
            throw new Exception("Please complete all required worker and product fields.");
        }

        // Format user-selected datetime for MySQL (YYYY-MM-DD HH:MM:SS)
        $dispatchDatetime = !empty($data['date_today']) 
            ? date('Y-m-d H:i:s', strtotime($data['date_today'])) 
            : date('Y-m-d H:i:s');

        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("INSERT INTO dispatch_sessions (worker_name, date_today, status) VALUES (?, ?, 'Active')");
            $stmt->execute([trim($data['worker_name']), $dispatchDatetime]);
            $sid = (int)$this->pdo->lastInsertId();

            $worker = trim($data['worker_name']);

            foreach ($data['product_ids'] as $idx => $pid) {
                if (empty($pid)) continue;

                $qty = (int)($data['qtys'][$idx] ?? 0);
                $pid = (int)$pid;

                if ($qty > 0) {
                    $this->processItem($sid, $pid, $qty, $worker, $admin);
                }
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) { 
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack(); 
            }
            throw $e; 
        }
    }

    public function addSingleItem(array $data, string $admin): bool {
        if (empty($data['session_id']) || empty($data['new_product_id']) || empty($data['new_qty'])) {
            throw new Exception("Invalid parameters for adding item.");
        }

        try {
            $this->pdo->beginTransaction();

            $sStmt = $this->pdo->prepare("SELECT worker_name FROM dispatch_sessions WHERE id = ?");
            $sStmt->execute([(int)$data['session_id']]);
            $worker = $sStmt->fetchColumn();

            if (!$worker) {
                throw new Exception("Dispatch session not found.");
            }

            $sessionId = (int)$data['session_id'];
            $newPid = (int)$data['new_product_id'];
            $qty = (int)$data['new_qty'];

            $this->processItem($sessionId, $newPid, $qty, $worker, $admin);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) { 
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack(); 
            }
            throw $e;
        }
    }

    private function processItem(int $sid, int $pid, int $qty, string $worker, string $admin): void {
        // Fetch product details
        $p = $this->pdo->prepare("SELECT product_name, quantity, wholesale_price FROM products WHERE id = ?");
        $p->execute([$pid]);
        $prod = $p->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            throw new Exception("Product ID {$pid} not found.");
        }

        if ($qty > $prod['quantity']) {
            throw new Exception("Insufficient stock for '{$prod['product_name']}'. Available: {$prod['quantity']}");
        }

        // Check if product already exists in this dispatch session
        $check = $this->pdo->prepare("SELECT id, qty_taken FROM dispatch_items WHERE session_id = ? AND product_id = ?");
        $check->execute([$sid, $pid]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update existing row quantity
            $updateItem = $this->pdo->prepare("UPDATE dispatch_items SET qty_taken = qty_taken + ? WHERE id = ?");
            $updateItem->execute([$qty, $existing['id']]);
        } else {
            // Insert new item row
            $insertItem = $this->pdo->prepare("INSERT INTO dispatch_items (session_id, product_id, qty_taken, price_at_time) VALUES (?, ?, ?, ?)");
            $insertItem->execute([$sid, $pid, $qty, $prod['wholesale_price']]);
        }

        // Deduct inventory stock
        $deductStock = $this->pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
        $deductStock->execute([$qty, $pid]);

        // Insert log
        $log = $this->pdo->prepare("INSERT INTO inventory_logs (product_id, quantity_change, action, notes, admin_name) VALUES (?, ?, 'Removed', ?, ?)");
        $log->execute([$pid, $qty, "From dispatch session #{$sid} ({$worker})", $admin]);
    }
}