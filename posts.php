<?php
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
include('connection.php');
include('helpers.php');

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
        $data = json_decode($json, true);

        // Validate and sanitize input
        if (!isset($data['user_id']) || !isset($data['content']) || !isset($data['expiry_duration'])) {
            echo json_encode(["error" => "Missing Data"]);
            return;
        }

        $user_id = (int) sanitizeInput($data['user_id']);
        $content = sanitizeInput($data['content']);
        $expiry_duration = sanitizeInput($data['expiry_duration']);

        $expiry_datetime = calculateExpiryDatetime($expiry_duration);

        // Correct placeholder in the SQL query
        $query = "INSERT INTO posts (user_id, content, timestamp, expiry_duration) VALUES (:user_id, :content, NOW(), :expiry_datetime)";

        try {
            $this->conn->beginTransaction();

            // Insert post
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':content', $content, PDO::PARAM_STR);
            // Change this to match the placeholder in the query
            $stmt->bindParam(':expiry_datetime', $expiry_datetime, PDO::PARAM_STR);
            $stmt->execute();

            $post_id = $this->conn->lastInsertId();

            // Handle image uploads
            $image_names = [];
            if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                $total_files = count($_FILES['images']['name']);
                for ($i = 0; $i < $total_files; $i++) {
                    // Debugging: log file information
                    error_log("Processing file: " . $_FILES['images']['name'][$i]);

                    if ($_FILES['images']['error'][$i] == UPLOAD_ERR_OK) {
                        $image_name = sanitizeInput($_FILES['images']['name'][$i]);
                        $temp_name = $_FILES['images']['tmp_name'][$i];
                        $upload_dir = 'POST_IMAGES/';
                        $target_file = $upload_dir . basename($image_name);

                        // Debugging: log target file path
                        error_log("Target file path: " . $target_file);

                        if (move_uploaded_file($temp_name, $target_file)) {
                            // Image uploaded successfully
                            $image_names[] = $image_name; // Store the image name
                            error_log("Image uploaded: " . $image_name); // Log uploaded image name

                            // Insert image record
                            $image_query = "INSERT INTO images (post_id, image_url) VALUES (:post_id, :image_name)";
                            $image_stmt = $this->conn->prepare($image_query);
                            $image_stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
                            $image_stmt->bindParam(':image_name', $image_name, PDO::PARAM_STR);
                            $image_stmt->execute();
                        } else {
                            throw new Exception("Failed to move uploaded file: $image_name");
                        }
                    } else {
                        throw new Exception("File upload error: " . $_FILES['images']['error'][$i]);
                    }
                }
            }


            $this->conn->commit();
            echo json_encode(array("success" => true, "message" => "Post created successfully", "image_names" => $image_names));
        } catch (PDOException $e) {
            $this->conn->rollBack();
            echo json_encode(array("success" => false, "message" => "Failed to create post: " . $e->getMessage()));
        } catch (Exception $e) {
            $this->conn->rollBack();
            echo json_encode(array("success" => false, "message" => $e->getMessage()));
        } finally {
            $stmt = null; // Cleanup
        }
    }

    // Read all posts
    public function readPosts($json)
    {
        $json = json_decode($json, true);
        $stmt = null;
        try {
            // Sanitize input data
            $json = sanitizeInput($json);

            // SQL query to get posts, their authors, images, and reactions (if liked by the user)
            $sql = 'SELECT posts.`post_id`, posts.`user_id`, posts.`content`, posts.`timestamp`, posts.`expiry_duration`, 
                           users.first_name, users.last_name, users.username, users.profile_image, 
                           GROUP_CONCAT(images.image_url) AS post_images, 
                           IF(reactions.`reaction_type` = "like", 1, 0) AS liked_by_user 
                    FROM `posts`
                    LEFT JOIN `follows` ON posts.`user_id` = follows.`following_id` AND follows.`follower_id` = :userId
                    JOIN `users` ON posts.`user_id` = users.`user_id`
                    LEFT JOIN `images` ON posts.`post_id` = images.`post_id`
                    LEFT JOIN `reactions` ON posts.`post_id` = reactions.`post_id` AND reactions.`user_id` = :userId
                    WHERE follows.`follower_id` = :userId OR posts.`user_id` = :userId
                    GROUP BY posts.`post_id`
                    ORDER BY posts.`timestamp` DESC';

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':userId', $json['userId'], PDO::PARAM_INT);
            $stmt->execute();

            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = json_encode(array("success" => $posts));
        } catch (PDOException $e) {
            $result = json_encode(array("error" => $e->getMessage()));
        } finally {
            unset($stmt);
        }
        return $result;
    }

    // Update a post
    public function updatePost($json)
    {
        $json = json_decode($json, true);
        $stmt = null;
        try {
            // Sanitize input data
            $json = sanitizeInput($json);
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
        } catch (PDOException $e) {
            $result = json_encode(array("error" => $e->getMessage()));
        } finally {
            unset($stmt);
        }
        return $result;
    }

    // Delete a post
    public function deletePost($json)
    {
        $json = json_decode($json, true);
        $stmt = null;
        try {
            // Sanitize input data
            $json = sanitizeInput($json);
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
        } catch (PDOException $e) {
            $result = json_encode(array("error" => $e->getMessage()));
        } finally {
            unset($stmt);
        }
        return $result;
    }

    public function likePost($json)
    {
        $data = json_decode($json, true);

        // Validate input
        if (!isset($data['user_id']) || !isset($data['post_id'])) {
            return json_encode(["error" => "Missing Data"]);
        }

        $user_id = (int) sanitizeInput($data['user_id']);
        $post_id = (int) sanitizeInput($data['post_id']);
        $reaction_type = 'like'; // Default reaction type

        try {
            // Check if the user has already liked the post
            $checkQuery = "SELECT * FROM reactions WHERE user_id = :user_id AND post_id = :post_id";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
            $stmt->execute();

            // If a record is found, the user has already liked the post
            if ($stmt->rowCount() > 0) {
                return json_encode(["error" => "User has already liked this post"]);
            }

            // Insert the like into the reactions table
            $insertQuery = "INSERT INTO reactions (user_id, post_id, reaction_type, timestamp) VALUES (:user_id, :post_id, :reaction_type, NOW())";
            $stmt = $this->conn->prepare($insertQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
            $stmt->bindParam(':reaction_type', $reaction_type, PDO::PARAM_STR);
            $stmt->execute();

            return json_encode(["success" => true, "message" => "Post liked successfully"]);
        } catch (PDOException $e) {
            return json_encode(["error" => "Failed to like post: " . $e->getMessage()]);
        } finally {
            $stmt = null; // Cleanup
        }
    }

}

$posts = new Posts();

try {
    if ($_SERVER["REQUEST_METHOD"] == "GET" || $_SERVER["REQUEST_METHOD"] == "POST") {
        if (isset($_REQUEST["operation"]) && isset($_REQUEST["json"])) {
            $operation = $_REQUEST["operation"];
            $json = $_REQUEST["json"];

            switch ($operation) {
                case "createPost":
                    echo $posts->createPost($json);
                    break;

                case "getPosts":
                    echo $posts->readPosts($json);
                    break;

                case "updatePosts":
                    echo $posts->updatePost($json);
                    break;

                case "deletePosts":
                    echo $posts->deletePost($json);
                    break;

                case "likePost":
                    echo $posts->likePost($json);
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