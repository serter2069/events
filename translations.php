<?php
function getCurrentLanguage() {
    global $userData;
    
    debug("Getting current language", "TRANSLATE", [
        'userData' => $userData,
        'default' => 'ru'
    ]);
    
    if (isset($userData['language'])) {
        return trim(str_replace('_', '', $userData['language']));
    }
    return 'ru';
  }

$availableLanguages = array (
  0 => 'ru',
  1 => 'en',
);




$translations = [
  'ru' => [
      // Города
      'city_san_francisco' => 'Сан-Франциско',
      'city_new_york' => 'Нью-Йорк',
      'city_moscow' => 'Москва',
      'category_cinema_theater' => 'Кино и театр',
      'category_concerts_music' => 'Концерты и шоу',
      'category_food_drinks' => 'Еда и напитки', 
      'category_active_recreation' => 'Активный отдых',
      'category_education_workshops' => 'Образование и мастер-классы',
      'category_social_parties' => 'Вечеринки и общение',
      'category_art_exhibitions' => 'Искусство и выставки',
      'save_categories' => 'Сохранить выбор',
      'select_categories' => 'Выберите интересующие вас категории (можно несколько):',
      'min_one_category_error' => 'Пожалуйста, выберите хотя бы одну категорию',

      'settings' => 'Настройки',
      'settings_message' => 'Настройки профиля:',
      'change_language' => 'Изменить язык',
      'change_city' => 'Изменить город',
      'change_categories' => 'Изменить категории',
      'back_to_main' => 'Вернуться в главное меню',
      
      // Категории событий
      'category_culture' => 'Культура',
      'category_sports' => 'Спорт',
      'category_education' => 'Образование',
      'category_music' => 'Музыка',
      'category_food' => 'Еда и напитки',
      'category_networking' => 'Нетворкинг',
      'category_tech' => 'Технологии',
      'category_art' => 'Искусство',
      'category_outdoor' => 'Активный отдых',
      'category_business' => 'Бизнес',
      'category_health' => 'Здоровье',
      'category_science' => 'Наука',
      'category_family' => 'Семья и дети',
      'category_pets' => 'Питомцы',
      'category_charity' => 'Благотворительность',
      
      // Гендер
      'select_gender' => 'Выберите ваш пол:',
      'gender_male' => 'Мужской',
      'gender_female' => 'Женский',
      
      // Возраст
      'select_age' => 'Выберите вашу возрастную группу:',
      
      // Основное меню
      'main_menu_welcome' => 'Добро пожаловать в главное меню!',
      'main_menu_instruction' => 'Здесь вы можете искать события и управлять настройками.',
      'main_menu_features' => 'Доступные функции:',
      'weekend_planner' => 'Планировщик выходных',
      'weekend_planner_message' => 'Функция планировщика выходных находится в разработке.',
      
      // Настройки
      'settings_message' => 'Настройки профиля:',
      'change_language' => 'Изменить язык',
      'change_gender' => 'Изменить пол',
      'change_age' => 'Изменить возраст',
      'change_city' => 'Изменить город',
      'change_categories' => 'Изменить категории',
      'back_to_main' => 'Вернуться в главное меню',
      
      // Категории
      'select_categories' => 'Выберите интересующие вас категории (можно несколько):',
      'save_categories' => 'Сохранить категории',
      'categories_saved' => 'Категории успешно сохранены!',
      'min_one_category_error' => 'Необходимо выбрать хотя бы одну категорию',
      
      // Общие сообщения
      'welcome_message' => 'Привет! Я помогу вам найти интересные события.',
      'select_language' => 'Выберите язык:',
      'select_city_message' => 'В каком городе вы ищете мероприятия?',
      'processing_request' => 'Обрабатываю ваш запрос...',
      'onboarding_completed' => 'Отлично! Настройка завершена. Теперь вы можете искать события!'
  ],
  
  'en' => [
      // Cities
      'city_san_francisco' => 'San Francisco',
      'city_new_york' => 'New York',
      'city_moscow' => 'Moscow',


      // Event categories
      'category_cinema_theater' => 'Cinema & Theater',
      'category_concerts_music' => 'Concerts & Shows',
      'category_food_drinks' => 'Food & Drinks',
      'category_active_recreation' => 'Active Recreation',
      'category_education_workshops' => 'Education & Workshops',
      'category_social_parties' => 'Social Events & Parties',
      'category_art_exhibitions' => 'Art & Exhibitions',
      
      // Category buttons and messages
      'save_categories' => 'Save Selection',
      'select_categories' => 'Select categories of interest (multiple choice):',
      'min_one_category_error' => 'Please select at least one category',
      
      // Event categories
      'category_culture' => 'Culture',
      'category_sports' => 'Sports',
      'category_education' => 'Education',
      'category_music' => 'Music',
      'category_food' => 'Food & Drinks',
      'category_networking' => 'Networking',
      'category_tech' => 'Tech',
      'category_art' => 'Art',
      'category_outdoor' => 'Outdoor Activities',
      'category_business' => 'Business',
      'category_health' => 'Health',
      'category_science' => 'Science',
      'category_family' => 'Family & Kids',
      'category_pets' => 'Pets',
      'category_charity' => 'Charity',
      
      // Gender
      'select_gender' => 'Select your gender:',
      'gender_male' => 'Male',
      'gender_female' => 'Female',
      
      // Age
      'select_age' => 'Select your age group:',
      
      // Main menu
      'main_menu_welcome' => 'Welcome to the main menu!',
      'main_menu_instruction' => 'Here you can search for events and manage your settings.',
      'main_menu_features' => 'Available features:',
      'weekend_planner' => 'Weekend Planner',
      'weekend_planner_message' => 'Weekend planner feature is under development.',
      
      // Settings
      'settings_message' => 'Profile Settings:',
      'change_language' => 'Change Language',
      'change_gender' => 'Change Gender',
      'change_age' => 'Change Age',
      'change_city' => 'Change City',
      'change_categories' => 'Change Categories',
      'back_to_main' => 'Back to Main Menu',

      'settings' => 'Settings',
      'settings_message' => 'Profile Settings:',
      'change_language' => 'Change Language',
      'change_city' => 'Change City',
      'change_categories' => 'Change Categories',
      'back_to_main' => 'Back to Main Menu',
      
      // Categories
      'select_categories' => 'Select categories of interest (multiple choice):',
      'save_categories' => 'Save Categories',
      'categories_saved' => 'Categories successfully saved!',
      'min_one_category_error' => 'You must select at least one category',
      
      // General messages
      'welcome_message' => 'Hi! I\'ll help you find interesting events.',
      'select_language' => 'Select language:',
      'select_city_message' => 'In which city are you looking for events?',
      'processing_request' => 'Processing your request...',
      'onboarding_completed' => 'Great! Setup completed. Now you can search for events!'
  ]
];

var_dump($translations);


function __($key, $params = []) {
    global $translations;
    $lang = getCurrentLanguage();
    $lowerKey = strtolower($key);
    if (isset($translations[$lang][$lowerKey])) {
        $translation = $translations[$lang][$lowerKey];
        foreach ($params as $param => $value) {
            $translation = str_replace("%$param%", $value, $translation);
        }
        return $translation;
    }
    return $key;
}
