<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

include 'db_connection.php';

if (!extension_loaded('zip')) {
   die('ZipArchive не доступен');
}

function getDirectoryFiles($dir) {
   $files = [];
   foreach (new DirectoryIterator($dir) as $file) {
       if ($file->isFile() && in_array($file->getExtension(), ['php', 'js', 'yaml'])) {
           $files[] = [
               'name' => $file->getFilename(),
               'size' => $file->getSize()
           ];
       }
   }
   usort($files, function($a, $b) {
       return strcmp($a['name'], $b['name']);
   });
   return $files;
}

function formatFileSize($bytes) {
   $units = ['B', 'KB', 'MB', 'GB'];
   $i = 0;
   while ($bytes >= 1024 && $i < count($units) - 1) {
       $bytes /= 1024;
       $i++;
   }
   return round($bytes, 2) . ' ' . $units[$i];
}

function getSelectedFiles($conn) {
   $stmt = $conn->query("SELECT filename FROM selected_files WHERE is_selected = 1");
   return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function updateSelectedFiles($conn, $selectedFiles) {
   try {
       $conn->beginTransaction();
       
       $stmt = $conn->prepare("UPDATE selected_files SET is_selected = 0");
       $stmt->execute();
       
       if (!empty($selectedFiles)) {
           $stmt = $conn->prepare("INSERT INTO selected_files (filename, is_selected) 
                               VALUES (:filename, 1) 
                               ON DUPLICATE KEY UPDATE is_selected = 1");
           
           foreach ($selectedFiles as $file) {
               $stmt->execute(['filename' => $file]);
           }
       }
       
       $conn->commit();
   } catch (PDOException $e) {
       $conn->rollBack();
       error_log("Error updating files: " . $e->getMessage());
   }
}

function getDatabaseStructure($pdo) {
   $structure = "";

   $tables = getDatabaseTables($pdo);
   $structure .= "Список таблиц в базе данных:\n";
   foreach ($tables as $table) {
       $structure .= "Таблица: $table\n";
       $columns = getTableColumns($pdo, $table);
       foreach ($columns as $column) {
           $structure .= "  Колонка: " . $column['Field'] . " - " . $column['Type'] . "\n";
       }
       $structure .= "\n";
   }

   $structure .= "\nСвязи между таблицами (Внешние ключи):\n";
   foreach ($tables as $table) {
       $structure .= "Таблица: $table\n";
       $foreignKeys = getTableForeignKeys($pdo, $table);
       foreach ($foreignKeys as $fk) {
           $structure .= "  Колонка: " . $fk['COLUMN_NAME'] . " -> " . 
                        $fk['REFERENCED_TABLE_NAME'] . "(" . $fk['REFERENCED_COLUMN_NAME'] . ")\n";
       }
       $structure .= "\n";
   }

   $structure .= "\nПримеры данных из таблиц:\n";
   foreach ($tables as $table) {
       $structure .= "Таблица: $table (Примеры записей)\n";
       $sampleData = getTableSampleData($pdo, $table);
       foreach ($sampleData as $row) {
           $structure .= json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
       }
       $structure .= "\n";
   }

   return $structure;
}

function getDatabaseTables($pdo) {
   $stmt = $pdo->query("SHOW TABLES");
   return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getTableColumns($pdo, $table) {
   $stmt = $pdo->prepare("SHOW COLUMNS FROM " . $table);
   $stmt->execute();
   return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTableForeignKeys($pdo, $table) {
   $stmt = $pdo->prepare("
       SELECT 
           kcu.COLUMN_NAME, 
           kcu.REFERENCED_TABLE_NAME, 
           kcu.REFERENCED_COLUMN_NAME 
       FROM 
           INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
       WHERE 
           kcu.TABLE_SCHEMA = DATABASE() AND 
           kcu.TABLE_NAME = :table AND 
           kcu.REFERENCED_TABLE_NAME IS NOT NULL
   ");
   $stmt->execute(['table' => $table]);
   return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getTableSampleData($pdo, $table) {
    $stmt = $pdo->prepare("SELECT * FROM " . $table . " LIMIT 3");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Обрезаем все строковые значения до 50 символов
    return array_map(function($row) {
        return array_map(function($value) {
            if (is_string($value) && mb_strlen($value) > 50) {
                return mb_substr($value, 0, 47) . '...';
            }
            return $value;
        }, $row);
    }, $data);
 }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   if (isset($_POST['select_files'])) {
       $selectedFiles = $_POST['files'] ?? [];
       updateSelectedFiles($conn, $selectedFiles);
   } elseif (isset($_POST['create_archive'])) {
       $enteredPassword = $_POST['password'] ?? '';
       $correctPassword = 'Orelkosyak5';

       if ($enteredPassword === $correctPassword) {
           $selectedFiles = getSelectedFiles($conn);
           $zip = new ZipArchive();
           $zipFileName = 'archive_' . uniqid() . '.zip';
           $tempFile = tempnam(sys_get_temp_dir(), 'zip');
           
           if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
               foreach ($selectedFiles as $file) {
                   if (file_exists($file)) {
                       $zip->addFile($file);
                   }
               }
               
               $dbStructure = getDatabaseStructure($conn);
               $zip->addFromString('database_structure.txt', $dbStructure);
               
               $zip->close();

               if (file_exists($tempFile) && filesize($tempFile) > 0) {
                   header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
                   header("Cache-Control: post-check=0, pre-check=0", false);
                   header("Pragma: no-cache");
                   header('Content-Type: application/zip');
                   header('Content-Disposition: attachment; filename="' . basename($zipFileName) . '"');
                   header('Content-Length: ' . filesize($tempFile));
                   header('Content-Transfer-Encoding: binary');
                   if (ob_get_length()) ob_clean();
                   flush();
                   readfile($tempFile);
                   unlink($tempFile);
                   exit;
               } else {
                   echo "Ошибка при создании архива.";
               }
           } else {
               echo "Не удалось создать архив.";
           }
       } else {
           echo 'Неверный пароль. Пожалуйста, попробуйте снова.';
       }
   }
}

$currentDir = __DIR__;
$allFiles = getDirectoryFiles($currentDir);
$selectedFiles = getSelectedFiles($conn);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
   <meta http-equiv="Pragma" content="no-cache">
   <meta http-equiv="Expires" content="0">
   <title>Управление архивом</title>
   <style>
       body { font-family: Arial, sans-serif; line-height: 1.4; padding: 20px; }
       h2 { color: #333; margin-top: 20px; }
       form { margin-bottom: 20px; }
       label { display: inline-block; margin-bottom: 3px; }
       input[type="password"] { margin-bottom: 10px; }
       button { padding: 5px 10px; }
       .file-list { column-count: 3; column-gap: 20px; }
       .file-size { color: #666; font-size: 0.9em; margin-left: 5px; }
   </style>
</head>
<body>
   <h2>Выберите файлы для архивации</h2>
   <form method="post">
       <label>
           <input type="checkbox" id="select-all"> Выбрать все / Снять выделение со всех
       </label><br><br>
       <div class="file-list">
           <?php foreach ($allFiles as $file): ?>
               <label>
                   <input type="checkbox" name="files[]" value="<?php echo htmlspecialchars($file['name']); ?>"
                          <?php echo in_array($file['name'], $selectedFiles) ? 'checked' : ''; ?>
                          class="file-checkbox">
                   <?php echo htmlspecialchars($file['name']); ?>
                   <span class="file-size">(<?php echo formatFileSize($file['size']); ?>)</span>
               </label><br>
           <?php endforeach; ?>
       </div>
       <button type="submit" name="select_files">Сохранить выбор</button>
   </form>

   <h2>Создать архив</h2>
   <form method="post">
       <label for="password">Введите пароль для создания архива:</label>
       <input type="password" id="password" name="password" required>
       <button type="submit" name="create_archive">Создать и скачать архив</button>
   </form>

   <script>
       document.addEventListener('DOMContentLoaded', function() {
           const selectAllCheckbox = document.getElementById('select-all');
           const fileCheckboxes = document.querySelectorAll('.file-checkbox');

           selectAllCheckbox.addEventListener('change', function() {
               fileCheckboxes.forEach(checkbox => {
                   checkbox.checked = selectAllCheckbox.checked;
               });
           });

           fileCheckboxes.forEach(checkbox => {
               checkbox.addEventListener('change', function() {
                   selectAllCheckbox.checked = Array.from(fileCheckboxes).every(cb => cb.checked);
               });
           });
       });
   </script>
</body>
</html>