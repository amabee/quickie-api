<?php
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
include('connection.php');
include('helpers.php');

class User
{
    private $conn;

    public function __construct()
    {
        $this->conn = DatabaseConnection::getInstance()->getConnection();
    }

    public function searchUser($json)
    {
        $data = json_decode($json, true);


        if (!isset($data['search_query'])) {
            return json_encode(["error" => "Search query is required"]);
        }

        $search_query = '%' . sanitizeInput($data['search_query']) . '%';

        try {
            $sql = 'SELECT user_id, first_name, last_name, username, email, profile_image 
                FROM users 
                WHERE username LIKE :search_query OR email LIKE :search_query 
                LIMIT 10';

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':search_query', $search_query, PDO::PARAM_STR);
            $stmt->execute();

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode(["success" => true, "users" => $users]);
        } catch (PDOException $e) {
            return json_encode(["error" => $e->getMessage()]);
        } finally {
            unset($stmt);
        }
    }


}

$user = new User();

try {
    if ($_SERVER["REQUEST_METHOD"] == "GET" || $_SERVER["REQUEST_METHOD"] == "POST") {
        if (isset($_REQUEST["operation"]) && isset($_REQUEST["json"])) {
            $operation = $_REQUEST["operation"];
            $json = $_REQUEST["json"];

            switch ($operation) {
                case "searchUser":
                    echo $user->searchUser($json);
                    break;

                default:
                    echo json_encode(array("error" => "Invalid Operation"));
                    break;
            }
        } else {
            echo json_encode(array("error" => "Missing Parameters"));
        }
    } else {
        echo json_encode(array("error" => "Invalid Request Method"));
    }
} catch (Exception $e) {
    echo json_encode(array("error" => $e->getMessage()));
}
?>