<?php
/**
 * Custom Embed Builder Backend Handlers
 * 
 * USAGE: Copy the case statements from the switch below and add them to
 * save_discord_channel_config.php around line 90, after 'send_stream_schedule_message' case
 * 
 * This file is structured as valid PHP for syntax checking purposes.
 * The actual implementation should be integrated into the existing switch statement.
 */

// Simulated context for syntax validation
if (false) {
    $action = '';
    $input = [];
    $debug_logs = [];
    $discord_conn = null;
    $server_id = '';
    $api_key = '';
    $user_id = 0;
    switch ($action) {
        // Case 1: Save Custom Embed Configuration
        case 'save_custom_embed':
    debug_log('Processing save_custom_embed with input: ' . json_encode($input));
    $embed_id = isset($input['embed_id']) && !empty($input['embed_id']) ? (int)$input['embed_id'] : 0;
    $embed_name = isset($input['embed_name']) ? trim($input['embed_name']) : '';
    $title = isset($input['title']) ? trim($input['title']) : null;
    $description = isset($input['description']) ? trim($input['description']) : null;
    $color = isset($input['color']) ? trim($input['color']) : '#5865f2';
    $url = isset($input['url']) ? trim($input['url']) : null;
    $thumbnail_url = isset($input['thumbnail_url']) ? trim($input['thumbnail_url']) : null;
    $image_url = isset($input['image_url']) ? trim($input['image_url']) : null;
    $footer_text = isset($input['footer_text']) ? trim($input['footer_text']) : null;
    $footer_icon_url = isset($input['footer_icon_url']) ? trim($input['footer_icon_url']) : null;
    $author_name = isset($input['author_name']) ? trim($input['author_name']) : null;
    $author_url = isset($input['author_url']) ? trim($input['author_url']) : null;
    $author_icon_url = isset($input['author_icon_url']) ? trim($input['author_icon_url']) : null;
    $timestamp_enabled = isset($input['timestamp_enabled']) ? (bool)$input['timestamp_enabled'] : false;
    $fields = isset($input['fields']) ? $input['fields'] : null;
    // Validate embed name
    if (empty($embed_name)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Embed name is required', 'debug_logs' => $debug_logs]);
        exit();
    }
    // Prepare timestamp value
    $timestamp_value = $timestamp_enabled ? 1 : 0;
    if ($embed_id > 0) {
        // Update existing embed
        $updateStmt = $discord_conn->prepare("UPDATE custom_embeds SET embed_name = ?, title = ?, description = ?, color = ?, url = ?, thumbnail_url = ?, image_url = ?, footer_text = ?, footer_icon_url = ?, author_name = ?, author_url = ?, author_icon_url = ?, fields = ?, timestamp_enabled = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND server_id = ?");
        if (!$updateStmt) {
            debug_log('Failed to prepare update statement: ' . $discord_conn->error);
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
            exit();
        }
        $updateStmt->bind_param("sssssssssssssiis", $embed_name, $title, $description, $color, $url, $thumbnail_url, $image_url, $footer_text, $footer_icon_url, $author_name, $author_url, $author_icon_url, $fields, $timestamp_value, $embed_id, $server_id);
        $success = $updateStmt->execute();
        $updateStmt->close();
        if ($success) {
            debug_log('Successfully updated custom embed ID: ' . $embed_id);
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Embed updated successfully',
                'embed_id' => $embed_id,
                'debug_logs' => $debug_logs
            ]);
            exit();
        } else {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to update embed: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
            exit();
        }
    } else {
        // Insert new embed
        $insertStmt = $discord_conn->prepare("INSERT INTO custom_embeds (server_id, embed_name, title, description, color, url, thumbnail_url, image_url, footer_text, footer_icon_url, author_name, author_url, author_icon_url, fields, timestamp_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$insertStmt) {
            debug_log('Failed to prepare insert statement: ' . $discord_conn->error);
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
            exit();
        }
        $insertStmt->bind_param("ssssssssssssssi", $server_id, $embed_name, $title, $description, $color, $url, $thumbnail_url, $image_url, $footer_text, $footer_icon_url, $author_name, $author_url, $author_icon_url, $fields, $timestamp_value);
        $success = $insertStmt->execute();
        $new_embed_id = $discord_conn->insert_id;
        $insertStmt->close();
        if ($success) {
            debug_log('Successfully created custom embed ID: ' . $new_embed_id);
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Embed created successfully',
                'embed_id' => $new_embed_id,
                'debug_logs' => $debug_logs
            ]);
            exit();
        } else {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to create embed: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
            exit();
        }
    }
    break;

// Case 2: Send Custom Embed to Discord Channel
case 'send_custom_embed':
    debug_log('send_custom_embed case entered with input: ' . json_encode($input));
    if (!isset($input['embed_id']) || !isset($input['channel_id'])) {
        debug_log('Missing embed_id or channel_id in input');
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Embed ID and Channel ID are required', 'debug_logs' => $debug_logs]);
        exit();
    }
    $embed_id = (int)$input['embed_id'];
    $channel_id = trim($input['channel_id']);
    if (empty($channel_id)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Channel ID cannot be empty', 'debug_logs' => $debug_logs]);
        exit();
    }
    // Fetch embed data from database
    $fetchStmt = $discord_conn->prepare("SELECT * FROM custom_embeds WHERE id = ? AND server_id = ?");
    $fetchStmt->bind_param("is", $embed_id, $server_id);
    $fetchStmt->execute();
    $result = $fetchStmt->get_result();
    if ($embedData = $result->fetch_assoc()) {
        $fetchStmt->close();
        // Check if api_key exists
        if (empty($api_key)) {
            debug_log('api_key not found for user_id: ' . $user_id);
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'API key not found. Please refresh the page and try again.', 'debug_logs' => $debug_logs]);
            exit();
        }
        // Send websocket notification to post the embed to Discord channel
        $websocket_url = 'https://websocket.botofthespecter.com/notify';
        debug_log('Building websocket request with api_key: ' . substr($api_key, 0, 10) . '...');
        // Parse fields if present
        $fields_data = [];
        if (!empty($embedData['fields'])) {
            $fields_data = json_decode($embedData['fields'], true);
            if (!$fields_data) {
                $fields_data = [];
            }
        }
        $params = [
            'code' => $api_key,
            'event' => 'post_custom_embed',
            'server_id' => $server_id,
            'channel_id' => $channel_id,
            'embed_id' => $embed_id,
            'embed_name' => $embedData['embed_name'],
            'title' => $embedData['title'] ?? '',
            'description' => $embedData['description'] ?? '',
            'color' => $embedData['color'] ?? '#5865f2',
            'url' => $embedData['url'] ?? '',
            'thumbnail_url' => $embedData['thumbnail_url'] ?? '',
            'image_url' => $embedData['image_url'] ?? '',
            'footer_text' => $embedData['footer_text'] ?? '',
            'footer_icon_url' => $embedData['footer_icon_url'] ?? '',
            'author_name' => $embedData['author_name'] ?? '',
            'author_url' => $embedData['author_url'] ?? '',
            'author_icon_url' => $embedData['author_icon_url'] ?? '',
            'timestamp_enabled' => $embedData['timestamp_enabled'] ?? 0,
            'fields' => json_encode($fields_data)
        ];
        // Build query string
        $query_string = http_build_query($params);
        $full_url = $websocket_url . '?' . $query_string;
        debug_log('Sending websocket request to: ' . $full_url);
        // Send HTTP GET request to websocket server
        $ch = curl_init($full_url);
        if ($ch === false) {
            debug_log('Failed to initialize cURL');
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to initialize HTTP request', 'debug_logs' => $debug_logs]);
            exit();
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        if ($response === false) {
            $curl_error = curl_error($ch);
            debug_log('cURL error: ' . $curl_error);
            curl_close($ch);
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'HTTP request failed: ' . $curl_error, 'debug_logs' => $debug_logs]);
            exit();
        }
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        debug_log('Websocket response: HTTP ' . $http_code . ', Body: ' . $response);
        if ($http_code !== 200) {
            debug_log("Failed to send websocket notification for custom embed: HTTP $http_code, Response: $response");
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to send embed to Discord channel', 'debug_logs' => $debug_logs]);
            exit();
        } else {
            debug_log("Successfully sent websocket notification for custom embed");
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Embed sent to Discord channel successfully',
                'embed_id' => $embed_id,
                'channel_id' => $channel_id,
                'debug_logs' => $debug_logs
            ]);
            exit();
        }
    } else {
        $fetchStmt->close();
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Embed not found', 'debug_logs' => $debug_logs]);
        exit();
    }
    break;

// Case 3: Delete Custom Embed
case 'delete_custom_embed':
    debug_log('Processing delete_custom_embed with input: ' . json_encode($input));
    if (!isset($input['embed_id'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Embed ID is required', 'debug_logs' => $debug_logs]);
        exit();
    }
    $embed_id = (int)$input['embed_id'];
    // Delete embed (cascade will delete associated messages)
    $deleteStmt = $discord_conn->prepare("DELETE FROM custom_embeds WHERE id = ? AND server_id = ?");
    if (!$deleteStmt) {
        debug_log('Failed to prepare delete statement: ' . $discord_conn->error);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
        exit();
    }
    $deleteStmt->bind_param("is", $embed_id, $server_id);
    $success = $deleteStmt->execute();
    $deleteStmt->close();
    if ($success) {
        debug_log('Successfully deleted custom embed ID: ' . $embed_id);
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Embed deleted successfully',
            'debug_logs' => $debug_logs
        ]);
        exit();
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to delete embed: ' . $discord_conn->error, 'debug_logs' => $debug_logs]);
        exit();
    }
    break;
    }
}
?>