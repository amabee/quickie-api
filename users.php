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

        if (!isset($data['search_query']) || strlen(trim($data['search_query'])) < 2) {
            return json_encode(["error" => "Search query is required and must be at least 2 characters long"]);
        }

        $search_query = sanitizeInput($data['search_query']);
        $search_terms = explode(' ', $search_query);
        $current_user_id = isset($data['current_user_id']) ? intval($data['current_user_id']) : null;

        try {
            $sql = 'SELECT u.user_id, u.first_name, u.last_name, u.username, u.email, u.profile_image,
                    (CASE
                        WHEN u.username = :exact_match THEN 100
                        WHEN u.email = :exact_match THEN 90
                        WHEN u.username LIKE :starts_with THEN 80
                        WHEN u.email LIKE :starts_with THEN 70
                        WHEN CONCAT(u.first_name, " ", u.last_name) LIKE :full_name THEN 60
                        ELSE (
                            (CASE WHEN u.username LIKE :contains THEN 20 ELSE 0 END) +
                            (CASE WHEN u.email LIKE :contains THEN 15 ELSE 0 END) +
                            (CASE WHEN u.first_name LIKE :contains THEN 10 ELSE 0 END) +
                            (CASE WHEN u.last_name LIKE :contains THEN 10 ELSE 0 END)
                        )
                    END) AS relevance,
                    CASE WHEN f.follower_id IS NOT NULL THEN 1 ELSE 0 END AS is_following
                FROM users u
                LEFT JOIN follows f ON u.user_id = f.following_id AND f.follower_id = :current_user_id
                WHERE 
                    u.username LIKE :contains OR 
                    u.email LIKE :contains OR 
                    u.first_name LIKE :contains OR 
                    u.last_name LIKE :contains OR
                    CONCAT(u.first_name, " ", u.last_name) LIKE :full_name
                HAVING relevance > 0
                ORDER BY relevance DESC
                LIMIT 10';

            $stmt = $this->conn->prepare($sql);

            $exact_match = $search_query;
            $starts_with = $search_query . '%';
            $contains = '%' . $search_query . '%';
            $full_name = '%' . implode('%', $search_terms) . '%';

            $stmt->bindParam(':exact_match', $exact_match, PDO::PARAM_STR);
            $stmt->bindParam(':starts_with', $starts_with, PDO::PARAM_STR);
            $stmt->bindParam(':contains', $contains, PDO::PARAM_STR);
            $stmt->bindParam(':full_name', $full_name, PDO::PARAM_STR);
            $stmt->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);

            $stmt->execute();

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode(["success" => $users]);
        } catch (PDOException $e) {
            return json_encode(["error" => $e->getMessage()]);
        } finally {
            unset($stmt);
        }
    }

    public function suggestUsersToFollow($json)
    {
        $data = json_decode($json, true);

        if (!isset($data['user_id'])) {
            return json_encode(["error" => "User ID is required"]);
        }

        $user_id = sanitizeInput($data['user_id']);

        try {
            $sql = "
            SELECT u.user_id, u.first_name, u.last_name, u.username, u.email, u.profile_image
            FROM users u
            LEFT JOIN follows f ON f.following_id = u.user_id AND f.follower_id = :user_id
            WHERE u.user_id != :user_id
            AND f.following_id IS NULL
            LIMIT 10;
        ";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();

            $suggested_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode(["success" => $suggested_users]);
        } catch (PDOException $e) {
            return json_encode(["error" => $e->getMessage()]);
        } finally {
            unset($stmt);
        }
    }

    public function followUser($json)
    {
        $data = json_decode($json, true);

        if (!isset($data['follower_id']) || !isset($data['following_id'])) {
            return json_encode(["error" => "Follower ID and Following ID are required"]);
        }

        $follower_id = intval($data['follower_id']);
        $following_id = intval($data['following_id']);

        if ($follower_id === $following_id) {
            return json_encode(["error" => "You cannot follow yourself"]);
        }

        try {

            // Insert into follows table
            $sql = "INSERT INTO follows (follower_id, following_id, follow_timestamp) 
                VALUES (:follower_id, :following_id, :follow_timestamp)";
            $stmt = $this->conn->prepare($sql);
            $follow_timestamp = date('Y-m-d H:i:s');
            $stmt->bindParam(':follower_id', $follower_id, PDO::PARAM_INT);
            $stmt->bindParam(':following_id', $following_id, PDO::PARAM_INT);
            $stmt->bindParam(':follow_timestamp', $follow_timestamp);

            if ($stmt->execute()) {
                return json_encode(["success" => "Successfully followed user"]);
            } else {
                return json_encode(["error" => "Failed to follow user"]);
            }
        } catch (PDOException $e) {
            return json_encode(["error" => $e->getMessage()]);
        } finally {
            unset($stmt);
        }
    }

    public function unfollowUser($json)
    {
        $data = json_decode($json, true);

        if (!isset($data['follower_id']) || !isset($data['following_id'])) {
            return json_encode(["error" => "Follower ID and Following ID are required"]);
        }

        $follower_id = intval($data['follower_id']);
        $following_id = intval($data['following_id']);

        if ($follower_id === $following_id) {
            return json_encode(["error" => "You cannot unfollow yourself"]);
        }

        try {
            // Delete from follows table
            $sql = "DELETE FROM follows WHERE follower_id = :follower_id AND following_id = :following_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':follower_id', $follower_id, PDO::PARAM_INT);
            $stmt->bindParam(':following_id', $following_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                return json_encode(["success" => "Successfully unfollowed user"]);
            } else {
                return json_encode(["error" => "Failed to unfollow user"]);
            }
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

                case "suggestUsersToFollow":
                    echo $user->suggestUsersToFollow($json);
                    break;

                case "followUser":
                    echo $user->followUser($json);
                    break;

                case "unfollowUser":
                    echo $user->unfollowUser($json);
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