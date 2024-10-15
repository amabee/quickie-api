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

    private function notifHandler($userID, $targetID, $postID, $type, $message)
    {
        switch ($type) {
            case "like":
                $this->likeNotif($userID, $targetID, $postID, $type, $message);
                break;
            case "comment":
                $this->commentNotif($userID, $targetID, $postID, $type, $message);
                break;

            default:
                break;
        }
    }

    private function likeNotif($userID, $targetUserID, $postID, $type, $message)
    {
        try {
            $query = "INSERT INTO notifications (user_id, target_user, type, message, related_post_id, is_read) 
                  VALUES (:user_id, :target_user, :type, :message, :related_post_id, 0)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userID, PDO::PARAM_INT);
            $stmt->bindParam(':target_user', $targetUserID, PDO::PARAM_INT);
            $stmt->bindParam(':type', $type, PDO::PARAM_STR);
            $stmt->bindParam(':message', $message, PDO::PARAM_STR);
            $stmt->bindParam(':related_post_id', $postID, PDO::PARAM_INT);
            $stmt->execute();

            return json_encode(["success" => true, "message" => "Notification created successfully"]);
        } catch (PDOException $e) {
            return json_encode(["error" => "Failed to create notification: " . $e->getMessage()]);
        }
    }

    private function commentNotif($userID, $targetUserID, $postID, $type, $message)
    {
        try {
            $query = "INSERT INTO notifications (user_id, target_user, type, message, related_post_id, is_read) 
                  VALUES (:user_id, :target_user, :type, :message, :related_post_id, 0)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userID, PDO::PARAM_INT);
            $stmt->bindParam(':target_user', $targetUserID, PDO::PARAM_INT);
            $stmt->bindParam(':type', $type, PDO::PARAM_STR);
            $stmt->bindParam(':message', $message, PDO::PARAM_STR);
            $stmt->bindParam(':related_post_id', $postID, PDO::PARAM_INT);
            $stmt->execute();

            return json_encode(["success" => true, "message" => "Notification created successfully"]);
        } catch (PDOException $e) {
            return json_encode(["error" => "Failed to create notification: " . $e->getMessage()]);
        }
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

        $cooldown_duration = rand(300, 7200);

        // Calculate next allowed post time
        $now = new DateTime();
        $next_allowed_post_time = clone $now;
        $next_allowed_post_time->modify("+{$cooldown_duration} seconds");

        // Format the next allowed post time and assign it as a string
        $next_time = $next_allowed_post_time->format('Y-m-d H:i:s');

        // Prepare the query for inserting the post
        $query = "INSERT INTO posts (user_id, content, timestamp, expiry_duration) VALUES (:user_id, :content, NOW(), :expiry_datetime)";

        try {
            $this->conn->beginTransaction();

            // Insert the post
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':content', $content, PDO::PARAM_STR);
            $stmt->bindParam(':expiry_datetime', $expiry_datetime, PDO::PARAM_STR);
            $stmt->execute();

            $post_id = $this->conn->lastInsertId();



            // Insert next allowed post time into post_cooldown table
            $cooldown_query = "INSERT INTO post_cooldown (user_id, next_allowed_post_time) 
                               VALUES (:user_id, :next_allowed_post_time)
                               ON DUPLICATE KEY UPDATE next_allowed_post_time = :next_allowed_post_time";
            $cooldown_stmt = $this->conn->prepare($cooldown_query);
            $cooldown_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $cooldown_stmt->bindParam(':next_allowed_post_time', $next_time, PDO::PARAM_STR); // Bind as string
            $cooldown_stmt->execute();

            // Handle image uploads
            $image_names = [];
            if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                $total_files = count($_FILES['images']['name']);
                for ($i = 0; $i < $total_files; $i++) {
                    if ($_FILES['images']['error'][$i] == UPLOAD_ERR_OK) {
                        $image_name = sanitizeInput($_FILES['images']['name'][$i]);
                        $temp_name = $_FILES['images']['tmp_name'][$i];
                        $upload_dir = 'POST_IMAGES/';
                        $target_file = $upload_dir . basename($image_name);

                        if (move_uploaded_file($temp_name, $target_file)) {
                            // Image uploaded successfully
                            $image_names[] = $image_name;

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
        $this->deleteExpiredPosts();
        $json = json_decode($json, true);
        $stmt = null;
        try {
            // Sanitize input data
            $json = sanitizeInput($json);

            // Set default limit and offset if not provided
            $limit = isset($json['limit']) ? (int) $json['limit'] : 10;
            $offset = isset($json['offset']) ? (int) $json['offset'] : 0;

            // SQL query to get posts, their authors, images, and reactions (if liked by the user),
            // and the total number of likes for each post
            $sql = 'SELECT posts.`post_id`, posts.`user_id`, posts.`content`, posts.`timestamp`, posts.`expiry_duration`, 
                           users.first_name, users.last_name, users.username, users.profile_image, 
                           GROUP_CONCAT(images.image_url) AS post_images, 
                           IF(reactions.`reaction_type` = "like", 1, 0) AS liked_by_user, 
                           (SELECT COUNT(*) FROM `reactions` WHERE `post_id` = posts.`post_id` AND `reaction_type` = "like") AS like_count 
                    FROM `posts`
                    LEFT JOIN `follows` ON posts.`user_id` = follows.`following_id` AND follows.`follower_id` = :userId
                    JOIN `users` ON posts.`user_id` = users.`user_id`
                    LEFT JOIN `images` ON posts.`post_id` = images.`post_id`
                    LEFT JOIN `reactions` ON posts.`post_id` = reactions.`post_id` AND reactions.`user_id` = :userId
                    WHERE follows.`follower_id` = :userId OR posts.`user_id` = :userId
                    GROUP BY posts.`post_id`
                    ORDER BY posts.`timestamp` DESC
                    LIMIT :limit OFFSET :offset';

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':userId', $json['userId'], PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
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

    // Delete Expired Posts
    private function deleteExpiredPosts()
    {
        try {
            $sql = "DELETE FROM posts WHERE expiry_duration < NOW()";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

        } catch (PDOException $e) {
            echo json_encode(array("exception" => $e->getMessage()));
        } finally {
            unset($stmt);
        }
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

        if (!isset($data['user_id'])) {
            return json_encode(["error" => "Missing User ID"]);
        }

        if (!isset($data['post_id'])) {
            return json_encode(["error" => "Missing Post ID"]);
        }

        if (!isset($data["target_id"])) {
            return json_encode(["error" => "Missing Target ID"]);
        }

        $user_id = (int) sanitizeInput($data['user_id']);
        $post_id = (int) sanitizeInput($data['post_id']);
        $reaction_type = 'like';
        $target_ID = (int) sanitizeInput($data["target_id"]);

        try {
            // Insert the like into the reactions table
            $insertQuery = "INSERT INTO reactions (user_id, post_id, reaction_type, timestamp) VALUES (:user_id, :post_id, :reaction_type, NOW())";
            $stmt = $this->conn->prepare($insertQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
            $stmt->bindParam(':reaction_type', $reaction_type, PDO::PARAM_STR);

            if ($stmt->execute()) {
                $userQuery = "SELECT first_name, last_name FROM users WHERE user_id = :user_id";
                $userStmt = $this->conn->prepare($userQuery);
                $userStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                $userStmt->execute();

                $user = $userStmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {

                    $fullName = $user['first_name'] . ' ' . $user['last_name'];
                    $message = "{$fullName} liked your post.";
                    $targetID = $target_ID;


                    $this->notifHandler($user_id, $targetID, $post_id, $reaction_type, $message);
                }

                return json_encode(["success" => "Post liked successfully"]);
            } else {
                return json_encode(["error" => $stmt->errorInfo()]);
            }

        } catch (PDOException $e) {
            return json_encode(["error" => "Failed to like post: " . $e->getMessage()]);
        } finally {
            $stmt = null;
            $userStmt = null;
        }
    }


    public function dislikePost($json)
    {
        $data = json_decode($json, true);

        // Validate input
        // if (!isset($data['user_id']) || !isset($data['post_id'])) {
        //     return json_encode(["error" => "Missing Data"]);
        // }

        if (!isset($data['user_id'])) {
            return json_encode(["error" => "Missing User ID"]);
        }

        if (!isset($data['post_id'])) {
            return json_encode(["error" => "Missing Post ID"]);
        }

        $user_id = (int) sanitizeInput($data['user_id']);
        $post_id = (int) sanitizeInput($data['post_id']);

        try {
            // Check if the user has liked the post
            $checkQuery = "SELECT * FROM reactions WHERE user_id = :user_id AND post_id = :post_id";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
            $stmt->execute();

            // If a record is found, the user can dislike the post
            if ($stmt->rowCount() === 0) {
                return json_encode(["error" => "User has not liked this post"]);
            }

            // Delete the like from the reactions table
            $deleteQuery = "DELETE FROM reactions WHERE user_id = :user_id AND post_id = :post_id";
            $stmt = $this->conn->prepare($deleteQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
            if ($stmt->execute()) {
                return json_encode(["success" => "Post disliked successfully"]);
            } else {
                return json_encode(value: ["error" => "Something went wrong unliking the post"]);
            }


        } catch (PDOException $e) {
            return json_encode(["error" => "Failed to dislike post: " . $e->getMessage()]);
        } finally {
            $stmt = null; // Cleanup
        }
    }

    public function getCommentsByPostId($json)
    {
        $data = json_decode($json, true);

        // Validate and sanitize input
        if (!isset($data['post_id'])) {
            return json_encode(["error" => "Post ID is required"]);
        }

        if (!isset($data['user_id'])) {
            return json_encode(["error" => "User ID is required"]);
        }

        $post_id = (int) sanitizeInput($data['post_id']);
        $user_id = (int) sanitizeInput($data['user_id']);

        try {
            // Fetch main comments
            $sqlMainComments = "SELECT 
                                c.comment_id, 
                                c.user_id, 
                                c.content, 
                                c.timestamp, 
                                u.first_name, 
                                u.last_name, 
                                u.username, 
                                u.profile_image,
                                CASE 
                                    WHEN cr.reaction_id IS NOT NULL THEN 1 
                                    ELSE 0 
                                END AS liked_by_user
                            FROM comments c
                            JOIN users u ON c.user_id = u.user_id
                            LEFT JOIN comment_reactions cr ON c.comment_id = cr.comment_id AND cr.user_id = :user_id
                            WHERE c.post_id = :post_id
                            ORDER BY c.timestamp DESC";

            $stmtMainComments = $this->conn->prepare($sqlMainComments);
            $stmtMainComments->bindParam(':post_id', $post_id, PDO::PARAM_INT);
            $stmtMainComments->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmtMainComments->execute();

            $mainComments = $stmtMainComments->fetchAll(PDO::FETCH_ASSOC);

            // Fetch all replies, including whether the current user liked them
            $sqlReplies = "SELECT 
                            ct.id, 
                            ct.parent_id,
                            ct.main_id,
                            ct.user_id, 
                            ct.content, 
                            ct.timestamp, 
                            u.first_name, 
                            u.last_name, 
                            u.username, 
                            u.profile_image,
                            CASE 
                                WHEN rr.reaction_id IS NOT NULL THEN 1 
                                ELSE 0 
                            END AS liked_by_user
                        FROM comment_threads ct
                        JOIN users u ON ct.user_id = u.user_id
                        LEFT JOIN reply_reactions rr ON ct.id = rr.reply_id AND rr.user_id = :user_id
                        WHERE ct.post_id = :post_id
                        ORDER BY ct.timestamp";

            $stmtReplies = $this->conn->prepare($sqlReplies);
            $stmtReplies->bindParam(':post_id', $post_id, PDO::PARAM_INT);
            $stmtReplies->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmtReplies->execute();

            $replies = $stmtReplies->fetchAll(PDO::FETCH_ASSOC);

            // Group replies by main_id and parent_id
            $replyMap = [];
            foreach ($replies as $reply) {
                $replyData = [
                    'id' => $reply['id'],
                    'main_id' => $reply['main_id'],
                    'parent_id' => $reply['parent_id'],
                    'user_id' => $reply['user_id'],
                    'content' => $reply['content'],
                    'timestamp' => $reply['timestamp'],
                    'first_name' => $reply['first_name'],
                    'last_name' => $reply['last_name'],
                    'username' => $reply['username'],
                    'profile_image' => $reply['profile_image'],
                    'liked_by_user' => $reply['liked_by_user'],
                    'replies' => [] // Initialize empty array for child replies
                ];

                // Group replies by main_id and parent_id
                $replyMap[$reply['main_id']][$reply['parent_id']][] = $replyData;
            }

            // Recursive function to structure nested replies
            function buildReplyTree($parentId, $mainId, &$replyMap)
            {
                $nestedReplies = [];
                if (isset($replyMap[$mainId][$parentId])) {
                    foreach ($replyMap[$mainId][$parentId] as $reply) {
                        // Recursively get the replies for this reply
                        $reply['replies'] = buildReplyTree($reply['id'], $mainId, $replyMap);
                        $nestedReplies[] = $reply;
                    }
                }
                return $nestedReplies;
            }

            // Structure comments and their replies
            $structuredComments = [];
            foreach ($mainComments as $comment) {
                $commentData = [
                    'comment_id' => $comment['comment_id'],
                    'user_id' => $comment['user_id'],
                    'content' => $comment['content'],
                    'timestamp' => $comment['timestamp'],
                    'first_name' => $comment['first_name'],
                    'last_name' => $comment['last_name'],
                    'username' => $comment['username'],
                    'profile_image' => $comment['profile_image'],
                    'liked_by_user' => $comment['liked_by_user'],
                    'replies' => []
                ];

                // Add top-level replies (where parent_id is null) to this comment
                $commentData['replies'] = buildReplyTree(null, $comment['comment_id'], $replyMap);

                $structuredComments[] = $commentData;
            }

            return json_encode(["success" => $structuredComments]);

        } catch (PDOException $e) {
            error_log($e->getMessage());
            return json_encode(["error" => $e->getMessage()]);
        } finally {
            unset($stmtMainComments, $stmtReplies);
        }
    }

    public function sendComment($json)
    {
        $data = json_decode($json, true);

        if (!isset($data['user_id'])) {
            return json_encode(["error" => "Missing User ID"]);
        }

        if (!isset($data['post_id'])) {
            return json_encode(["error" => "Missing Post ID"]);
        }

        if (!isset($data['target_id'])) {
            return json_encode(["error" => "Missing Target ID"]);
        }

        $post_id = (int) sanitizeInput($data['post_id']);
        $user_id = (int) sanitizeInput($data['user_id']);
        $content = sanitizeInput($data['content']);
        $reaction_type = 'comment';
        $target_ID = (int) sanitizeInput($data["target_id"]);

        try {
            // Insert the comment into the comments table
            $insertQuery = "INSERT INTO comments (post_id, user_id, content, timestamp) VALUES (:post_id, :user_id, :content, NOW())";
            $stmt = $this->conn->prepare($insertQuery);
            $stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':content', $content, PDO::PARAM_STR);

            if ($stmt->execute()) {

                $userQuery = "SELECT first_name, last_name FROM users WHERE user_id = :user_id";
                $userStmt = $this->conn->prepare($userQuery);
                $userStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                $userStmt->execute();

                $user = $userStmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {

                    $fullName = $user['first_name'] . ' ' . $user['last_name'];
                    $message = "{$fullName} Commented on your post.";
                    $targetID = $target_ID;

                    $this->notifHandler($user_id, $targetID, $post_id, $reaction_type, $message);
                }

                return json_encode(["success" => "Comment posted successfully"]);
            } else {
                return json_encode(["error" => "Failed to post comment"]);
            }
        } catch (PDOException $e) {
            return json_encode(["error" => "Failed to post comment: " . $e->getMessage()]);
        } finally {
            $stmt = null;
        }
    }

    public function likeComment($json)
    {
        $data = json_decode($json, true);


        if (!isset($data['user_id'])) {
            return json_encode(["error" => "Missing User ID"]);
        }

        if (!isset($data['comment_id'])) {
            return json_encode(["error" => "Missing Comment ID"]);
        }

        

        $user_id = (int) sanitizeInput($data['user_id']);
        $comment_id = (int) sanitizeInput($data['comment_id']);
        $reaction_type = 'liked';

        try {
            $insertQuery = "INSERT INTO comment_reactions (user_id, comment_id, reaction_type, timestamp) VALUES (:user_id, :comment_id, :reaction_type, NOW())";
            $stmt = $this->conn->prepare($insertQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':comment_id', $comment_id, PDO::PARAM_INT);
            $stmt->bindParam(':reaction_type', $reaction_type, PDO::PARAM_STR);
            if ($stmt->execute()) {
                return json_encode(["success" => "Comment liked successfully"]);
            } else {
                return json_encode(["error" => "Something went wrong liking the comment"]);
            }
        } catch (PDOException $e) {
            return json_encode(["error" => "Failed to like comment: " . $e->getMessage()]);
        } finally {
            $stmt = null; // Cleanup
        }
    }

    public function unlikeComment($json)
    {
        $data = json_decode($json, true);

        // Validate input
        if (!isset($data['user_id']) || !isset($data['comment_id'])) {
            return json_encode(["error" => "Missing Data"]);
        }

        $user_id = (int) sanitizeInput($data['user_id']);
        $comment_id = (int) sanitizeInput($data['comment_id']);

        try {
            // Delete the like from the comment_reactions table
            $deleteQuery = "DELETE FROM comment_reactions WHERE user_id = :user_id AND comment_id = :comment_id";
            $stmt = $this->conn->prepare($deleteQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':comment_id', $comment_id, PDO::PARAM_INT);
            if ($stmt->execute()) {
                return json_encode(["success" => "Comment unliked successfully"]);
            } else {
                return json_encode(["error" => "Something went wrong unliking the comment"]);
            }
        } catch (PDOException $e) {
            return json_encode(["error" => "Failed to dislike comment: " . $e->getMessage()]);
        } finally {
            $stmt = null; // Cleanup
        }
    }

    public function addCommentReply($json)
    {
        $data = json_decode($json, true);

        // Validate input
        if (!isset($data['user_id'])) {
            return json_encode(["error" => "Missing User ID"]);
        }

        if (!isset($data['post_id'])) {
            return json_encode(["error" => "Missing Post ID"]);
        }

        if (!isset($data['content'])) {
            return json_encode(["error" => "Missing Content"]);
        }

        if (!isset($data['main_id'])) {
            return json_encode(["error" => "Missing Main ID"]);
        }

        // Sanitize inputs
        $user_id = (int) sanitizeInput($data['user_id']);
        $post_id = (int) sanitizeInput($data['post_id']);
        $main_id = (int) sanitizeInput($data['main_id']);
        $content = sanitizeInput($data['content']);
        $timestamp = date('Y-m-d H:i:s');

        // Check if parent_id is set
        $parent_id = isset($data['parent_id']) ? (int) sanitizeInput($data['parent_id']) : null;

        try {
            // Insert the new comment thread into the comment_threads table
            $insertQuery = "INSERT INTO comment_threads (parent_id, post_id, user_id, main_id, content, timestamp) 
                    VALUES (:parent_id, :post_id, :user_id, :main_id, :content, :timestamp)";
            $stmt = $this->conn->prepare($insertQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
            $stmt->bindParam(':main_id', $main_id, PDO::PARAM_INT);
            $stmt->bindParam(':content', $content, PDO::PARAM_STR);
            $stmt->bindParam(':timestamp', $timestamp);

            // Bind parent_id, allow it to be null
            if ($parent_id !== null) {
                $stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':parent_id', null, PDO::PARAM_NULL);
            }

            if ($stmt->execute()) {
                return json_encode(["success" => "Comment added successfully"]);
            } else {
                return json_encode(["error" => "Failed to add comment. Please try again."]);
            }
        } catch (PDOException $e) {
            return json_encode(["error" => "Error occurred: " . $e->getMessage()]);
        } finally {
            $stmt = null; // Cleanup
        }
    }
    public function likeReply($json)
    {
        $data = json_decode($json, true);

        if (!isset($data['user_id'])) {
            return json_encode(["error" => "Missing User ID"]);
        }

        if (!isset($data['reply_id'])) {
            return json_encode(["error" => "Missing Reply ID"]);
        }

        $user_id = (int) sanitizeInput($data['user_id']);
        $reply_id = (int) sanitizeInput($data['reply_id']);
        $reaction_type = 'liked';

        try {
            // Check if the user has already liked this reply
            $checkQuery = "SELECT COUNT(*) FROM reply_reactions WHERE user_id = :user_id AND reply_id = :reply_id AND reaction_type = :reaction_type";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':reply_id', $reply_id, PDO::PARAM_INT);
            $stmt->bindParam(':reaction_type', $reaction_type, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->fetchColumn() > 0) {
                return json_encode(["error" => "You have already liked this reply"]);
            }

            // Insert the like into the comment_reactions table
            $insertQuery = "INSERT INTO `reply_reactions`(`reply_id`, `user_id`, `reaction_type`, `timestamp`) VALUES (:reply_id, :user_id, :reaction_type, NOW())";
            $stmt = $this->conn->prepare($insertQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':reply_id', $reply_id, PDO::PARAM_INT);
            $stmt->bindParam(':reaction_type', $reaction_type, PDO::PARAM_STR);

            if ($stmt->execute()) {
                return json_encode(["success" => "Reply liked successfully"]);
            } else {
                return json_encode(["error" => "Failed to like reply. Please try again."]);
            }
        } catch (PDOException $e) {
            return json_encode(["error" => "Error occurred: " . $e->getMessage()]);
        } finally {
            $stmt = null; // Cleanup
        }
    }

    public function unlikeReply($json)
    {
        $data = json_decode($json, true);

        if (!isset($data['user_id']) || !isset($data['reply_id'])) {
            return json_encode(["error" => "Missing User ID or Reply ID"]);
        }

        $user_id = (int) sanitizeInput($data['user_id']);
        $reply_id = (int) sanitizeInput($data['reply_id']);

        try {
            // Check if the like exists before trying to delete
            $checkQuery = "SELECT COUNT(*) FROM reply_reactions WHERE user_id = :user_id AND reply_id = :reply_id AND reaction_type = 'liked'";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':reply_id', $reply_id, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->fetchColumn() == 0) {
                return json_encode(["error" => "You have not liked this reply"]);
            }

            // Delete the like from the comment_reactions table
            $deleteQuery = "DELETE FROM reply_reactions WHERE user_id = :user_id AND reply_id = :reply_id AND reaction_type = 'liked'";
            $stmt = $this->conn->prepare($deleteQuery);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':reply_id', $reply_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                return json_encode(["success" => "Reply unliked successfully"]);
            } else {
                return json_encode(["error" => "Failed to unlike reply. Please try again."]);
            }
        } catch (PDOException $e) {
            return json_encode(["error" => "Error occurred: " . $e->getMessage()]);
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

                case "dislikePost":
                    echo $posts->dislikePost($json);
                    break;

                case "getComments":
                    echo $posts->getCommentsByPostId($json);
                    break;

                case "sendComment":
                    echo $posts->sendComment($json);
                    break;

                case "likeComment":
                    echo $posts->likeComment($json);
                    break;

                case "addCommentReply":
                    echo $posts->addCommentReply($json);
                    break;

                case "unlikeComment":
                    echo $posts->unlikeComment($json);
                    break;

                case "likeReply":
                    echo $posts->likeReply($json);
                    break;

                case "unlikeReply":
                    echo $posts->unlikeReply($json);
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