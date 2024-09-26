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

    public function getCurrentUser($json)
    {
        $data = json_decode($json, true);

        if (!isset($data['current_user_id'])) {
            return json_encode(["error" => "Current user ID is required"]);
        }

        $current_user_id = intval($data['current_user_id']);
        $stmt = null;
        $followers_stmt = null;
        $following_stmt = null;

        try {
            // Get user details
            $sql = "SELECT user_id, first_name, last_name, username, email, bio, profile_image 
                    FROM users 
                    WHERE user_id = :current_user_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
            $stmt->execute();

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Get followers count
                $followers_sql = "SELECT COUNT(*) as followers_count 
                                  FROM follows 
                                  WHERE following_id = :current_user_id";

                $followers_stmt = $this->conn->prepare($followers_sql);
                $followers_stmt->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
                $followers_stmt->execute();
                $followers_count = $followers_stmt->fetchColumn();

                // Get following count
                $following_sql = "SELECT COUNT(*) as following_count 
                                  FROM follows 
                                  WHERE follower_id = :current_user_id";

                $following_stmt = $this->conn->prepare($following_sql);
                $following_stmt->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
                $following_stmt->execute();
                $following_count = $following_stmt->fetchColumn();

                // Return user details along with followers and following counts
                return json_encode([
                    "success" => array_merge($user, [
                        "followers_count" => $followers_count,
                        "following_count" => $following_count
                    ])
                ]);
            } else {
                return json_encode(["error" => "User not found"]);
            }
        } catch (PDOException $e) {
            return json_encode(["error" => $e->getMessage()]);
        } finally {
            unset($stmt);
            unset($followers_stmt);
            unset($following_stmt);
        }
    }

    public function getCurrentUserPosts($json)
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
                    JOIN `users` ON posts.`user_id` = users.`user_id`
                    LEFT JOIN `images` ON posts.`post_id` = images.`post_id`
                    LEFT JOIN `reactions` ON posts.`post_id` = reactions.`post_id` AND reactions.`user_id` = :current_user_id
                    WHERE posts.user_id = :current_user_id  -- Specify the posts table
                    GROUP BY posts.`post_id`
                    ORDER BY posts.`timestamp` DESC
                    ';

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':current_user_id', $json['current_user_id'], PDO::PARAM_INT);
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

    public function getCurrentUserFollowers($json)
    {
        $data = json_decode($json, true);

        if (!isset($data['current_user_id'])) {
            return json_encode(["error" => "Current user ID is required"]);
        }

        $current_user_id = intval($data['current_user_id']);

        try {
            // SQL query to fetch current user's followers and check if the current user is following them back
            $sql = "SELECT f.follower_id, u.first_name, u.last_name, u.username, u.profile_image,
                           (SELECT COUNT(*) FROM follows f2 WHERE f2.follower_id = :current_user_id AND f2.following_id = f.follower_id) AS is_following_back
                    FROM follows f
                    JOIN users u ON f.follower_id = u.user_id
                    WHERE f.following_id = :current_user_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
            $stmt->execute();

            $followers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode(["success" => $followers]);
        } catch (PDOException $e) {
            return json_encode(["error" => $e->getMessage()]);
        } finally {
            unset($stmt);
        }
    }


    public function getCurrentUserFollowing($json)
    {
        $data = json_decode($json, true);

        if (!isset($data['current_user_id'])) {
            return json_encode(["error" => "Current user ID is required"]);
        }

        $current_user_id = intval($data['current_user_id']);

        try {
            // SQL query to fetch users that the current user is following and check if the user is also followed back
            $sql = "SELECT f.following_id, u.first_name, u.last_name, u.username, u.profile_image,
                           (SELECT COUNT(*) FROM follows f2 WHERE f2.follower_id = :current_user_id AND f2.following_id = f.following_id) AS is_following
                    FROM follows f
                    JOIN users u ON f.following_id = u.user_id
                    WHERE f.follower_id = :current_user_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
            $stmt->execute();

            $following = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode(["success" => $following]);
        } catch (PDOException $e) {
            return json_encode(["error" => $e->getMessage()]);
        } finally {
            unset($stmt);
        }
    }

    public function updateProfile($json, $files)
    {
        $data = json_decode($json, true);

        if (
            !isset($data['user_id'])
            || !isset($data['first_name'])
            || !isset($data['last_name'])
            || !isset($data['username'])
            || !isset($data['email'])
        ) {
            return json_encode(["error" => "Missing required fields"]);
        }

        $user_id = intval($data['user_id']);
        $first_name = sanitizeInput($data['first_name']);
        $last_name = sanitizeInput($data['last_name']);
        $username = sanitizeInput($data['username']);
        $email = sanitizeInput($data['email']);

        // Optional profile image field
        $profile_image = isset($files['profile_image']) ? $files['profile_image'] : null;

        // Optional bio field
        $bio = isset($data['bio']) ? sanitizeInput($data['bio']) : null;

        // Optional password field
        $password = isset($data['password']) ? sha1(sanitizeInput($data['password'])) : null;

        // Validate the uploaded file only if a profile image is provided
        if ($profile_image && $profile_image['error'] !== UPLOAD_ERR_OK) {
            return json_encode(["error" => "Error uploading the file"]);
        }

        // Check for valid file type only if a profile image is uploaded
        if ($profile_image) {
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            $file_extension = strtolower(pathinfo($profile_image['name'], PATHINFO_EXTENSION));

            if (!in_array($file_extension, $allowed_extensions)) {
                return json_encode(["error" => "Invalid file type. Only JPG, JPEG, PNG, and GIF files are allowed"]);
            }

            // Create a unique filename to avoid overwriting existing files
            $new_filename = uniqid('user_' . $user_id . '_') . '.' . $file_extension;

            // Set the target directory
            $target_directory = 'USER_IMAGES/';
            $target_file = $target_directory . $new_filename;

            // Move the uploaded file to the target directory
            if (!move_uploaded_file($profile_image['tmp_name'], $target_file)) {
                return json_encode(["error" => "Failed to move uploaded file"]);
            }
        } else {
            // If no new image is uploaded, use a placeholder or keep existing
            $new_filename = null; // Placeholder; you can fetch existing filename if needed
        }

        try {
            // Start building the query
            $sql = 'UPDATE `users` SET `first_name` = :first_name, `last_name` = :last_name, `username` = :username, `email` = :email';

            // Add the profile image update if it's provided
            if ($new_filename) {
                $sql .= ', `profile_image` = :profile_image';
            }

            if ($password !== null) {
                $sql .= ', `password` = :password';
            }

            // If bio is provided, include it in the query
            if ($bio !== null) {
                $sql .= ', `bio` = :bio';
            }

            $sql .= ' WHERE `user_id` = :user_id';

            $stmt = $this->conn->prepare($sql);

            // Bind required parameters
            $stmt->bindParam(':first_name', $first_name, PDO::PARAM_STR);
            $stmt->bindParam(':last_name', $last_name, PDO::PARAM_STR);
            $stmt->bindParam(':username', $username, PDO::PARAM_STR);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);

            // Bind the optional profile image if it's provided
            if ($new_filename) {
                $stmt->bindParam(':profile_image', $new_filename, PDO::PARAM_STR);
            }

            // Bind the optional password if it's provided
            if ($password !== null) {
                $stmt->bindParam(':password', $password, PDO::PARAM_STR);
            }

            // Bind the optional bio if it's provided
            if ($bio !== null) {
                $stmt->bindParam(':bio', $bio, PDO::PARAM_STR);
            }

            // Bind the user ID
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);

            // Execute the query
            if ($stmt->execute()) {
                return json_encode(["success" => "Profile updated successfully", "profile_image" => $new_filename]);
            } else {
                return json_encode(["error" => "Failed to update profile"]);
            }
        } catch (PDOException $e) {
            return json_encode(["error" => $e->getMessage()]);
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

                case "getCurrentUser":
                    echo $user->getCurrentUser($json);
                    break;

                case "getCurrentUserPosts":
                    echo $user->getCurrentUserPosts($json);
                    break;

                case "getCurrentUserFollowers":
                    echo $user->getCurrentUserFollowers($json);
                    break;

                case "getCurrentUserFollowing":
                    echo $user->getCurrentUserFollowing($json);
                    break;

                case "updateProfile":
                    echo $user->updateProfile($_REQUEST["json"], $_FILES);
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