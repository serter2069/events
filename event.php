<?php
require_once 'db_connection.php';

function logMessage($type, $message, $data = []) {
    $datetime = date('Y-m-d H:i:s');
    $logEntry = "[{$datetime}] [{$type}] {$message}\n";
    
    if (!empty($data)) {
        $logEntry .= "Data: " . print_r($data, true) . "\n";
    }
    
    $logEntry .= str_repeat("-", 80) . "\n";
    file_put_contents('event_processor.log', $logEntry, FILE_APPEND);
}

function isSoldOut($event) {
    // Проверяем название
    if (stripos($event['name'], 'sold out') !== false) {
        logMessage("FILTER", "Event filtered - SOLD OUT in name", [
            'event_id' => $event['id'],
            'name' => $event['name']
        ]);
        return true;
    }
    
    // Проверяем описание
    if (isset($event['description']) && stripos($event['description'], 'sold out') !== false) {
        logMessage("FILTER", "Event filtered - SOLD OUT in description", [
            'event_id' => $event['id'],
            'name' => $event['name']
        ]);
        return true;
    }

    // Проверяем статус доступности
    if (isset($event['dates']['status']['code'])) {
        $status = strtolower($event['dates']['status']['code']);
        if ($status === 'offsale' || $status === 'cancelled' || $status === 'postponed') {
            logMessage("FILTER", "Event filtered - Status is {$status}", [
                'event_id' => $event['id'],
                'name' => $event['name']
            ]);
            return true;
        }
    }

    // Проверяем доступность билетов
    if (isset($event['accessibility']) && 
        isset($event['accessibility']['ticketing']) && 
        stripos($event['accessibility']['ticketing'], 'sold out') !== false) {
        logMessage("FILTER", "Event filtered - SOLD OUT in accessibility", [
            'event_id' => $event['id'],
            'name' => $event['name']
        ]);
        return true;
    }
    
    return false;
}

function getCityIdBySearchName($conn, $cityName) {
    try {
        $stmt = $conn->prepare("SELECT id FROM cities WHERE LOWER(cyty_name_for_event_search) = LOWER(?)");
        $stmt->execute([$cityName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['id'] : null;
    } catch (Exception $e) {
        logMessage("ERROR", "Error getting city ID: " . $e->getMessage());
        return null;
    }
}

function saveEvent($conn, $event) {
    try {
        // Проверяем на SOLD OUT
        if (isSoldOut($event)) {
            // Если событие уже есть в базе, удаляем его
            $stmt = $conn->prepare("DELETE FROM events WHERE event_id = ?");
            $stmt->execute([$event['id']]);
            if ($stmt->rowCount() > 0) {
                logMessage("DELETE", "Removed SOLD OUT event from database", [
                    'event_id' => $event['id'],
                    'name' => $event['name']
                ]);
            }
            return ['status' => 'filtered_soldout'];
        }

        // Подготовка данных
        $eventId = $event['id'];
        $name = $event['name'];
        $description = isset($event['description']) ? $event['description'] : 
                      (isset($event['info']) ? $event['info'] : null);
        
        // Получаем город из события
        $cityName = null;
        $cityId = null;
        if (isset($event['_embedded']['venues'][0]['city']['name'])) {
            $cityName = $event['_embedded']['venues'][0]['city']['name'];
            $cityId = getCityIdBySearchName($conn, $cityName);
            
            if (!$cityId) {
                logMessage("WARNING", "City not found in database", [
                    'city_name' => $cityName,
                    'event_name' => $name
                ]);
            }
        }
        
        // Получаем изображение
        $image = null;
        if (!empty($event['images'])) {
            foreach ($event['images'] as $img) {
                if ($img['width'] >= 200 && $img['width'] <= 400) {
                    $image = $img['url'];
                    break;
                }
            }
            if (empty($image) && !empty($event['images'][0]['url'])) {
                $image = $event['images'][0]['url'];
            }
        }
        
        // Даты
        $startDate = isset($event['dates']['start']['dateTime']) ? 
                    new DateTime($event['dates']['start']['dateTime']) : null;
        $endDate = isset($event['dates']['end']['dateTime']) ? 
                  new DateTime($event['dates']['end']['dateTime']) : $startDate;
        
        // Место проведения
        $venue = $event['_embedded']['venues'][0];
        $venueName = $venue['name'];
        $venueAddress = isset($venue['address']['line1']) ? $venue['address']['line1'] : null;
        if (isset($venue['city']['name']) && isset($venue['state']['stateCode'])) {
            $venueAddress .= ", " . $venue['city']['name'] . ", " . $venue['state']['stateCode'];
        }
        
        // Цены
        $priceMin = null;
        $priceMax = null;
        if (isset($event['priceRanges'])) {
            foreach ($event['priceRanges'] as $price) {
                $priceMin = $price['min'];
                $priceMax = $price['max'];
                break;
            }
        }
        
        // Дополнительная информация
        $genre = isset($event['classifications'][0]['genre']['name']) ? 
                $event['classifications'][0]['genre']['name'] : null;
        $eventType = isset($event['classifications'][0]['segment']['name']) ? 
                    $event['classifications'][0]['segment']['name'] : null;
        $saleStatus = isset($event['dates']['status']['code']) ? 
                     $event['dates']['status']['code'] : null;
        $ticketUrl = $event['url'] ?? null;

        // Проверяем существование записи
        $stmt = $conn->prepare("SELECT id FROM events WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Обновляем существующую запись
            $sql = "UPDATE events SET 
                    name = ?, description = ?, image_url = ?, 
                    start_date = ?, end_date = ?, venue_name = ?,
                    venue_address = ?, price_min = ?, price_max = ?,
                    ticket_url = ?, genre = ?, event_type = ?,
                    sale_status = ?, city_id = ?
                    WHERE event_id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $name, $description, $image,
                $startDate ? $startDate->format('Y-m-d H:i:s') : null,
                $endDate ? $endDate->format('Y-m-d H:i:s') : null,
                $venueName, $venueAddress, $priceMin, $priceMax,
                $ticketUrl, $genre, $eventType, $saleStatus, $cityId,
                $eventId
            ]);
            
            logMessage("UPDATE", "Updated event: {$eventId}", [
                'name' => $name,
                'venue' => $venueName,
                'city_id' => $cityId,
                'start_date' => $startDate ? $startDate->format('Y-m-d H:i:s') : null
            ]);
            
            return ['status' => 'updated', 'id' => $existing['id']];
        } else {
            // Создаем новую запись
            $sql = "INSERT INTO events (
                    event_id, name, description, image_url,
                    start_date, end_date, venue_name, venue_address,
                    price_min, price_max, ticket_url, genre,
                    event_type, sale_status, city_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $eventId, $name, $description, $image,
                $startDate ? $startDate->format('Y-m-d H:i:s') : null,
                $endDate ? $endDate->format('Y-m-d H:i:s') : null,
                $venueName, $venueAddress, $priceMin, $priceMax,
                $ticketUrl, $genre, $eventType, $saleStatus, $cityId
            ]);
            
            logMessage("INSERT", "Created new event: {$eventId}", [
                'name' => $name,
                'venue' => $venueName,
                'city_id' => $cityId,
                'start_date' => $startDate ? $startDate->format('Y-m-d H:i:s') : null
            ]);
            
            return ['status' => 'inserted', 'id' => $conn->lastInsertId()];
        }
    } catch (Exception $e) {
        logMessage("ERROR", "Error saving event {$eventId}: " . $e->getMessage(), [
            'name' => $name ?? 'unknown',
            'trace' => $e->getTraceAsString()
        ]);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// Основной процесс
try {
    logMessage("START", "Starting event processing");
    
    // Получаем список городов
    $stmt = $conn->query("SELECT * FROM cities WHERE cyty_name_for_event_search IS NOT NULL");
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($cities)) {
        throw new Exception("No cities found in database");
    }
    
    foreach ($cities as $city) {
        logMessage("PROCESS", "Processing city: " . $city['city_key'], [
            'city_id' => $city['id'],
            'search_name' => $city['cyty_name_for_event_search']
        ]);
        
        // API ключ
        $apiKey = 'Vb9FJd1rSeIylFj9VcnNedMnK7PaceAP';

        // Даты
        $startDateTime = date('Y-m-d') . 'T00:00:00Z';
        $endDateTime = date('Y-m-d', strtotime('+1 week')) . 'T23:59:59Z';

        // URL запроса для конкретного города
        $url = "https://app.ticketmaster.com/discovery/v2/events.json?"
            . "city=" . urlencode($city['cyty_name_for_event_search'])
            . "&startDateTime=" . urlencode($startDateTime)
            . "&endDateTime=" . urlencode($endDateTime)
            . "&sort=date,asc"
            . "&size=200"
            . "&includeTBA=no"
            . "&includeTBD=no"
            . "&status=onsale"
            . "&availability=PRIMARY"
            . "&apikey=" . $apiKey;

        logMessage("API", "Sending request to Ticketmaster API", [
            'city' => $city['city_key'],
            'url' => $url
        ]);

        // Выполняем запрос
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');

        $response = curl_exec($ch);

        if ($response === false) {
            throw new Exception("cURL Error for city {$city['city_key']}: " . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        logMessage("API", "Received response from API for city: " . $city['city_key'], [
            'http_code' => $httpCode,
            'response_length' => strlen($response)
        ]);

        // Декодируем JSON ответ
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error for city {$city['city_key']}: " . json_last_error_msg());
        }

        $stats = [
            'city' => $city['city_key'],
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'filtered_soldout' => 0,
            'errors' => 0
        ];

        if (isset($data['_embedded']['events'])) {
            $totalEvents = count($data['_embedded']['events']);
            logMessage("PROCESS", "Starting to process {$totalEvents} events for city: " . $city['city_key']);

            foreach ($data['_embedded']['events'] as $event) {
                $stats['processed']++;
                $result = saveEvent($conn, $event);
                
                switch($result['status']) {
                    case 'inserted':
                        $stats['inserted']++;
                        break;
                    case 'updated':
                        $stats['updated']++;
                        break;
                    case 'filtered_soldout':
                        $stats['filtered_soldout']++;
                        break;
                    case 'error':
                        $stats['errors']++;
                        break;
                }
            }
        } else {
            logMessage("INFO", "No events found in API response for city: " . $city['city_key']);
        }

        logMessage("FINISH", "Event processing completed for city: " . $city['city_key'], [
            'stats' => $stats
        ]);
    }

} catch (Exception $e) {
    logMessage("ERROR", "Fatal error in main process: " . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
    ]);
}