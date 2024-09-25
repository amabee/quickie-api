<?php
date_default_timezone_set('Asia/Manila');
include('connection.php');

class CleanupExpiredPosts
{
    private $conn;

    public function __construct()
    {
        $this->conn = DatabaseConnection::getInstance()->getConnection();
    }

    public function deleteExpiredPosts()
    {
        try {
            $sql = "DELETE FROM posts WHERE expiry_duration < NOW()";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            echo json_encode(array("success" => true, "message" => "Expired posts deleted successfully."));
        } catch (PDOException $e) {
            echo json_encode(array("success" => false, "error" => $e->getMessage()));
        } finally {
            unset($stmt);
        }
    }
}

$cleanup = new CleanupExpiredPosts();
$cleanup->deleteExpiredPosts();
