<?php
namespace App;
use PDO;
use Exception;

class ApplianceController {
    private $pdo;
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function index($params) {
        $sql = "SELECT * FROM appliances WHERE 1";
        $bindings = [];

        if (!empty($params['category'])) {
            $sql .= " AND category = :category";
            $bindings[':category'] = $params['category'];
        }
        if (isset($params['min_price'])) {
            $sql .= " AND price >= :min_price";
            $bindings[':min_price'] = (float)$params['min_price'];
        }
        if (isset($params['max_price'])) {
            $sql .= " AND price <= :max_price";
            $bindings[':max_price'] = (float)$params['max_price'];
        }

        $order = " ORDER BY id DESC";
        if (!empty($params['sort'])) {
            switch ($params['sort']) {
                case 'price_asc': $order = " ORDER BY price ASC"; break;
                case 'price_desc': $order = " ORDER BY price DESC"; break;
                case 'created_desc': $order = " ORDER BY created_at DESC"; break;
            }
        }
        $sql .= $order;

        $page = max(1, (int)($params['page'] ?? 1));
        $per_page = max(1, min(100, (int)($params['per_page'] ?? 10)));
        $offset = ($page -1) * $per_page;

        $countSql = "SELECT COUNT(*) as cnt FROM appliances WHERE 1";
        $countBindings = [];
        if (!empty($params['category'])) { $countSql .= " AND category = :category"; $countBindings[':category'] = $params['category']; }
        if (isset($params['min_price'])) { $countSql .= " AND price >= :min_price"; $countBindings[':min_price'] = (float)$params['min_price']; }
        if (isset($params['max_price'])) { $countSql .= " AND price <= :max_price"; $countBindings[':max_price'] = (float)$params['max_price']; }

        $stmt = $this->pdo->prepare($countSql);
        $stmt->execute($countBindings);
        $total = (int)$stmt->fetchColumn();

        $sql .= " LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($bindings as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        Response::json([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total
            ]
        ], 200);
    }

    public function show($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM appliances WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) Response::error('Not found', 404);
        Response::json(['data' => $row], 200);
    }

    public function store($input) {
        $errors = $this->validate($input, true);
        if (!empty($errors)) Response::error('Validation failed', 400, $errors);

        $stmt = $this->pdo->prepare("SELECT id FROM appliances WHERE sku = :sku");
        $stmt->execute([':sku' => $input['sku']]);
        if ($stmt->fetch()) Response::error('SKU already exists', 409);

        $sql = "INSERT INTO appliances (sku, name, brand, category, price, stock, warranty_months, energy_rating)
                VALUES (:sku, :name, :brand, :category, :price, :stock, :warranty_months, :energy_rating)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':sku' => $input['sku'],
            ':name' => $input['name'],
            ':brand' => $input['brand'],
            ':category' => $input['category'],
            ':price' => $input['price'],
            ':stock' => $input['stock'] ?? 0,
            ':warranty_months' => $input['warranty_months'] ?? 12,
            ':energy_rating' => $input['energy_rating'] ?? null
        ]);
        $id = $this->pdo->lastInsertId();
        $this->show($id);
    }

    public function update($id, $input) {
        $stmt = $this->pdo->prepare("SELECT * FROM appliances WHERE id = :id");
        $stmt->execute([':id'=>$id]);
        $existing = $stmt->fetch();
        if (!$existing) Response::error('Not found', 404);

        $errors = $this->validate($input, false);
        if (!empty($errors)) Response::error('Validation failed', 400, $errors);

        if (isset($input['sku']) && $input['sku'] !== $existing['sku']) {
            $stmt = $this->pdo->prepare("SELECT id FROM appliances WHERE sku = :sku AND id != :id");
            $stmt->execute([':sku'=>$input['sku'], ':id'=>$id]);
            if ($stmt->fetch()) Response::error('SKU already exists', 409);
        }

        $fields = [];
        $bindings = [':id'=>$id];
        $allowed = ['sku','name','brand','category','price','stock','warranty_months','energy_rating'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "$f = :$f";
                $bindings[":$f"] = $input[$f];
            }
        }
        if (empty($fields)) Response::error('No fields to update', 400);

        $sql = "UPDATE appliances SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        $this->show($id);
    }

    public function destroy($id) {
        $stmt = $this->pdo->prepare("SELECT id FROM appliances WHERE id = :id");
        $stmt->execute([':id'=>$id]);
        if (!$stmt->fetch()) Response::error('Not found', 404);

        $stmt = $this->pdo->prepare("DELETE FROM appliances WHERE id = :id");
        $stmt->execute([':id'=>$id]);
        Response::json(['message' => 'Deleted'], 200);
    }

    private function validate($input, $isCreate = true) {
        $errors = [];
        if ($isCreate) {
            $required = ['sku','name','brand','category','price'];
            foreach ($required as $r) {
                if (!isset($input[$r]) || $input[$r] === '') $errors[$r] = 'required';
            }
        }
        if (isset($input['price']) && !is_numeric($input['price'])) $errors['price'] = 'must be numeric';
        if (isset($input['price']) && $input['price'] < 0) $errors['price'] = 'must be >= 0';
        if (isset($input['stock']) && (!is_numeric($input['stock']) || (int)$input['stock'] < 0)) $errors['stock'] = 'must be integer >= 0';
        if (isset($input['warranty_months']) && (!is_numeric($input['warranty_months']) || (int)$input['warranty_months'] < 0)) $errors['warranty_months'] = 'must be integer >= 0';
        if (isset($input['energy_rating']) && (!is_numeric($input['energy_rating']) || (int)$input['energy_rating'] < 1 || (int)$input['energy_rating'] > 5)) $errors['energy_rating'] = 'must be 1-5 or null';
        return $errors;
    }
}
?>
