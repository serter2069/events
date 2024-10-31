<?php
echo 22;
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
setlocale(LC_ALL, 'ru_RU.UTF-8');

mb_internal_encoding('UTF-8');
header('Content-Type: text/html; charset=utf-8');



include 'db_connection.php';
include 'translations.php';
include 'message_handler.php';

$request_start = microtime(true);
$input = json_decode(file_get_contents('php://input'), true);

function debug($message, $type, $context = []) {
    $datetime = date('Y-m-d H:i:s');
    $log = "\n" . str_repeat("=", 80) . "\n";
    $log .= "[$datetime] [$type]\n";
    
    if (!empty($context)) {
        $log .= "Context:\n";
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $log .= "$key: " . print_r($value, true) . "\n";
            } else {
                $log .= "$key: $value\n";
            }
        }
    }
    
    $log .= "Message: $message\n";
    
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    if (isset($backtrace[0])) {
        $log .= "File: " . $backtrace[0]['file'] . "\n";
        $log .= "Line: " . $backtrace[0]['line'] . "\n";
    }
    
    $log .= str_repeat("-", 80) . "\n";
    file_put_contents('log.txt', $log, FILE_APPEND);
}

function send($data) {
    global $token, $chatId;
    debug("Sending message via send()", "TELEGRAM", ['data' => $data]);
    
    $ch = curl_init("https://api.telegram.org/bot$token/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode(array_merge(['chat_id' => $chatId], $data)),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    debug("Send response", "TELEGRAM", [
        'response' => $response,
        'httpCode' => $httpCode
    ]);
    
    curl_close($ch);
    return $response;
}

function editTelegramMessage($chatId, $messageId, $text, $markup = null) {
    global $token;
    
    debug("Editing message", "TELEGRAM", [
        'chatId' => $chatId,
        'messageId' => $messageId,
        'text' => $text,
        'markup' => $markup
    ]);
    
    try {
        $params = [
            'chat_id' => (string)$chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($markup) {
            $params['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
        }
        
        $ch = curl_init("https://api.telegram.org/bot$token/editMessageText");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8']);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($response === false) {
            throw new Exception('Curl error: ' . curl_error($ch));
        }
        
        $responseData = json_decode($response, true);
        if (!$responseData['ok']) {
            throw new Exception('Telegram API error: ' . ($responseData['description'] ?? 'Unknown error'));
        }
        
        debug("Telegram API response", "TELEGRAM", [
            'response' => $response,
            'httpCode' => $httpCode
        ]);
        
        curl_close($ch);
        return $response;
        
    } catch (Exception $e) {
        debug("Error editing message", "ERROR", [
            'error' => $e->getMessage(),
            'chatId' => $chatId,
            'messageId' => $messageId
        ]);
        return false;
    }
}

function answerCallbackQuery($callback_query_id, $text = '') {
    global $token;
    $data = [
        'callback_query_id' => $callback_query_id,
        'text' => $text
    ];
    
    $ch = curl_init("https://api.telegram.org/bot$token/answerCallbackQuery");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function sendTelegramMessage($chatId, $text, $markup = null) {
    global $token;
    
    debug("=== STARTING TELEGRAM MESSAGE SEND ===", "TELEGRAM_SEND", [
        'chatId' => $chatId,
        'messageLength' => strlen($text),
        'hasMarkup' => !is_null($markup),
        'token_start' => substr($token, 0, 8) . '...',
        'full_text' => $text
    ]);

    // Валидация входных данных
    if (empty($text)) {
        debug("ERROR: Empty message text", "TELEGRAM_ERROR");
        throw new Exception("Message text cannot be empty");
    }

    if (empty($chatId)) {
        debug("ERROR: Empty chat ID", "TELEGRAM_ERROR");
        throw new Exception("Chat ID cannot be empty");
    }

    if (empty($token)) {
        debug("ERROR: Empty bot token", "TELEGRAM_ERROR");
        throw new Exception("Bot token cannot be empty");
    }

    // Формирование параметров запроса
    $params = [
        'chat_id' => (string)$chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($markup) {
        $params['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
    }

    debug("Prepared request parameters", "TELEGRAM_SEND", [
        'params' => $params,
        'endpoint' => "https://api.telegram.org/bot" . substr($token, 0, 8) . "...}/sendMessage"
    ]);

    // Настройка CURL
    $ch = curl_init("https://api.telegram.org/bot$token/sendMessage");
    
    $curlOptions = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8']
    ];
    
    curl_setopt_array($ch, $curlOptions);

    debug("CURL configuration", "TELEGRAM_SEND", [
        'options' => $curlOptions
    ]);

    // Выполнение запроса
    $startTime = microtime(true);
    $response = curl_exec($ch);
    $endTime = microtime(true);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $curlInfo = curl_getinfo($ch);

    debug("CURL execution results", "TELEGRAM_SEND", [
        'execution_time' => round($endTime - $startTime, 4) . ' seconds',
        'http_code' => $httpCode,
        'curl_error' => $error,
        'curl_info' => $curlInfo,
        'response_raw' => $response
    ]);

    if ($response === false) {
        debug("CURL ERROR", "TELEGRAM_ERROR", [
            'error' => $error,
            'curl_info' => $curlInfo,
            'params' => $params
        ]);
        curl_close($ch);
        throw new Exception("Failed to send message: " . $error);
    }

    curl_close($ch);

    // Анализ ответа
    $responseData = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        debug("JSON DECODE ERROR", "TELEGRAM_ERROR", [
            'json_error' => json_last_error_msg(),
            'raw_response' => $response
        ]);
    }

    debug("Final API Response", "TELEGRAM_SEND", [
        'success' => isset($responseData['ok']) ? $responseData['ok'] : false,
        'response_decoded' => $responseData,
        'message_id' => isset($responseData['result']['message_id']) ? $responseData['result']['message_id'] : null
    ]);

    debug("=== FINISHED TELEGRAM MESSAGE SEND ===", "TELEGRAM_SEND");

    return $response;
}

function getCities($conn) {
    debug("Getting cities list", "FUNCTION");
    try {
        $stmt = $conn->query("SELECT id, city_key FROM cities WHERE 1");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        debug("Database error in getCities", "ERROR", ['error' => $e->getMessage()]);
        return [];
    }
}

function getEventCategories($conn) {
    debug("Getting event categories", "FUNCTION");
    try {
        $stmt = $conn->query("SELECT id, category_key FROM event_categories WHERE is_active = 1 ORDER BY sort_order");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        debug("Database error in getEventCategories", "ERROR", ['error' => $e->getMessage()]);
        return [];
    }
}

function getCurrentStep($userData) {
    debug("Getting current step", "FUNCTION", ['userData' => $userData]);
    
    if (!$userData['language']) {
        debug("Step: language", "STEP");
        return 'language';
    }
    if (!$userData['gender']) {
        debug("Step: gender", "STEP");
        return 'gender';
    }
    if (!$userData['age_group']) {
        debug("Step: age", "STEP");
        return 'age';
    }
    if (!$userData['selected_city']) {
        debug("Step: city", "STEP");
        return 'city';
    }
    if (!$userData['selected_categories']) {
        debug("Step: categories", "STEP");
        return 'categories';
    }
    if (!$userData['onboarding_completed']) {
        debug("Step: complete", "STEP");
        return 'complete';
    }
    
    debug("Step: main_menu", "STEP");
    return 'main_menu';
}

function createUser($telegramUserData) {
    global $conn;
    
    debug("Creating/updating user", "USER", ['telegramUserData' => $telegramUserData]);
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO telegram_users (
                id, username, first_name, last_name, 
                language_code, is_bot, created_at, last_interaction
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                username = VALUES(username),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                language_code = VALUES(language_code),
                last_interaction = NOW()
        ");
        
        $stmt->execute([
            $telegramUserData['id'],
            $telegramUserData['username'] ?? '',
            $telegramUserData['first_name'] ?? '',
            $telegramUserData['last_name'] ?? '',
            $telegramUserData['language_code'] ?? 'en',
            $telegramUserData['is_bot'] ? 1 : 0
        ]);
        
        debug("User created/updated", "USER");
        return true;
    } catch (PDOException $e) {
        debug("Error creating user", "ERROR", [
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

function handleScene($scene, $chatId, $userData) {
    global $conn;
    
    debug("Handling scene", "SCENE", [
        'scene' => $scene,
        'chatId' => $chatId,
        'userData' => $userData
    ]);

    try {
        switch($scene) {
            case 'language':
                debug("Processing language scene", "SCENE");
                $markup = [
                    'inline_keyboard' => [
                        [
                            ['text' => 'Русский', 'callback_data' => 'lang_ru'],
                            ['text' => 'English', 'callback_data' => 'lang_en']
                        ]
                    ]
                ];
                sendTelegramMessage($chatId, "Выберите язык / Choose language:", $markup);
                break;

            case 'gender':
                debug("Processing gender scene", "SCENE");
                $markup = [
                    'inline_keyboard' => [
                        [['text' => __('gender_male'), 'callback_data' => 'gender_male']],
                        [['text' => __('gender_female'), 'callback_data' => 'gender_female']]
                    ]
                ];
                sendTelegramMessage($chatId, __('select_gender'), $markup);
                break;

            case 'age':
                debug("Processing age scene", "SCENE");
                $markup = [
                    'inline_keyboard' => [
                        [['text' => '18-24', 'callback_data' => 'age_18_24']],
                        [['text' => '25-34', 'callback_data' => 'age_25_34']],
                        [['text' => '35-44', 'callback_data' => 'age_35_44']],
                        [['text' => '45+', 'callback_data' => 'age_45_plus']]
                    ]
                ];
                sendTelegramMessage($chatId, __('select_age'), $markup);
                break;

            case 'city':
                debug("Processing city scene", "SCENE");
                $cities = getCities($conn);
                $keyboard = [];
                foreach($cities as $city) {
                    $keyboard[] = [[
                        'text' => __('city_' . $city['city_key']),
                        'callback_data' => 'city_' . $city['id']
                    ]];
                }
                $markup = ['inline_keyboard' => $keyboard];
                sendTelegramMessage($chatId, __('select_city_message'), $markup);
                break;

            case 'categories':
                debug("Processing categories scene", "SCENE");
                $categories = getEventCategories($conn);
                $selectedCategories = json_decode($userData['selected_categories'] ?? '[]', true);
                $keyboard = [];
                
                foreach($categories as $category) {
                    $text = __('category_' . $category['category_key']);
                    if (in_array($category['id'], $selectedCategories)) {
                        $text .= ' ✅';
                    }
                    $keyboard[] = [[
                        'text' => $text,
                        'callback_data' => 'cat_' . $category['id']
                    ]];
                }
                
                if (!empty($selectedCategories)) {
                    $keyboard[] = [[
                        'text' => __('save_categories'),
                        'callback_data' => 'save_categories'
                    ]];
                }
                
                $markup = ['inline_keyboard' => $keyboard];
                sendTelegramMessage($chatId, __('select_categories'), $markup);
                break;

            case 'complete':
                debug("Processing complete scene", "SCENE");
                
                $stmt = $conn->prepare("UPDATE telegram_users SET onboarding_completed = 1 WHERE id = ?");
                $stmt->execute([$userData['id']]);
                
                sendTelegramMessage($chatId, __('onboarding_completed'));
                
                handleScene('main_menu', $chatId, $userData);
                break;

                case 'main_menu':
                    debug("Processing main menu scene", "SCENE");
                    
                    debug("Current user language", "SCENE", [
                        'language' => $userData['language']
                    ]);
                    
                    $message = __('main_menu_welcome') . "\n\n";
                    $message .= __('main_menu_instruction') . "\n\n";
                    $message .= __('main_menu_features');
                    
                    debug("Message translations", "SCENE", [
                        'welcome' => __('main_menu_welcome'),
                        'instruction' => __('main_menu_instruction'),
                        'features' => __('main_menu_features'),
                        'final_message' => $message
                    ]);
                    
                    $markup = [
                        'inline_keyboard' => [
                            [['text' => __('weekend_planner'), 'callback_data' => 'weekend_planner']],
                            [['text' => __('settings'), 'callback_data' => 'settings']]
                        ]
                    ];
                    
                    debug("Markup preparation", "SCENE", [
                        'weekend_planner_text' => __('weekend_planner'),
                        'settings_text' => __('settings'),
                        'final_markup' => $markup
                    ]);
                    
                    try {
                        debug("=== Starting message send attempt ===", "SCENE");
                        
                        if (empty($chatId)) {
                            throw new Exception("Chat ID is empty");
                        }
                        
                        debug("Pre-send check", "SCENE", [
                            'chatId' => $chatId,
                            'messageLength' => mb_strlen($message),
                            'hasMarkup' => !empty($markup)
                        ]);
                        
                        $result = sendTelegramMessage($chatId, $message, $markup);
                        
                        debug("Send attempt completed", "SCENE", [
                            'result' => $result
                        ]);
                        
                    } catch (Exception $e) {
                        debug("Error in main menu scene", "ERROR", [
                            'error_message' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                    break;
        }
    } catch (Exception $e) {
        debug("Error in handleScene", "ERROR", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        sendTelegramMessage($chatId, "An error occurred. Please try again later.");
    }
}

debug("Bot started", "INIT");

$update = json_decode(file_get_contents('php://input'), true);
debug("Received update", "TELEGRAM", ['update' => $update]);

$userData = null;
$userId = null;
$chatId = null;

if (isset($update['callback_query'])) {
    $userId = $update['callback_query']['from']['id'];
    $chatId = $update['callback_query']['message']['chat']['id'];
    debug("Callback query detected", "INIT", [
        'userId' => $userId,
        'chatId' => $chatId,
        'data' => $update['callback_query']['data']
    ]);
} elseif (isset($update['message'])) {
    $userId = $update['message']['from']['id'];
    $chatId = $update['message']['chat']['id'];
    debug("Message detected", "INIT", [
        'userId' => $userId,
        'chatId' => $chatId,
        'text' => $update['message']['text'] ?? null
    ]);
}

if ($userId) {
    try {
        $stmt = $conn->prepare("SELECT * FROM telegram_users WHERE id = ?");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch();
        debug("Retrieved user data", "USER", ['userData' => $userData]);
    } catch (PDOException $e) {
        debug("Database error", "ERROR", ['error' => $e->getMessage()]);
        die("Database error");
    }
}

// Обработка callback query
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $data = $callback['data'];
    $onboardingCommands = ['lang_', 'gender_', 'age_', 'city_', 'cat_', 'save_categories'];
    $isOnboardingCommand = false;
    
    foreach ($onboardingCommands as $cmd) {
        if (strpos($data, $cmd) === 0) {
            $isOnboardingCommand = true;
            break;
        }
    }
    
    if (!$isOnboardingCommand && (!$userData || !$userData['onboarding_completed'])) {
        debug("Non-onboarding command while onboarding incomplete", "PROCESS", ['command' => $data]);
        answerCallbackQuery($callback['id']);
        handleScene('language', $chatId, $userData);
        return;
    }

    if (strpos($data, 'lang_') === 0) {
        $lang = substr($data, 5);
        debug("Language selection", "CALLBACK", ['language' => $lang]);
        $stmt = $conn->prepare("UPDATE telegram_users SET language = ? WHERE id = ?");
        $stmt->execute([$lang, $userId]);
        $userData['language'] = $lang;
        handleScene('gender', $chatId, $userData);
    } elseif (strpos($data, 'gender_') === 0) {
        $gender = substr($data, 7);
        debug("Gender selection", "CALLBACK", ['gender' => $gender]);
        $stmt = $conn->prepare("UPDATE telegram_users SET gender = ? WHERE id = ?");
        $stmt->execute([$gender, $userId]);
        $userData['gender'] = $gender;
        handleScene('age', $chatId, $userData);
    } elseif (strpos($data, 'age_') === 0) {
        $age = str_replace('age_', '', $data);
        debug("Age selection", "CALLBACK", ['age' => $age]);
        $stmt = $conn->prepare("UPDATE telegram_users SET age_group = ? WHERE id = ?");
        $stmt->execute([$age, $userId]);
        $userData['age_group'] = $age;
        handleScene('city', $chatId, $userData);
    } elseif (strpos($data, 'city_') === 0) {
        $cityId = substr($data, 5);
        debug("City selection", "CALLBACK", ['city_id' => $cityId]);
        $stmt = $conn->prepare("UPDATE telegram_users SET selected_city = ? WHERE id = ?");
        $stmt->execute([$cityId, $userId]);
        $userData['selected_city'] = $cityId;
        handleScene('categories', $chatId, $userData);
    } elseif (strpos($data, 'cat_') === 0) {
        $categoryId = substr($data, 4);
        $messageId = $callback['message']['message_id'];
        debug("Category selection", "CALLBACK", ['category_id' => $categoryId, 'message_id' => $messageId]);
        $selectedCategories = json_decode($userData['selected_categories'] ?? '[]', true);
        if (in_array($categoryId, $selectedCategories)) {
            $selectedCategories = array_diff($selectedCategories, [$categoryId]);
            debug("Category removed", "CALLBACK", ['removed_category' => $categoryId]);
        } else {
            $selectedCategories[] = $categoryId;
            debug("Category added", "CALLBACK", ['added_category' => $categoryId]);
        }
        $categoriesJson = json_encode($selectedCategories);
        $stmt = $conn->prepare("UPDATE telegram_users SET selected_categories = ? WHERE id = ?");
        $stmt->execute([$categoriesJson, $userId]);
        $userData['selected_categories'] = $categoriesJson;
        $categories = getEventCategories($conn);
        $keyboard = [];
        foreach($categories as $category) {
            $text = __('category_' . $category['category_key']);
            if (in_array($category['id'], $selectedCategories)) {
                $text .= ' ✅';
            }
            $keyboard[] = [[
                'text' => $text,
                'callback_data' => 'cat_' . $category['id']
            ]];
        }
        if (!empty($selectedCategories)) {
            $keyboard[] = [[
                'text' => __('save_categories'),
                'callback_data' => 'save_categories'
            ]];
        }
        $markup = ['inline_keyboard' => $keyboard];
        editTelegramMessage($chatId, $messageId, __('select_categories'), $markup);
    } elseif ($data === 'save_categories') {
        debug("Saving categories", "CALLBACK");
        $selectedCategories = json_decode($userData['selected_categories'] ?? '[]', true);
        if (empty($selectedCategories)) {
            debug("No categories selected", "ERROR");
            sendTelegramMessage($chatId, __('min_one_category_error'));
        } else {
            debug("Categories saved successfully", "CALLBACK", ['categories' => $selectedCategories]);
            handleScene('complete', $chatId, $userData);
        }
    } elseif ($data === 'settings') {
        debug("Opening settings", "CALLBACK");
        $markup = [
            'inline_keyboard' => [
                [['text' => __('change_language'), 'callback_data' => 'settings_language']],
                [['text' => __('change_gender'), 'callback_data' => 'settings_gender']],
                [['text' => __('change_age'), 'callback_data' => 'settings_age']],
                [['text' => __('change_city'), 'callback_data' => 'settings_city']],
                [['text' => __('change_categories'), 'callback_data' => 'settings_categories']],
                [['text' => __('back_to_main'), 'callback_data' => 'back_to_main']]
            ]
        ];
        sendTelegramMessage($chatId, __('settings_message'), $markup);
    } elseif ($data === 'settings_language' || strpos($data, 'settings_lang_') === 0) {
        if (strpos($data, 'settings_lang_') === 0) {
            $lang = substr($data, 13);
            debug("Changing language in settings", "CALLBACK", ['new_language' => $lang]);
            $stmt = $conn->prepare("UPDATE telegram_users SET language = ? WHERE id = ?");
            $stmt->execute([$lang, $userId]);
            $userData['language'] = $lang;
            $markup = [
                'inline_keyboard' => [
                    [['text' => __('change_language'), 'callback_data' => 'settings_language']],
                    [['text' => __('change_gender'), 'callback_data' => 'settings_gender']],
                    [['text' => __('change_age'), 'callback_data' => 'settings_age']],
                    [['text' => __('change_city'), 'callback_data' => 'settings_city']],
                    [['text' => __('change_categories'), 'callback_data' => 'settings_categories']],
                    [['text' => __('back_to_main'), 'callback_data' => 'back_to_main']]
                ]
            ];
            sendTelegramMessage($chatId, __('settings_message'), $markup);
        } else {
            debug("Language settings", "CALLBACK");
            $markup = [
                'inline_keyboard' => [
                    [
                        ['text' => 'Русский', 'callback_data' => 'settings_lang_ru'],
                        ['text' => 'English', 'callback_data' => 'settings_lang_en']
                    ],
                    [['text' => __('back_to_main'), 'callback_data' => 'settings']]
                ]
            ];
            sendTelegramMessage($chatId, __('select_language'), $markup);
        }
    } elseif ($data === 'settings_gender' || strpos($data, 'settings_gender_') === 0) {
        if (strpos($data, 'settings_gender_') === 0) {
            $gender = substr($data, 15);
            debug("Changing gender in settings", "CALLBACK", ['new_gender' => $gender]);
            $stmt = $conn->prepare("UPDATE telegram_users SET gender = ? WHERE id = ?");
            $stmt->execute([$gender, $userId]);
            $userData['gender'] = $gender;
            $markup = [
                'inline_keyboard' => [
                    [['text' => __('change_language'), 'callback_data' => 'settings_language']],
                    [['text' => __('change_gender'), 'callback_data' => 'settings_gender']],
                    [['text' => __('change_age'), 'callback_data' => 'settings_age']],
                    [['text' => __('change_city'), 'callback_data' => 'settings_city']],
                    [['text' => __('change_categories'), 'callback_data' => 'settings_categories']],
                    [['text' => __('back_to_main'), 'callback_data' => 'back_to_main']]
                ]
            ];
            sendTelegramMessage($chatId, __('settings_message'), $markup);
        } else {
            debug("Gender settings", "CALLBACK");
            $markup = [
                'inline_keyboard' => [
                    [
                        ['text' => __('gender_male'), 'callback_data' => 'settings_gender_male'],
                        ['text' => __('gender_female'), 'callback_data' => 'settings_gender_female']
                    ],
                    [['text' => __('back_to_main'), 'callback_data' => 'settings']]
                ]
            ];
            sendTelegramMessage($chatId, __('select_gender'), $markup);
        }
    } elseif ($data === 'settings_age' || strpos($data, 'settings_age_') === 0) {
        if (strpos($data, 'settings_age_') === 0) {
            $age = substr($data, 12);
            debug("Changing age in settings", "CALLBACK", ['new_age' => $age]);
            $stmt = $conn->prepare("UPDATE telegram_users SET age_group = ? WHERE id = ?");
            $stmt->execute([$age, $userId]);
            $userData['age_group'] = $age;
            $markup = [
                'inline_keyboard' => [
                    [['text' => __('change_language'), 'callback_data' => 'settings_language']],
                    [['text' => __('change_gender'), 'callback_data' => 'settings_gender']],
                    [['text' => __('change_age'), 'callback_data' => 'settings_age']],
                    [['text' => __('change_city'), 'callback_data' => 'settings_city']],
                    [['text' => __('change_categories'), 'callback_data' => 'settings_categories']],
                    [['text' => __('back_to_main'), 'callback_data' => 'back_to_main']]
                ]
            ];
            sendTelegramMessage($chatId, __('settings_message'), $markup);
        } else {
            debug("Age settings", "CALLBACK");
            $markup = [
                'inline_keyboard' => [
                    [['text' => '18-24', 'callback_data' => 'settings_age_18_24']],
                    [['text' => '25-34', 'callback_data' => 'settings_age_25_34']],
                    [['text' => '35-44', 'callback_data' => 'settings_age_35_44']],
                    [['text' => '45+', 'callback_data' => 'settings_age_45_plus']],
                    [['text' => __('back_to_main'), 'callback_data' => 'settings']]
                ]
            ];
            sendTelegramMessage($chatId, __('select_age'), $markup);
        }
    } elseif ($data === 'settings_city' || strpos($data, 'settings_city_') === 0) {
        if (strpos($data, 'settings_city_') === 0) {
            $cityId = substr($data, 13);
            debug("Changing city in settings", "CALLBACK", ['new_city' => $cityId]);
            $stmt = $conn->prepare("UPDATE telegram_users SET selected_city = ? WHERE id = ?");
            $stmt->execute([$cityId, $userId]);
            $userData['selected_city'] = $cityId;
            $markup = [
                'inline_keyboard' => [
                    [['text' => __('change_language'), 'callback_data' => 'settings_language']],
                    [['text' => __('change_gender'), 'callback_data' => 'settings_gender']],
                    [['text' => __('change_age'), 'callback_data' => 'settings_age']],
                    [['text' => __('change_city'), 'callback_data' => 'settings_city']],
                    [['text' => __('change_categories'), 'callback_data' => 'settings_categories']],
                    [['text' => __('back_to_main'), 'callback_data' => 'back_to_main']]
                ]
            ];
            sendTelegramMessage($chatId, __('settings_message'), $markup);
        } else {
            debug("City settings", "CALLBACK");
            $cities = getCities($conn);
            $keyboard = [];
            foreach($cities as $city) {
                $keyboard[] = [[
                    'text' => __('city_' . $city['city_key']),
                    'callback_data' => 'settings_city_' . $city['id']
                ]];
            }
            $keyboard[] = [[
                'text' => __('back_to_main'),
                'callback_data' => 'settings'
            ]];
            $markup = ['inline_keyboard' => $keyboard];
            sendTelegramMessage($chatId, __('select_city_message'), $markup);
        }
    } elseif ($data === 'settings_categories') {
        debug("Categories settings", "CALLBACK");
        handleScene('categories', $chatId, $userData);
    } elseif ($data === 'back_to_main') {
        debug("Back to main menu", "CALLBACK");
        handleScene('main_menu', $chatId, $userData);
    } elseif ($data === 'weekend_planner') {
        debug("Weekend planner", "CALLBACK");
        $context = getAIContext($userId);
        if ($context) {
            processUserMessage($userId, $chatId, "Подскажи какие мероприятия будут на этих выходных?", $context);
        } else {
            sendTelegramMessage($chatId, __('error_processing_message'));
        }
    }
}

// Обработка обычных сообщений
if (isset($update['message'])) {
    debug("Processing message", "MESSAGE", ['message' => $update['message']]);
    
    try {
        $text = $update['message']['text'] ?? '';
        
        if ($text === '/start') {
            debug("Start command received", "COMMAND");
            createUser($update['message']['from']);
            
            $stmt = $conn->prepare("SELECT * FROM telegram_users WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            
            debug("User data after creation", "USER", ['userData' => $userData]);

            if (!$userData['onboarding_completed']) {
                debug("Onboarding not completed, starting from first step", "PROCESS");
                $stmt = $conn->prepare("
                    UPDATE telegram_users 
                    SET language = NULL,
                        gender = NULL,
                        age_group = NULL,
                        selected_city = NULL,
                        selected_categories = NULL,
                        onboarding_completed = 0 
                    WHERE id = ?
                ");
                $stmt->execute([$userId]);
                
                $stmt = $conn->prepare("SELECT * FROM telegram_users WHERE id = ?");
                $stmt->execute([$userId]);
                $userData = $stmt->fetch();
                
                handleScene('language', $chatId, $userData);
            } else {
                debug("Onboarding completed, showing main menu", "PROCESS");
                handleScene('main_menu', $chatId, $userData);
            }
        } else {
            // Проверяем статус онбординга и обрабатываем текстовые сообщения
            if (!$userData || !$userData['onboarding_completed']) {
                debug("Processing message for incomplete user", "MESSAGE");
                $currentStep = getCurrentStep($userData);
                handleScene($currentStep, $chatId, $userData);
            } else {
                // Обработка текстового сообщения через AI
                debug("Processing message through AI", "MESSAGE");
                $context = getAIContext($userId);
                
                if ($context) {
                    processUserMessage($userId, $chatId, $text, $context);
                } else {
                    debug("Failed to get AI context", "ERROR", [
                        'userId' => $userId,
                        'chatId' => $chatId
                    ]);
                    
                    // Пробуем получить контекст еще раз после небольшой задержки
                    sleep(1);
                    $context = getAIContext($userId);
                    
                    if ($context) {
                        processUserMessage($userId, $chatId, $text, $context);
                    } else {
                        // Если контекст все равно не получен, отправляем сообщение об ошибке
                        sendTelegramMessage($chatId, __('error_processing_message'));
                        // Можно добавить в translations.php:
                        // 'error_processing_message' => 'Извините, произошла ошибка при обработке сообщения. Пожалуйста, попробуйте позже.'
                    }
                }
            }
        }
    } catch (Exception $e) {
        debug("Error in message processing", "ERROR", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        sendTelegramMessage($chatId, __('error_processing_message'));
    }
}

// Обновляем время последнего взаимодействия
if ($userId) {
    try {
        $stmt = $conn->prepare("UPDATE telegram_users SET last_interaction = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        debug("Error updating last interaction", "ERROR", ['error' => $e->getMessage()]);
    }
}

debug("Bot finished processing request", "FINISH", [
    'execution_time' => microtime(true) - $request_start
]);