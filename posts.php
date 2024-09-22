<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
include('connection.php');

class Posts
{
    private $conn;

    public function __construct()
    {
        $this->conn = DatabaseConnection::getInstance()->getConnection();
    }

    // Create a new post
    public function createPost($json)
    {
        $json = json_decode($json, true);
        $stmt = null; // Initialize $stmt

        if (isset($json['user_id']) && isset($json['content']) && isset($json['expiry_duration'])) {
            $user_id = $json['user_id'];
            $content = $json['content'];
            $expiry_duration = $json['expiry_duration'];
            $timestamp = date('Y-m-d H:i:s');

            $sql = 'INSERT INTO `posts` (`user_id`, `content`, `timestamp`, `expiry_duration`) 
                    VALUES (:user_id, :content, :timestamp, :expiry_duration)';
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':content', $content, PDO::PARAM_STR);
            $stmt->bindParam(':timestamp', $timestamp, PDO::PARAM_STR);
            $stmt->bindParam(':expiry_duration', $expiry_duration, PDO::PARAM_STR);

            if ($stmt->execute()) {
                $result = json_encode(array("success" => "Post created successfully."));
            } else {
                $result = json_encode(array("error" => "Failed to create post."));
            }
        } else {
            $result = json_encode(array("error" => "Required fields are missing."));
        }

        unset($stmt); // Unset the statement
        return $result;
    }

    // Read all posts
    public function readPosts()
    {
        $sql = 'SELECT `post_id`, `user_id`, `content`, `timestamp`, `expiry_duration` FROM `posts` WHERE 1';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();

        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        unset($stmt); // Unset the statement
        return json_encode($posts);
    }

    // Update a post
    public function updatePost($json)
    {
        $json = json_decode($json, true);
        $stmt = null; // Initialize $stmt

        if (isset($json['post_id']) && isset($json['content']) && isset($json['expiry_duration'])) {
            $post_id = $json['post_id'];
            $content = $json['content'];
            $expiry_duration = $json['expiry_duration'];

            $sql = 'UPDATE `posts` SET `content` = :content, `expiry_duration` = :expiry_duration 
                    WHERE `post_id` = :post_id';
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
            $stmt->bindParam(':content', $content, PDO::PARAM_STR);
            $stmt->bindParam(':expiry_duration', $expiry_duration, PDO::PARAM_STR);

            if ($stmt->execute()) {
                $result = json_encode(array("success" => "Post updated successfully."));
            } else {
                $result = json_encode(array("error" => "Failed to update post."));
            }
        } else {
            $result = json_encode(array("error" => "Required fields are missing."));
        }

        unset($stmt); // Unset the statement
        return $result;
    }

    // Delete a post
    public function deletePost($json)
    {
        $json = json_decode($json, true);
        $stmt = null; // Initialize $stmt

        if (isset($json['post_id'])) {
            $post_id = $json['post_id'];

            $sql = 'DELETE FROM `posts` WHERE `post_id` = :post_id';
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $result = json_encode(array("success" => "Post deleted successfully."));
            } else {
                $result = json_encode(array("error" => "Failed to delete post."));
            }
        } else {
            $result = json_encode(array("error" => "Post ID is required."));
        }

        unset($stmt); // Unset the statement
        return $result;
    }
}

$posts = new Posts();

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    echo $posts->readPosts();
} elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_REQUEST["operation"]) && isset($_REQUEST["json"])) {
        $operation = $_REQUEST["operation"];
        $json = $_REQUEST["json"];

        switch ($operation) {
            case "create":
                echo $posts->createPost($json);
                break;
            case "update":
                echo $posts->updatePost($json);
                break;
            case "delete":
                echo $posts->deletePost($json);
                break;
            default:
                echo json_encode(array("error" => "No such operation here"));
                break;
        }
    } else {
        echo json_encode(array("error" => "Missing Parameters"));
    }
} else {
    echo json_encode(array("error" => "Invalid Request Method"));
}
?>
