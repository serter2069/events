<?php
echo 22;
  require_once 'db_connection.php';


// System prompt template для Claude API



function processUserMessage($userId, $chatId, $text, $context) {
    global $claude_api_key;
    
    try {
        debug("Starting message processing", "MESSAGE_PROCESS", [
            'userId' => $userId,
            'chatId' => $chatId,
            'userText' => mb_convert_encoding($text, 'UTF-8', 'auto')
        ]);
        
        // Отправляем сообщение о загрузке
        $loadingMessage = sendTelegramMessage($chatId, "⌛ " . __('processing_request'));
        $loadingMessageData = json_decode($loadingMessage, true);
        $loadingMessageId = $loadingMessageData['result']['message_id'];

        // Декодируем и проверяем контекст
        $contextData = json_decode($context, true);
        if (!$contextData) {
            throw new Exception("Failed to decode context data");
        }
        
        debug("Context loaded", "CONTEXT", [
            'userPreferences' => $contextData['user'],
            'eventsCount' => count($contextData['events'])
        ]);

        // Готовим системный промпт с улучшенным форматированием
        $systemPrompt = "You are an event recommendation assistant. " .
            "Format events as follows:\n" .
            "- Event name with emoji based on type:\n" .
            "  * 🎵 for music/concerts\n" .
            "  * 🎭 for theater/shows\n" .
            "  * 🎨 for arts/exhibitions\n" .
            "  * 🎪 for entertainment\n" .
            "  * 🎬 for cinema\n" .
            "  * 🎤 for comedy\n" .
            "  * 🎮 for gaming\n" .
            "- ⏰ Start time\n" .
            "- Format venue and address as HTML link to Google Maps:\n" .
            "  📍 <a href=\"https://www.google.com/maps/search/?api=1&query=VENUE_ADDRESS\">VENUE_NAME, ADDRESS</a>\n" .
            "- 💰 Price (if available)\n" .
            "- 📝 Brief description (1-2 sentences)\n" .
            "- 🎟️ Ticket link\n\n" .
            "Important requirements:\n" .
            "1. Respond in {$contextData['user']['language']} language\n" .
            "2. Show only 3 most relevant events sorted by start time\n" .
            "3. ALWAYS encode spaces with + in Google Maps links\n" .
            "4. Consider user preferences:\n" .
            "   - Gender: {$contextData['user']['gender']}\n" .
            "   - Age: {$contextData['user']['age_group']}\n" .
            "   - Interests: " . implode(", ", $contextData['user']['interests']) . "\n" .
            "5. If there are more events, ask if user wants to see more";

        // Структурируем запрос к API
        $requestData = [
            'model' => 'claude-3-opus-20240229',
            'max_tokens' => 1024,
            'system' => $systemPrompt,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'available_events' => $contextData['events'],
                        'user_query' => mb_convert_encoding($text, 'UTF-8', 'auto')
                    ], JSON_UNESCAPED_UNICODE)
                ]
            ]
        ];

        debug("Preparing Claude API request", "CLAUDE", [
            'requestData' => $requestData
        ]);

        // Выполняем запрос к API
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $claude_api_key,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_RETURNTRANSFER => true
        ]);
        
        debug("Sending request to Claude API", "CLAUDE");
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        debug("Received response from Claude API", "CLAUDE", [
            'httpCode' => $httpCode,
            'error' => $error,
            'response' => $response
        ]);
        
        curl_close($ch);
        
        if ($response === false || $httpCode !== 200) {
            throw new Exception("Failed to get response from Claude API: " . ($error ?: "HTTP $httpCode"));
        }

        // Обрабатываем ответ
        $responseData = json_decode($response, true);
        if (!$responseData || !isset($responseData['content'][0]['text'])) {
            throw new Exception("Invalid response format from Claude API");
        }

        $messageText = $responseData['content'][0]['text'];
        
        debug("Processing Claude API response", "CLAUDE", [
            'messageLength' => mb_strlen($messageText)
        ]);

        // Обновляем сообщение с результатом
        try {
            debug("Sending final message", "TELEGRAM_UPDATE", [
                'messageLength' => mb_strlen($messageText)
            ]);
            
            editTelegramMessage($chatId, $loadingMessageId, $messageText, ['parse_mode' => 'HTML']);
            
        } catch (Exception $e) {
            debug("Error sending final message", "ERROR", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        debug("Error in processUserMessage", "ERROR", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // Пытаемся отправить сообщение об ошибке пользователю
        try {
            sendTelegramMessage($chatId, "Произошла ошибка при обработке запроса. Пожалуйста, попробуйте позже.");
        } catch (Exception $sendError) {
            debug("Error sending error message to user", "ERROR", [
                'error' => $sendError->getMessage()
            ]);
        }
        
        return false;
    }
}


function sendClaudeRequest($messages, $chatId, $loadingMessageId) {
    global $claude_api_key;
    
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    
    $data = [
        'model' => 'claude-3-opus-20240229',
        'max_tokens' => 1024,
        'messages' => $messages,
        'stream' => true
    ];
    
    $fullResponse = '';
    $lastUpdateTime = microtime(true);
    $updateInterval = 2.0; // Интервал обновления сообщения в секундах
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $claude_api_key,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_WRITEFUNCTION => function($ch, $chunk) 
            use ($chatId, $loadingMessageId, &$fullResponse, &$lastUpdateTime, $updateInterval) {
            
            if (preg_match_all('/data: ({.+?})\n\n/s', $chunk, $matches)) {
                foreach ($matches[1] as $jsonData) {
                    $data = json_decode($jsonData, true);
                    
                    // Пропускаем служебные сообщения
                    if (isset($data['type']) && $data['type'] !== 'content_block_delta') {
                        continue;
                    }
                    
                    // Добавляем новый текст к полному ответу
                    if (isset($data['delta']['text'])) {
                        $fullResponse .= $data['delta']['text'];
                        
                        // Обновляем сообщение в Telegram с определенным интервалом
                        $currentTime = microtime(true);
                        if (($currentTime - $lastUpdateTime) >= $updateInterval) {
                            try {
                                editTelegramMessage($chatId, $loadingMessageId, $fullResponse, ['parse_mode' => 'HTML']);
                                $lastUpdateTime = $currentTime;
                            } catch (Exception $e) {
                                error_log("Error updating message: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
            return strlen($chunk);
        },
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT => 120
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_ggetSystemPromptetinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error || $httpCode !== 200) {
        error_log("Claude API error: " . ($error ?: "HTTP $httpCode"));
        return false;
    }
    
    // Отправляем финальное обновление сообщения
    if (!empty($fullResponse)) {
        try {
            editTelegramMessage($chatId, $loadingMessageId, $fullResponse, ['parse_mode' => 'HTML']);
        } catch (Exception $e) {
            error_log("Error sending final message: " . $e->getMessage());
            return false;
        }
    }
    
    return true;
}

function getAIContext($userId) {
    global $conn;
    
    try {
        debug("Getting AI context for user", "AI_CONTEXT", [
            'userId' => $userId
        ]);
        
        // Сначала получаем chatId пользователя
        $stmtChat = $conn->prepare("SELECT id as chat_id FROM telegram_users WHERE id = ?");
        $stmtChat->execute([$userId]);
        $chatData = $stmtChat->fetch(PDO::FETCH_ASSOC);
        $chatId = $chatData['chat_id'];
        
        // Получаем актуальные данные пользователя
        $stmt = $conn->prepare("
            SELECT 
                u.*,
                c.timezone,
                c.city_key,
                GROUP_CONCAT(DISTINCT ec.category_key) as interests
            FROM telegram_users u 
            LEFT JOIN cities c ON c.id = CAST(REPLACE(u.selected_city, '_', '') AS UNSIGNED)
            LEFT JOIN event_categories ec ON JSON_CONTAINS(u.selected_categories, CAST(ec.id AS JSON), '$')
            WHERE u.id = ?
            GROUP BY u.id
        ");
        
        $stmt->execute([$userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        debug("Retrieved user data for AI context", "AI_CONTEXT", [
            'userData' => $userData
        ]);
        
        if (!$userData) {
            $errorMsg = "❌ Error: No user data found for AI context (ID: $userId)";
            debug($errorMsg, "ERROR");
            sendTelegramMessage($chatId, $errorMsg);
            return null;
        }
        
        // Проверяем наличие обязательных данных пользователя
        $requiredFields = ['language', 'selected_city'];
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (empty($userData[$field])) {
                $missingFields[] = $field;
            }
        }
        
        if (!empty($missingFields)) {
            $errorMsg = "❌ Error: Missing required user data: " . implode(", ", $missingFields);
            debug($errorMsg, "ERROR");
            sendTelegramMessage($chatId, $errorMsg);
            return null;
        }
        
        // Получаем события на ближайшую неделю
        $stmt = $conn->prepare("
            SELECT e.* 
            FROM events e 
            WHERE e.city_id = ? 
            AND e.start_date >= NOW()
            AND e.start_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
            ORDER BY e.start_date ASC
        ");
        
        $cityId = intval(str_replace('_', '', $userData['selected_city']));
        
        debug("Getting events for city", "AI_CONTEXT", [
            'cityId' => $cityId,
            'userId' => $userId
        ]);
        
        $stmt->execute([$cityId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($events)) {
            $errorMsg = "ℹ️ No events found for the next 7 days in your city (City ID: $cityId)";
            debug($errorMsg, "INFO");
            sendTelegramMessage($chatId, $errorMsg);
            return null;
        }
        
        debug("Retrieved events for AI context", "AI_CONTEXT", [
            'eventCount' => count($events)
        ]);
        
        // Формируем контекст
        $context = [
            'user' => [
                'language' => str_replace('_', '', $userData['language']),
                'gender' => $userData['gender'],
                'age_group' => $userData['age_group'],
                'city' => $userData['city_key'],
                'interests' => explode(',', $userData['interests'] ?? '')
            ],
            'events' => array_map(function($event) {
                return [
                    'name' => $event['name'],
                    'description' => $event['description'],
                    'start_date' => $event['start_date'],
                    'venue' => $event['venue_name'],
                    'address' => $event['venue_address'],
                    'price' => [
                        'min' => $event['price_min'],
                        'max' => $event['price_max']
                    ],
                    'genre' => $event['genre'],
                    'type' => $event['event_type'],
                    'ticket_url' => $event['ticket_url']
                ];
            }, $events)
        ];
        
        debug("Created AI context", "AI_CONTEXT", [
            'contextSize' => strlen(json_encode($context)),
            'userPreferences' => $context['user'],
            'eventCount' => count($context['events'])
        ]);
        
        // Обновляем контекст в базе
        $stmt = $conn->prepare("
            UPDATE telegram_users 
            SET ai_context = ?,
                last_ai_context_update = NOW() 
            WHERE id = ?
        ");
        
        $contextJson = json_encode($context);
        $stmt->execute([$contextJson, $userId]);
        
        debug("Updated AI context in database", "AI_CONTEXT", [
            'userId' => $userId,
            'updateTime' => date('Y-m-d H:i:s')
        ]);
        
        // Отправляем информационное сообщение об успешном обновлении контекста
        sendTelegramMessage($chatId, "✅ Context updated successfully!\nEvents found: " . count($events));
        
        return $contextJson;
        
    } catch (Exception $e) {
        $errorMsg = "❌ Error in getAIContext:\n" . 
                   "Message: " . $e->getMessage() . "\n" .
                   "File: " . $e->getFile() . "\n" .
                   "Line: " . $e->getLine();
        
        debug($errorMsg, "ERROR", [
            'trace' => $e->getTraceAsString(),
            'userId' => $userId
        ]);
        
        // Отправляем сообщение об ошибке в Telegram
        if (isset($chatId)) {
            sendTelegramMessage($chatId, $errorMsg);
        }
        return null;
    }
}